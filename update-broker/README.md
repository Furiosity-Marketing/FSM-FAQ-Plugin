# Furiosity Update Broker (Cloudflare Worker)

Serves Plugin Update Checker metadata and plugin zips so client sites never need a GitHub token. One Worker serves every plugin listed in [`src/plugins.js`](src/plugins.js).

| Slug | Repo | Sync secret (Worker) |
|------|------|----------------------|
| `fsm-faq` | Furiosity-Marketing/FSM-FAQ-Plugin | `SYNC_SECRET` |
| `click-id-utm-capture` | Furiosity-Marketing/click-id-utm-capture | `SYNC_SECRET_CLICK_ID_UTM_CAPTURE` |

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/<slug>.json` | PUC metadata |
| GET | `/<slug>.zip` | Plugin zip download |
| POST | `/admin/sync/<slug>` | Upload a packaged zip (`Authorization: Bearer <slug's sync secret>`, header `X-Plugin-Version`; optional `X-Plugin-Requires`, `X-Plugin-Tested`, `X-Plugin-Requires-PHP`) |
| POST | `/admin/sync-from-github/<slug>` | Pull latest/tag release from GitHub (needs `GITHUB_TOKEN` when private) |
| GET | `/health` | Liveness + served slugs |

`/admin/sync` and `/admin/sync-from-github` without a slug still mean `fsm-faq` (legacy CI).

Production URL: `https://updates.furiositymarketing.com/<slug>.json`. The legacy hostname `updates.fullspectrummarketing.com` is still attached because FSM FAQ <= 1.1.9 has it hard-coded; remove that route once every FAQ install is on 1.1.10+.

## Adding a plugin

1. Add an entry to `src/plugins.js` (slug = plugin folder name, `secret` = a new Worker secret name).
2. Deploy, then `npx wrangler secret put <SECRET_NAME>`.
3. In the plugin repo, point PUC at `https://updates.furiositymarketing.com/<slug>.json` and add a CI step that POSTs the zip to `/admin/sync/<slug>`.

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

Scope `GITHUB_TOKEN` to read-only Contents access on the plugin repos only (fine-grained PAT or GitHub App). Sync tags must be versions like `1.1.0` / `v1.1.0`; anything else is rejected.

Custom domains are declared in `wrangler.jsonc` `routes`.

Set GitHub Actions secrets on this repo:

- `FSM_FAQ_BROKER_URL` — e.g. `https://updates.furiositymarketing.com`
- `FSM_FAQ_BROKER_SYNC_SECRET` — same value as Worker `SYNC_SECRET`

Full cutover (bridge release → soak → privatize): see [CUTOVER.md](CUTOVER.md).

## Manual sync

```bash
chmod +x scripts/package-plugin.sh
BROKER_URL=https://updates.furiositymarketing.com \
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
