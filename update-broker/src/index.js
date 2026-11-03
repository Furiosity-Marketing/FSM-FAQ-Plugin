/**
 * FSM FAQ update broker — Plugin Update Checker metadata + zip delivery.
 *
 * Client sites never talk to GitHub. Ops syncs a release zip into KV via
 * POST /admin/sync (Authorization: Bearer <SYNC_SECRET>).
 *
 * Optional: POST /admin/sync-from-github pulls the latest (or tagged) release
 * zipball from GitHub using GITHUB_TOKEN when the repo is private.
 */

const META_KEY = 'fsm-faq:metadata';
const ZIP_KEY = 'fsm-faq:zip';
const VERSION_KEY = 'fsm-faq:version';
const VERSION_RE = /^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/;

export default {
	async fetch(request, env, ctx) {
		const url = new URL(request.url);
		const path = url.pathname.replace(/\/+$/, '') || '/';

		try {
			if (request.method === 'GET' && (path === '/' || path === '/health')) {
				return json({ ok: true, service: 'fsm-faq-update-broker' });
			}

			if (request.method === 'GET' && path === '/fsm-faq.json') {
				return serveMetadata(env, url);
			}

			if (request.method === 'GET' && (path === '/fsm-faq.zip' || path === '/download/fsm-faq.zip')) {
				return serveZip(env);
			}

			if (request.method === 'POST' && path === '/admin/sync') {
				return adminSync(request, env, url);
			}

			if (request.method === 'POST' && path === '/admin/sync-from-github') {
				return adminSyncFromGitHub(request, env, url);
			}

			return json({ error: 'Not found' }, 404);
		} catch (err) {
			return json({ error: err.message || String(err) }, 500);
		}
	},
};

async function serveMetadata(env, requestUrl) {
	const raw = await env.STORE.get(META_KEY);
	if (!raw) {
		return json({ error: 'No release published yet. Run /admin/sync first.' }, 404);
	}

	let meta;
	try {
		meta = JSON.parse(raw);
	} catch {
		return json({ error: 'Corrupt metadata' }, 500);
	}

	const base = publicBase(env, requestUrl);
	meta.download_url = `${base}/fsm-faq.zip`;

	return new Response(JSON.stringify(meta, null, 2), {
		headers: {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'public, max-age=300',
		},
	});
}

async function serveZip(env) {
	const obj = await env.STORE.get(ZIP_KEY, { type: 'arrayBuffer' });
	if (!obj) {
		return json({ error: 'Zip not found. Run /admin/sync first.' }, 404);
	}

	const version = (await env.STORE.get(VERSION_KEY)) || 'latest';

	return new Response(obj, {
		headers: {
			'Content-Type': 'application/zip',
			'Content-Disposition': `attachment; filename="fsm-faq-${version}.zip"`,
			'Cache-Control': 'public, max-age=300',
		},
	});
}

async function adminSync(request, env, requestUrl) {
	const authErr = requireSyncAuth(request, env);
	if (authErr) {
		return authErr;
	}

	const version = (request.headers.get('X-Plugin-Version') || '').replace(/^v/i, '').trim();
	if (!VERSION_RE.test(version)) {
		return json({ error: 'Missing or invalid X-Plugin-Version header (e.g. 1.1.0)' }, 400);
	}

	const zip = await request.arrayBuffer();
	if (!zip || zip.byteLength < 100) {
		return json({ error: 'Request body must be the plugin zip binary' }, 400);
	}

	await persistRelease(env, requestUrl, version, zip, {
		description: request.headers.get('X-Plugin-Description') || 'FSM FAQ plugin update.',
	});

	return json({
		ok: true,
		version,
		metadata_url: `${publicBase(env, requestUrl)}/fsm-faq.json`,
		download_url: `${publicBase(env, requestUrl)}/fsm-faq.zip`,
		bytes: zip.byteLength,
	});
}

async function adminSyncFromGitHub(request, env, requestUrl) {
	const authErr = requireSyncAuth(request, env);
	if (authErr) {
		return authErr;
	}

	let body = {};
	const ct = request.headers.get('Content-Type') || '';
	if (ct.includes('application/json')) {
		body = await request.json().catch(() => ({}));
	}

	const owner = env.GITHUB_OWNER || 'Furiosity-Marketing';
	const repo = env.GITHUB_REPO || 'FSM-FAQ-Plugin';
	const tag = String(body.tag || request.headers.get('X-GitHub-Tag') || '').replace(/^v/i, '').trim();
	if (tag && !VERSION_RE.test(tag)) {
		return json({ error: 'Invalid tag; expected a version such as 1.1.0 or v1.1.0' }, 400);
	}

	const repoApi = `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repo)}`;

	const headers = {
		Accept: 'application/vnd.github+json',
		'User-Agent': 'fsm-faq-update-broker',
	};
	if (env.GITHUB_TOKEN) {
		headers.Authorization = `Bearer ${env.GITHUB_TOKEN}`;
	}

	let release;
	if (tag) {
		const res = await fetch(
			`${repoApi}/releases/tags/${encodeURIComponent(`v${tag}`)}`,
			{ headers }
		);
		if (!res.ok) {
			const alt = await fetch(
				`${repoApi}/releases/tags/${encodeURIComponent(tag)}`,
				{ headers }
			);
			if (!alt.ok) {
				return json({ error: `GitHub release not found for tag ${tag}`, status: alt.status }, 502);
			}
			release = await alt.json();
		} else {
			release = await res.json();
		}
	} else {
		const res = await fetch(`${repoApi}/releases/latest`, {
			headers,
		});
		if (!res.ok) {
			return json({ error: `GitHub latest release failed`, status: res.status }, 502);
		}
		release = await res.json();
	}

	const releaseTag = typeof release.tag_name === 'string' ? release.tag_name : '';
	const version = releaseTag.replace(/^v/i, '');
	if (!VERSION_RE.test(version) || (tag && version !== tag)) {
		return json({ error: 'GitHub release tag does not match the requested version' }, 502);
	}

	const assetPrefix = `${repoApi}/releases/assets/`;

	// Prefer an uploaded release asset named like fsm-faq*.zip; else zipball.
	let zipUrl = null;
	if (Array.isArray(release.assets)) {
		const zips = release.assets.filter(
			(a) => a && typeof a.name === 'string' && a.name.endsWith('.zip')
				&& typeof a.url === 'string' && a.url.startsWith(assetPrefix)
		);
		const asset = zips.find((a) => a.name.toLowerCase().includes('fsm-faq')) || zips[0];
		if (asset) {
			zipUrl = asset.url; // API asset URL needs Accept: application/octet-stream
		}
	}

	let zip;
	if (zipUrl) {
		const assetHeaders = { ...headers, Accept: 'application/octet-stream' };
		const zres = await fetch(zipUrl, { headers: assetHeaders, redirect: 'follow' });
		if (!zres.ok) {
			return json({ error: `Failed to download release asset`, status: zres.status }, 502);
		}
		zip = await zres.arrayBuffer();
	} else {
		const zres = await fetch(
			`${repoApi}/zipball/${encodeURIComponent(releaseTag)}`,
			{ headers: { ...headers, Accept: 'application/vnd.github+json' }, redirect: 'follow' }
		);
		if (!zres.ok) {
			return json({ error: `Failed to download zipball`, status: zres.status }, 502);
		}
		zip = await zres.arrayBuffer();
	}

	await persistRelease(env, requestUrl, version, zip, {
		description: release.body || release.name || 'FSM FAQ plugin update.',
	});

	return json({
		ok: true,
		version,
		source: zipUrl ? 'release-asset' : 'zipball',
		metadata_url: `${publicBase(env, requestUrl)}/fsm-faq.json`,
		download_url: `${publicBase(env, requestUrl)}/fsm-faq.zip`,
		bytes: zip.byteLength,
	});
}

async function persistRelease(env, requestUrl, version, zip, extras = {}) {
	const base = publicBase(env, requestUrl);
	const meta = {
		name: 'FSM FAQ',
		version,
		download_url: `${base}/fsm-faq.zip`,
		homepage: 'https://fsm.agency',
		requires: '5.9',
		tested: '6.4',
		requires_php: '8.0',
		last_updated: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00'),
		sections: {
			description: extras.description || 'Custom FAQ post type with ACF fields and shortcode.',
		},
	};

	await env.STORE.put(ZIP_KEY, zip);
	await env.STORE.put(VERSION_KEY, version);
	await env.STORE.put(META_KEY, JSON.stringify(meta));
}

function requireSyncAuth(request, env) {
	const secret = env.SYNC_SECRET;
	if (!secret) {
		return json({
			error: 'SYNC_SECRET is not configured on the Worker. Set it with: wrangler secret put SYNC_SECRET',
		}, 503);
	}

	const header = request.headers.get('Authorization') || '';
	const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
	const alt = request.headers.get('X-Sync-Secret') || '';

	if (token !== secret && alt !== secret) {
		return json({ error: 'Unauthorized' }, 401);
	}
	return null;
}

function publicBase(env, requestUrl) {
	if (env.PUBLIC_BASE_URL) {
		return env.PUBLIC_BASE_URL.replace(/\/+$/, '');
	}
	return requestUrl.origin;
}

function json(data, status = 200) {
	return new Response(JSON.stringify(data, null, 2), {
		status,
		headers: { 'Content-Type': 'application/json; charset=utf-8' },
	});
}
