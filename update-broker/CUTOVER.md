# Cutover & privatize checklist

Follow in order. **Do not make the GitHub repo private until step 4.**

## 1. Deploy broker (Furiosity Cloudflare account)

Prerequisites:

- Cloudflare account authenticated (MCP bindings can create KV; Wrangler needs deploy auth).
- KV namespace `fsm-faq-update-broker` id `b40e905330c047dd920002daa7c411f8` (already created).
- R2 optional (not required; zips stored in KV). Enable R2 in the dashboard later if you prefer object storage.

```bash
cd update-broker
npm install
# Prefer API token in CI/agents:
#   export CLOUDFLARE_API_TOKEN=…   # Workers Scripts:Edit, Workers KV Storage:Edit, Account Settings:Read
# Or interactive:
#   npx wrangler login
npx wrangler deploy
npx wrangler secret put SYNC_SECRET
# Optional, for /admin/sync-from-github against a private repo:
npx wrangler secret put GITHUB_TOKEN
```

Attach custom domain `updates.furiositymarketing.com` to the Worker (Workers → Custom Domains), or set `routes` in `wrangler.jsonc`.

Repo secrets for Actions:

- `FSM_FAQ_BROKER_URL` = `https://updates.furiositymarketing.com`
- `FSM_FAQ_BROKER_SYNC_SECRET` = same as Worker `SYNC_SECRET`

## 2. Seed metadata (before or with bridge release)

```bash
BROKER_URL=https://updates.furiositymarketing.com \
SYNC_SECRET=… \
node scripts/sync-release.mjs 1.1.0
```

Verify:

```bash
curl -fsS https://updates.furiositymarketing.com/fsm-faq.json
curl -fsS -o /tmp/fsm-faq.zip https://updates.furiositymarketing.com/fsm-faq.zip
unzip -l /tmp/fsm-faq.zip | head
```

Disable Bot Fight / challenge on this hostname if WordPress update checks get HTML challenges instead of JSON.

## 3. Bridge release (repo still public)

- Merge plugin 1.1.0 (PUC → broker).
- Publish GitHub Release `v1.1.0` while the repo is **public** so existing sites still update via GitHub PUC.
- Confirm Actions synced the zip to the broker (or run manual sync).

## 4. Soak

On known client sites:

- Plugins screen shows **1.1.0** (or newer).
- “Check for updates” does not error; next release appears from the broker.

Laggards: one-time manual install of the 1.1.0 zip.

## 5. Privatize GitHub (only after soak)

```bash
gh repo edit Furiosity-Marketing/FSM-FAQ-Plugin --visibility private
# Confirm prompts carefully.
```

Or: GitHub → Settings → Danger Zone → Change visibility → Private.

Sites still on &lt; 1.1.0 will stop receiving GitHub updates until manually upgraded once.

## 6. Steady state

Bump version → GitHub Release → broker sync → client one-click update. No per-site GitHub tokens.
