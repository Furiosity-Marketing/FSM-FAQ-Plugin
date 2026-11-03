#!/usr/bin/env node
/**
 * Package the plugin and POST the zip to the update broker.
 *
 * Usage:
 *   BROKER_URL=https://… SYNC_SECRET=… node scripts/sync-release.mjs [version]
 */
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const brokerRoot = join(__dirname, '..');
const brokerUrl = (process.env.BROKER_URL || '').replace(/\/+$/, '');
const secret = process.env.SYNC_SECRET || '';
const versionArg = process.argv[2] || '';

if (!brokerUrl) {
	console.error('BROKER_URL is required');
	process.exit(1);
}
if (!secret) {
	console.error('SYNC_SECRET is required');
	process.exit(1);
}

const packed = spawnSync('bash', [join(__dirname, 'package-plugin.sh'), join(brokerRoot, 'dist'), versionArg], {
	encoding: 'utf8',
});
if (packed.status !== 0) {
	console.error(packed.stderr || packed.stdout);
	process.exit(packed.status || 1);
}
const zipPath = packed.stdout.trim().split('\n').pop();
const version =
	versionArg.replace(/^v/i, '') ||
	zipPath.match(/fsm-faq-(.+)\.zip$/)?.[1] ||
	'';

const zip = readFileSync(zipPath);
const res = await fetch(`${brokerUrl}/admin/sync/fsm-faq`, {
	method: 'POST',
	headers: {
		Authorization: `Bearer ${secret}`,
		'X-Plugin-Version': version,
		'Content-Type': 'application/zip',
	},
	body: zip,
});

const text = await res.text();
if (!res.ok) {
	console.error(text);
	process.exit(1);
}
console.log(text);
