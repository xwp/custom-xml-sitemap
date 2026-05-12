# custom-xml-sitemap e2e suite — handoff

Playwright end-to-end suite for the `custom-xml-sitemap` plugin. Runs against
the plugin's `wp-env` tests instance (port 8889), seeded from a committed SQL
fixture so every run starts from a deterministic baseline.

26 tests across 7 spec files. Latest run: 26/26 green in ~4.5m, idempotent
across consecutive runs.

## Quick start

```sh
# From the plugin root.
pnpm install --ignore-workspace          # parent monorepo's pnpm-workspace.yaml excludes plugins/
pnpm test:e2e:install                    # one-off: download Chromium
pnpm env:start                           # boots wp-env (dev :8888 + tests :8889)
pnpm test:e2e                            # run the full suite
pnpm test:e2e:ui                         # interactive runner
```

`global-setup` automatically loads `tests/e2e/fixtures/seed.sql.gz` into the
tests DB before each run, so you don't need to manually reset state between
runs. It's idempotent.

If wp-env's tests container drifts (rare), nuke and restart:

```sh
pnpm env:destroy && pnpm env:start
```

## Run flow

1. `pnpm test:e2e` invokes Playwright with `tests/e2e/playwright.config.ts`.
2. **`globalSetup`** (`helpers/global-setup.ts`):
   - `loadSeedFixture()` — gunzips `seed.sql.gz` on the host (Node zlib),
     drops the `.sql` into bind-mounted `.tmp/`, runs `wp db import` inside
     the tests-cli container, restores pretty permalinks. ~6.5s.
   - `resetBaselineState()` — re-activates the plugin (tests env doesn't
     auto-activate), wipes any leftover `cxs_sitemap` CPTs, cancels pending
     Action Scheduler jobs in the `cxs-sitemap` group, and deletes any
     `e2e-`-prefixed terms/posts (NOT `fx-`-prefixed fixture rows). ~12s.
   - Logs in as admin and persists storage state to `.auth/admin.json`.
3. Each spec runs with the persisted auth and a fresh page. Specs create
   their own `e2e-`-prefixed CPTs/posts/terms; they are cleaned up between
   runs (not within a run — specs are independent and scoped).
4. `globalTeardown` does nothing destructive — the next run resets via
   `loadSeedFixture()`.

## Files

```
tests/e2e/
├── HANDOFF.md                          # this file
├── playwright.config.ts                # global setup, reporter, baseURL
├── fixtures/
│   ├── build-fixture.php               # WP-CLI eval-file script (rebuilds seed.sql.gz)
│   └── seed.sql.gz                     # committed SQL dump (~90K)
├── helpers/
│   ├── fixtures.ts                     # createSitemap / createPost / createTerm /
│   │                                   # regenerateSitemap / fetchSitemap /
│   │                                   # runScheduledJobs / installMuPlugin
│   ├── global-setup.ts                 # fixture load + auth + baseline reset
│   ├── global-teardown.ts              # no-op
│   └── wp-cli.ts                       # wpCli / wpCliJson / wpEval / loadSeedFixture
├── mu-plugins/
│   └── skip-post-by-meta.php           # exercised by skip-post-filter.spec.ts
└── specs/
    ├── admin-settings.spec.ts          # React panel: create/edit/delete sitemaps
    ├── sitemap-routing.spec.ts         # /sitemaps/<slug>/... routes + XSL + paginated terms
    ├── skip-post-filter.spec.ts        # cxs_sitemap_skip_post filter
    ├── meta-cache.spec.ts              # Memcached-safe CPT meta cache
    ├── term-invalidation.spec.ts       # term CRUD enqueues regen jobs
    ├── wp-cli.spec.ts                  # wp cxs list|generate|validate|stats
    └── url-limit-notice.spec.ts        # 1000-URL admin notice gating
```

## The seed fixture

`tests/e2e/fixtures/seed.sql.gz` (90 KB committed, ~640 KB uncompressed)
contains four datasets that every spec can rely on:

| Slug prefix | Count | Date range | Purpose |
|-------------|-------|------------|---------|
| `fx-bulk-202406-`   | 1000 | 2024-06    | URL-limit notice (≥1000 in one bucket) |
| `fx-spread-2023-MM-`| 500  | 2023 (all months, ~42/month) | Granularity assertions |
| `fx-cat-`           | 1100 categories | n/a | Paginated terms sitemap (>1000 → `<sitemapindex>`) |
| `fx-img-202408-`    | 25   | 2024-08    | News/image extension rendering |

The 1100 categories are linked round-robin to the 500 spread posts so they
survive `hide_empty=true` filtering.

### Critical implications for spec authors

- `category` taxonomy now has 1100 visible terms. Any sitemap with
  `terms`-mode + `category` + `hide_empty=true` will hit the
  `<sitemapindex>` paginated path (`MAX_TERMS_PER_SITEMAP = 1000` in
  `Terms_Sitemap_Generator`). Use `post_tag` (empty in fixture) for ≤1000-term
  assertions; use `category` to deliberately exercise pagination.
- **NEVER use `e2e-` as a slug prefix and expect it to survive across runs.**
  `resetBaselineState` deletes those between runs.
- **NEVER use `fx-` as a slug prefix in specs** — those are fixture rows and
  must not be modified or deleted by a spec.

### Rebuilding the fixture

When the schema or fixture content needs to change:

```sh
# From the plugin root.
pnpm env:destroy
pnpm env:start

# Run the build script against a clean DB.
./node_modules/.bin/wp-env run tests-cli wp eval-file \
    /var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/fixtures/build-fixture.php

# Export and recompress.
./node_modules/.bin/wp-env run tests-cli wp db export \
    /var/www/html/wp-content/plugins/custom-xml-sitemap/tests/e2e/fixtures/seed.sql \
    --add-drop-table

gzip -f tests/e2e/fixtures/seed.sql

# Verify the suite still passes against the new dump.
pnpm test:e2e
```

The build script is documented at the top of
`tests/e2e/fixtures/build-fixture.php`. Time budget: ~50s build + 2s export.

## Helpers

### `wp-cli.ts`

- **`wpCli(args)`** — synchronous shell-out to wp-env's `tests-cli` container.
  Throws on non-zero exit.
- **`wpCliJson<T>(args)`** — same, JSON-parses stdout.
- **`wpEval(php)`** — runs an arbitrary PHP snippet via `wp eval-file`. Uses
  a tempfile under `tests/e2e/.tmp/` (bind-mounted into the container).
  Do NOT use `wp eval` directly: argv escaping is broken under
  `execFileSync` (no shell).
- **`ensurePrettyPermalinks()`** — sets `permalink_structure=/%postname%/`
  and flushes rewrite rules.
- **`loadSeedFixture()`** — gunzips and imports `seed.sql.gz`.

### `fixtures.ts`

- **`createSitemap(page, props)`** — drives the React admin panel
  (`#cxs-settings-panel`) using exact-text labels. Returns the new sitemap's
  slug.
- **`createPost(props)`** — creates a post via WP-CLI. Term assignment uses
  `wp post term set --by=id` because the default is `--by=slug` and would
  silently create new terms whose names are stringified IDs.
- **`createTerm(taxonomy, name, slug)`** — `wp term create` wrapper.
- **`regenerateSitemap(slug)`** — kicks off the regenerate AS job and
  drains the queue.
- **`fetchSitemap(url)`** — fetches a sitemap URL from the host (Node
  `fetch`, not WP `wp_remote_get` — the latter fails inside the container
  with cURL error 7). Follows redirects and retries once with
  `connection: close` if Apache drops keep-alive.
- **`runScheduledJobs(group)`** — drains AS jobs in a group. Falls back to
  `as_get_scheduled_actions` + `process_action` if `stake_claim` throws
  `InvalidArgumentException` (no group row in DB yet).

## Troubleshooting

**Test creates a term named `123` instead of using term ID 123.**
You're calling `wp post term set` without `--by=id`. The default is
`--by=slug`, so passing a numeric ID causes WP-CLI to silently CREATE a new
term whose slug and name are the stringified ID. Always pass `--by=id` when
working with numeric term IDs.

**`wp post list --name__like=fx-` returns ALL posts.**
This filter is silently ignored by WP-CLI — it's not a real filter. Same for
`wp term list --slug__like=`. The fix already lives in
`resetBaselineState`: list `ID,post_name` for all rows, filter by prefix in
JS.

**`fetch('http://localhost:8889/sitemaps/...')` from inside the container
throws cURL error 7.**
The container's localhost is the container, not the WP host. Always use
Node `fetch` from the host (port-mapped to `:8889`).

**`fetchSitemap` intermittently throws `SocketError: other side closed`.**
Apache in wp-env occasionally drops keep-alive. The helper retries once with
`connection: close`; if you see this in your own helper, copy the retry.

**`/cxs-stylesheet.xsl` returns 301.**
`redirect_canonical` adds a trailing slash. `fetchSitemap` follows redirects
by default; if you're using raw `fetch`, set `redirect: 'follow'`.

**`stake_claim('cxs-sitemap')` throws `InvalidArgumentException`.**
The AS group has never been claimed before (no group row in DB yet). Use
`runScheduledJobs()` from `helpers/fixtures.ts` instead, which falls back to
`as_get_scheduled_actions` + `process_action`.

**Terms-mode sitemap unexpectedly returns `<sitemapindex>` instead of
`<urlset>`.**
You're probably using `category` taxonomy. The fixture seeds 1100 categories,
so anything `>1000` (the pagination threshold) goes to the index path. Switch
to `post_tag` for inline-urlset assertions, or assert on the paginated
shape if that's what you want to test.

**Plugin isn't active in the tests env after fixture import.**
`global-setup`'s `resetBaselineState()` runs `wp plugin activate
custom-xml-sitemap` after every fixture reload. wp-env auto-activates in
`:8888` but NOT `:8889`.

**`pnpm install` fails with workspace errors.**
The parent monorepo's `pnpm-workspace.yaml` only includes `themes/*-theme`.
The plugin is NOT in the workspace — use `pnpm install --ignore-workspace`.
CI is unaffected because the plugin checks out standalone.

**`@wordpress/e2e-test-utils-playwright` upgrade pulled in `11.x`.**
That's `@wordpress/scripts`. The Playwright-utils package's latest is
`1.45.0`; we pin `^1.19.0`.

**`wp-env run --quiet` hangs or errors.**
`wp-env run` doesn't accept `--quiet`. Pass `<container> wp <args>` directly.

## CI

`.github/workflows/test.yml`:

- `lint` (PHPCS + ESLint)
- `test-php` (PHPUnit unit + integration) and `test-e2e` (Playwright) run
  in parallel, both gated on `lint`
- `build` runs after both pass

Playwright report and traces are uploaded as artefacts on failure
(`playwright-report/`, `test-results/`).

## Things NOT to touch

- `.gitignore` entries: `/playwright-report/`, `/test-results/`,
  `/tests/e2e/.auth/`, `/tests/e2e/.tmp/`.
- The fixture's `fx-` slug prefix convention. If you change it, you must
  also update `build-fixture.php`, the spec assertions in
  `sitemap-routing.spec.ts` and `url-limit-notice.spec.ts`, and rebuild
  `seed.sql.gz`.
- `MAX_TERMS_PER_SITEMAP = 1000` in `Terms_Sitemap_Generator.php`. Specs
  assume this constant.
