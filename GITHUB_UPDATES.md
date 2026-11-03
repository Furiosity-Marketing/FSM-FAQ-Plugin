# GitHub-based / broker updates for Furiosity FAQ

This plugin uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). **Production installs talk to the Furiosity Cloudflare update broker**, not GitHub. That keeps the GitHub repo private while sites still get one-click updates.

## Architecture

```
GitHub Release (private) → broker sync (CI or manual) → Cloudflare Worker
                                                          ↓
                                              Client WordPress (PUC)
```

- Default metadata URL: `https://updates.furiositymarketing.com/fsm-faq.json`
- Zip download: `https://updates.furiositymarketing.com/fsm-faq.zip`
- Broker code and ops: [`update-broker/`](update-broker/)

## Client sites (production)

No `wp-config.php` defines required when using the default broker URL.

Optional override of the metadata URL:

```php
define( 'FSM_FAQ_UPDATE_URL', 'https://updates.furiositymarketing.com/fsm-faq.json' );
```

**Do not** put `FSM_FAQ_GITHUB_TOKEN` on client sites.

## Agency / internal GitHub override

Only for Furiosity-controlled environments that must hit GitHub directly:

```php
define( 'FSM_FAQ_GITHUB_REPO', 'https://github.com/Furiosity-Marketing/FSM-FAQ-Plugin/' );
define( 'FSM_FAQ_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx' ); // never commit
```

Both must be set; then PUC uses GitHub instead of the broker.

## Zero-break cutover (do not privatize first)

1. **Broker live** — Deploy `update-broker` on the Furiosity Cloudflare account; sync at least one release; verify JSON + zip. Repo stays **public**.
2. **Bridge release** — Ship plugin **1.1.0+** (this version points PUC at the broker) as a normal **public** GitHub Release so existing sites auto-update via the old GitHub flow.
3. **Soak** — Confirm key installs show 1.1.0+ and “Check for updates” succeeds against the broker.
4. **Privatize** — Set the GitHub repo to private. Sites on 1.1.0+ are unaffected.
5. **Steady state** — Every later release: bump Version → GitHub Release → workflow/`sync-release` uploads zip to broker → sites update from Cloudflare.

See [`update-broker/CUTOVER.md`](update-broker/CUTOVER.md) for the privatize checklist.

## Releasing an update (after cutover)

1. Bump **Version** in `fsm-faq.php` and **Stable tag** in `readme.txt`.
2. Commit, tag, and publish a GitHub Release (e.g. `v1.1.1`).
3. Ensure GitHub Actions secrets `FSM_FAQ_BROKER_URL` and `FSM_FAQ_BROKER_SYNC_SECRET` are set so [`.github/workflows/sync-update-broker.yml`](.github/workflows/sync-update-broker.yml) pushes the zip to the broker.
4. Or sync manually:

```bash
cd update-broker
BROKER_URL=https://updates.furiositymarketing.com \
SYNC_SECRET=… \
node scripts/sync-release.mjs 1.1.1
```

Sites see the update within ~12 hours, or immediately after “Check for updates”.

## Flow summary

| Audience | Update source | Secrets on site |
|----------|---------------|-----------------|
| Client installs | Cloudflare broker | None |
| FSM internal override | Private GitHub | `FSM_FAQ_GITHUB_TOKEN` |
| Broker itself | GitHub App/PAT or CI upload | Worker `SYNC_SECRET` / `GITHUB_TOKEN` |
