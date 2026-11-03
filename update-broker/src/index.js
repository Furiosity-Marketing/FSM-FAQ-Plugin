/**
 * Furiosity update broker — Plugin Update Checker metadata + zip delivery
 * for every plugin listed in ./plugins.js.
 *
 * Client sites never talk to GitHub. CI syncs a release zip into KV via
 * POST /admin/sync/<slug> (Authorization: Bearer <that plugin's sync secret>).
 *
 * Optional: POST /admin/sync-from-github/<slug> pulls the latest (or tagged)
 * release from GitHub using GITHUB_TOKEN when the repo is private.
 */

import { PLUGINS, DEFAULT_SLUG } from './plugins.js';

const SERVICE = 'fsm-faq-update-broker';
const SLUG_RE = '([a-z0-9][a-z0-9-]*)';
const META_RE = new RegExp(`^/${SLUG_RE}\\.json$`);
const ZIP_RE = new RegExp(`^(?:/download)?/${SLUG_RE}\\.zip$`);
const VERSION_RE = /^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/;
const ADMIN_RE = new RegExp(`^/admin/(sync|sync-from-github)(?:/${SLUG_RE})?$`);

const keys = (slug) => ({
	meta: `${slug}:metadata`,
	zip: `${slug}:zip`,
	version: `${slug}:version`,
});

export default {
	async fetch(request, env) {
		const url = new URL(request.url);
		const path = url.pathname.replace(/\/+$/, '') || '/';

		try {
			if (request.method === 'GET' && (path === '/' || path === '/health')) {
				return json({ ok: true, service: SERVICE, plugins: Object.keys(PLUGINS) });
			}

			let m;
			if (request.method === 'GET' && (m = path.match(META_RE)) && PLUGINS[m[1]]) {
				return serveMetadata(env, url, m[1]);
			}

			if (request.method === 'GET' && (m = path.match(ZIP_RE)) && PLUGINS[m[1]]) {
				return serveZip(env, m[1]);
			}

			if (request.method === 'POST' && (m = path.match(ADMIN_RE))) {
				const slug = m[2] || request.headers.get('X-Plugin-Slug') || DEFAULT_SLUG;
				if (!PLUGINS[slug]) {
					return json({ error: `Unknown plugin slug: ${slug}` }, 404);
				}
				const authErr = requireSyncAuth(request, env, slug);
				if (authErr) {
					return authErr;
				}
				return m[1] === 'sync'
					? adminSync(request, env, url, slug)
					: adminSyncFromGitHub(request, env, url, slug);
			}

			return json({ error: 'Not found' }, 404);
		} catch (err) {
			return json({ error: err.message || String(err) }, 500);
		}
	},
};

async function serveMetadata(env, requestUrl, slug) {
	const raw = await env.STORE.get(keys(slug).meta);
	if (!raw) {
		return json({ error: 'No release published yet. Run /admin/sync first.' }, 404);
	}

	let meta;
	try {
		meta = JSON.parse(raw);
	} catch {
		return json({ error: 'Corrupt metadata' }, 500);
	}

	meta.download_url = `${publicBase(env, requestUrl)}/${slug}.zip`;

	return new Response(JSON.stringify(meta, null, 2), {
		headers: {
			'Content-Type': 'application/json; charset=utf-8',
			'Cache-Control': 'public, max-age=300',
		},
	});
}

async function serveZip(env, slug) {
	const k = keys(slug);
	const obj = await env.STORE.get(k.zip, { type: 'arrayBuffer' });
	if (!obj) {
		return json({ error: 'Zip not found. Run /admin/sync first.' }, 404);
	}

	const version = (await env.STORE.get(k.version)) || 'latest';

	return new Response(obj, {
		headers: {
			'Content-Type': 'application/zip',
			'Content-Disposition': `attachment; filename="${slug}-${version}.zip"`,
			'Cache-Control': 'public, max-age=300',
		},
	});
}

async function adminSync(request, env, requestUrl, slug) {
	const version = (request.headers.get('X-Plugin-Version') || '').replace(/^v/i, '').trim();
	if (!VERSION_RE.test(version)) {
		return json({ error: 'Missing or invalid X-Plugin-Version header (e.g. 1.1.0)' }, 400);
	}

	const zip = await request.arrayBuffer();
	if (!zip || zip.byteLength < 100) {
		return json({ error: 'Request body must be the plugin zip binary' }, 400);
	}

	await persistRelease(env, slug, version, zip, {
		description: request.headers.get('X-Plugin-Description'),
		requires: request.headers.get('X-Plugin-Requires'),
		tested: request.headers.get('X-Plugin-Tested'),
		requires_php: request.headers.get('X-Plugin-Requires-PHP'),
	});

	return json(syncResult(env, requestUrl, slug, version, zip));
}

async function adminSyncFromGitHub(request, env, requestUrl, slug) {
	let body = {};
	const ct = request.headers.get('Content-Type') || '';
	if (ct.includes('application/json')) {
		body = await request.json().catch(() => ({}));
	}

	const [owner, repo] = PLUGINS[slug].github.split('/');
	const tag = String(body.tag || request.headers.get('X-GitHub-Tag') || '').replace(/^v/i, '').trim();
	if (tag && !VERSION_RE.test(tag)) {
		return json({ error: 'Invalid tag; expected a version such as 1.1.0 or v1.1.0' }, 400);
	}

	const repoApi = `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repo)}`;

	const headers = {
		Accept: 'application/vnd.github+json',
		'User-Agent': SERVICE,
	};
	if (env.GITHUB_TOKEN) {
		headers.Authorization = `Bearer ${env.GITHUB_TOKEN}`;
	}

	let release;
	if (tag) {
		const res = await fetch(`${repoApi}/releases/tags/${encodeURIComponent(`v${tag}`)}`, { headers });
		if (!res.ok) {
			const alt = await fetch(`${repoApi}/releases/tags/${encodeURIComponent(tag)}`, { headers });
			if (!alt.ok) {
				return json({ error: `GitHub release not found for tag ${tag}`, status: alt.status }, 502);
			}
			release = await alt.json();
		} else {
			release = await res.json();
		}
	} else {
		const res = await fetch(`${repoApi}/releases/latest`, { headers });
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

	// Prefer an uploaded release asset named like <slug>*.zip; else zipball.
	let zipUrl = null;
	if (Array.isArray(release.assets)) {
		const zips = release.assets.filter(
			(a) => a && typeof a.name === 'string' && a.name.endsWith('.zip')
				&& typeof a.url === 'string' && a.url.startsWith(assetPrefix)
		);
		const asset = zips.find((a) => a.name.toLowerCase().includes(slug)) || zips[0];
		if (asset) {
			zipUrl = asset.url; // API asset URL needs Accept: application/octet-stream
		}
	}

	let zip;
	if (zipUrl) {
		const zres = await fetch(zipUrl, { headers: { ...headers, Accept: 'application/octet-stream' }, redirect: 'follow' });
		if (!zres.ok) {
			return json({ error: `Failed to download release asset`, status: zres.status }, 502);
		}
		zip = await zres.arrayBuffer();
	} else {
		const zres = await fetch(`${repoApi}/zipball/${encodeURIComponent(releaseTag)}`, { headers, redirect: 'follow' });
		if (!zres.ok) {
			return json({ error: `Failed to download zipball`, status: zres.status }, 502);
		}
		zip = await zres.arrayBuffer();
	}

	await persistRelease(env, slug, version, zip, {
		description: release.body || release.name,
	});

	return json({ ...syncResult(env, requestUrl, slug, version, zip), source: zipUrl ? 'release-asset' : 'zipball' });
}

async function persistRelease(env, slug, version, zip, overrides = {}) {
	const plugin = PLUGINS[slug];
	const meta = {
		name: plugin.name,
		slug,
		version,
		homepage: plugin.homepage,
		requires: overrides.requires || plugin.requires,
		tested: overrides.tested || plugin.tested,
		requires_php: overrides.requires_php || plugin.requires_php,
		last_updated: new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00'),
		sections: {
			description: overrides.description || plugin.description,
		},
	};

	const k = keys(slug);
	await env.STORE.put(k.zip, zip);
	await env.STORE.put(k.version, version);
	await env.STORE.put(k.meta, JSON.stringify(meta));
}

function syncResult(env, requestUrl, slug, version, zip) {
	const base = publicBase(env, requestUrl);
	return {
		ok: true,
		slug,
		version,
		metadata_url: `${base}/${slug}.json`,
		download_url: `${base}/${slug}.zip`,
		bytes: zip.byteLength,
	};
}

function requireSyncAuth(request, env, slug) {
	const secretName = PLUGINS[slug].secret;
	const secret = env[secretName];
	if (!secret) {
		return json({
			error: `${secretName} is not configured on the Worker. Set it with: wrangler secret put ${secretName}`,
		}, 503);
	}

	const header = request.headers.get('Authorization') || '';
	const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
	const alt = request.headers.get('X-Sync-Secret') || '';

	if (!timingSafeEqual(token, secret) && !timingSafeEqual(alt, secret)) {
		return json({ error: 'Unauthorized' }, 401);
	}
	return null;
}

function timingSafeEqual(a, b) {
	const enc = new TextEncoder();
	const x = enc.encode(a);
	const y = enc.encode(b);
	if (x.byteLength !== y.byteLength) {
		return false;
	}
	return crypto.subtle.timingSafeEqual(x, y);
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
