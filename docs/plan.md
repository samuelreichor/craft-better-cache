# Better Cache v1 Plan

Terms follow `CONTEXT.md`.

## Decisions

| Area | Decision |
|---|---|
| Dependency tracking | Only Craft's native cache tags via `startCollectingCacheInfo()`. Coarse, section-level invalidation is accepted. No own query tracking. |
| Unit | Full site GET response, HTML only. |
| Storage | `StorageDriverInterface` with two drivers: **File** (static files in `@webroot/cache/better-cache`, configurable, served by nginx/Apache) and **Craft Cache** (Craft's cache component, served by Craft early in the request, for multi-server). |
| Tag Index | DB tables for both drivers. A miss upserts its row, so missing files heal themselves. |
| Refresh Strategy | One global setting: Clear, Expire or Refresh. |
| Re-rendering | Anonymous HTTP GET to the real URL (Guzzle pool, no cookies, bypass header, configurable concurrency), run as queue jobs in batches. A re-render that redirects or returns a 4xx clears the page. |
| Warming | All live element URLs per site plus all Page Variants known to the Tag Index. |
| Scheduled Changes | Cron command detects passed postDate/expiryDate and triggers an Invalidation. |
| Deploys | `better-cache/cache/invalidate` applies the global strategy; `better-cache/cache/clear` as escape hatch. `clear-caches/all` clears everything too, so deploy scripts should prefer `invalidate`. |
| Cacheability | Blitz rules (see below). URIs are opt-in via include patterns. URLs with a query string are not cached by default. |
| Logged-in users | Served the Cached Page with `Cache-Control: private`, keeping their cookies; their renders are not stored. |
| Dynamic content | CSRF inputs and the `csrf-token` meta tag become placeholders automatically when a page is stored (no helper), filled by one inline script from a CSRF endpoint. Dynamic Includes via `craft.betterCache.include()` with a signed payload. Always JS, for both drivers. |
| CDN | `Cache-Control` and hashed cache tag headers, only when a purger is configured. `PurgerInterface` with `purgeTags`, `purgeUrls` and `purgeAll`; the CDN is purged before re-renders run. Stale pages get no public headers. No concrete CDN in v1. |
| Enabling | `enabled` setting, set per environment through Craft's multi-environment config. |
| CP | Utility page (counts per site, invalidate/clear/warm) plus an entry in Craft's "Clear Caches" that clears everything. A card in the entry sidebar shows the page's cache status and clears it. |
| Server config | nginx/Apache snippets in the docs only. Without them, PHP serves cached pages early in the request. |
| Out of v1 | Per-template overrides, concrete CDN purgers, deployers, cached includes, SSI/ESI, non-HTML responses. |

### Cacheability rules (from Blitz)

Request:
- Site request, GET, not console, not an action request.
- Not a preview or token request (`x-craft-preview`, `x-craft-live-preview`, `tokenParam`, `X-Craft-Token`, site token). The warm bypass is exempt.
- Not refused for logged-in users, except: site offline without `accessSiteWhenSystemIsOff`, or debug toolbar enabled for the site.
- No non-empty `no-cache` param.
- URI at most 2048 chars, no segment made only of periods (or `%2e`), not under `resourceBaseUrl`, not `index.php`.
- Matches an include pattern and no exclude pattern (regex per site, exclude wins, nothing included by default).
- No query string unless query string caching is on; `gclid` and `fbclid` are always dropped.

Response:
- Status 2xx only (no redirects, no 404s).
- HTML only.
- Not stored if the body contains `assets/generate-transform` or the two-step verification form.
- Set-Cookie headers are removed before storing and serving; the page is still stored.
- Only the body is stored. Craft's standard headers are re-added on serve; headers set by templates are lost.
- Mutex per Page Variant with a 1s timeout. If the lock fails, the page is served but not stored.

## Implementation notes

- Start collecting cache info before routing (on `EVENT_INIT`), and also call `collectCacheInfoForElement()` for the matched element, because Craft loads it before rendering starts.
- Listen to `Elements::EVENT_INVALIDATE_CACHES`. One save fires it several times (propagation, nested elements), so buffer the tags per request (and per queue job) and push one `InvalidateTagsJob` at the end. The job resolves the tags and applies the strategy, so saving content does not wait. The explicit commands (`invalidate`, `clear`, `clear-url`) stay synchronous.
- Match tags exactly against the Tag Index. The tags in `Constants::ALL_PAGES_TAGS` affect every page: the bare `element` tag (Fields, Sites) and the GlobalSet tag, since global sets are memoized per request and pages may not carry their tags.
- Keep the cacheability check and the strategy decision behind one seam so per-template overrides can be added later without restructuring.
- Expire under the File driver: keep the file, mark the row stale, queue a re-render that replaces the file atomically (write to a temp file, then rename).

## Steps

1. **Settings and enabling**: `Settings` model with `enabled`, `storageDriver`, `refreshStrategy`, include/exclude URI patterns per site, query string caching, warm concurrency, cache path.
   Verify: settings load from `config/better-cache.php` with per-environment values; phpstan and ecs pass.
2. **Tag Index**: install migration for pages (site, path, query, stale flag, dates) and page tags; a service to upsert a page with its tags and to find pages by tags.
   Verify: upsert twice yields one row; lookup by any tag returns the page.
3. **Cacheability**: request and response checks from the rules above, behind one service.
   Verify: each rule has a case that is refused and one that passes.
4. **File driver + capture**: collect cache info around the render, store body and tags on a cacheable response, docs snippets for nginx and Apache.
   Verify: in the showcase site, a second request to an included URI is served by nginx without PHP (check a response header that only PHP sets).
5. **Invalidation with Clear**: buffered `EVENT_INVALIDATE_CACHES` listener, resolve tags to pages, delete files and rows.
   Verify: saving an entry in a section removes the pages that queried that section and keeps unrelated pages; saving Globals removes all pages.
6. **Re-rendering and warming**: queue job sending anonymous HTTP requests with the bypass header; Expire and Refresh strategies on top of it; warm command from element URLs plus Tag Index.
   Verify: under Expire, the old file is served until the job replaces it; under Refresh, the file is gone and then reappears.
7. **CLI**: `cache/invalidate`, `cache/clear`, `cache/warm`, `cache/refresh-scheduled`, plus clearing a single URL.
   Verify: an entry with a future postDate appears on its listing page after the cron run.
8. **Craft Cache driver**: store pages in Craft's cache component and serve them early in the request, sharing the Tag Index.
   Verify: steps 4 to 7 behave the same with this driver selected.
9. **Dynamic content**: CSRF endpoint and JS, Dynamic Include Twig helper and endpoint.
   Verify: a form on a Cached Page submits successfully for two different anonymous visitors.
10. **CDN headers**: `Cache-Control` and cache tag headers on cacheable responses, `PurgerInterface` called on Invalidation.
    Verify: headers present on cacheable responses and absent on uncacheable ones.
11. **CP**: utility page and "Clear Caches" entry.
    Verify: counts match the Tag Index; the buttons trigger the same services as the CLI.

## Known limits

- A render that started before a save can store old content after the Invalidation ran.
- Scheduled Changes cover entries only, not other element types with post dates.
- The query string is a directory below the URI, so a cached `/blog/archive` is also served for `/blog?archive`.
- The Apache snippet is not verified.
- `InvalidateTagsJob` does not split itself; this only matters at six-figure page counts.
- The utility's rendering is not covered by automated tests (the console test app cannot render CP forms).
- Pages served directly by nginx or Apache carry no CDN headers.
- Changing a site's base URL leaves the files under the old host or path until `cache/clear`.
- The per-page retry when a file cannot be deleted is only tested while the showcase has a site without a host.

Tests use PHPUnit against the surrounding showcase install (`composer test` inside ddev).

## Blitz parity

Switching from Blitz should need no more than translating the config, replacing the `craft.blitz` template calls and swapping the server snippet.

| Area | Decision |
|---|---|
| Queue | Plugin property `queue` (component ID, default `queue`), set like Blitz through `pluginConfigs` in `config/app.php`. Invalidation and render jobs go there. |
| Unchanged and not live saves | Same rules as Blitz, without settings: a save is skipped when nothing changed (not first save, not deleted, same status per site, no dirty attributes or fields), or when the status did not change and is not live, active, enabled, pending or expired. The original status is read in `EVENT_BEFORE_SAVE_ELEMENT`. Only invalidations of an element that is being saved itself are skipped, so owners invalidated for a nested element still refresh. |
| Query strings | `includedQueryParams` make a separate Page Variant, `excludedQueryParams` are ignored (default `gclid`, `fbclid`, `utm_.*`). Both are regexes on the param name, per site. Any other param makes the request uncacheable, unlike Blitz, which strips it and so lets a template that reads it poison the cache. Replaces `cacheQueryStrings`. |
| Blitz config | `better-cache/blitz/config` reads `config/blitz.php` through Craft's config service (no Blitz install needed) and prints a `config/better-cache.php`, with unsupported settings as comments. A README section covers the remaining steps. |

### Steps

1. **Queue**: plugin property, used by `Invalidation` and `Warming`.
   Verify: with `pluginConfigs` pointing to a second queue, jobs are pushed there.
2. **Unchanged and not live saves**: before-save snapshot, skip in the invalidation handler.
   Verify: unchanged save, disabled entry edit: no job. Title change, enable, disable, new, delete, nested entry change: job. In the bench, re-saving an article re-renders nothing.
3. **Query strings**: settings, validation, `Cacheability`, CP settings, README.
   Verify: included param gives its own variant, excluded param maps to the page without it, unknown param is not cached, param order does not matter.
4. **Blitz config**: console command and README section.
   Verify: a Blitz fixture config translates to the expected Better Cache config, including site handles, refresh modes and query params.
