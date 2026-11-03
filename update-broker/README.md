# FSM FAQ Update Broker (Cloudflare Worker)

Serves Plugin Update Checker metadata and the plugin zip so client sites never need a GitHub token.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/fsm-faq.json` | PUC metadata |
| GET | `/fsm-faq.zip` | Plugin zip download |
| POST | `/admin/sync` | Upload a packaged zip (`Authorization: Bearer SYNC_SECRET`, header `X-Plugin-Version`) |
| POST | `/admin/sync-from-github` | Pull latest/tag release from GitHub (needs `GITHUB_TOKEN` when private) |
| GET | `/health` | Liveness |

Production URL (after DNS): `https://updates.fullspectrummarketing.com/fsm-faq.json`

## Deploy (FSM Cloudflare account)

KV namespace **fsm-faq-update-broker** is already created on the FSM account:

- id: `b40e905330c047dd920002daa7c411f8` (wired in `wrangler.jsonc`)

R2 is optional and not enabled on the account yet; zips are stored in KV.

```bash
cd update-broker
npm install
export CLOUDFLARE_API_TOKEN=…   # or: npx wrangler login
npx wrangler deploy
npx wrangler secret put SYNC_SECRET
# Optional, for private-repo GitHub pulls:
npx wrangler secret put GITHUB_TOKEN
```

Scope `GITHUB_TOKEN` to read-only Contents access on `FSM-FAQ-Plugin` only (fine-grained PAT or GitHub App). Sync tags must be versions like `1.1.0` / `v1.1.0`; anything else is rejected.

Map custom domain `updates.fullspectrummarketing.com` to this Worker in the Cloudflare dashboard.

Set GitHub Actions secrets on this repo:

- `FSM_FAQ_BROKER_URL` — e.g. `https://updates.fullspectrummarketing.com`
- `FSM_FAQ_BROKER_SYNC_SECRET` — same value as Worker `SYNC_SECRET`

Full cutover (bridge release → soak → privatize): see [CUTOVER.md](CUTOVER.md).

## Manual sync

```bash
chmod +x scripts/package-plugin.sh
BROKER_URL=https://updates.fullspectrummarketing.com \
SYNC_SECRET=… \
node scripts/sync-release.mjs 1.1.0
```

## Temporary preview (agents without account tokens)

```bash
npx wrangler kv namespace create STORE --temporary
# paste id into wrangler.jsonc, then:
npx wrangler deploy --temporary --var SYNC_SECRET:local-dev-secret
```

Claim within 60 minutes if you want to keep that preview account. Prefer deploying to the real FSM account with `CLOUDFLARE_API_TOKEN` instead.
