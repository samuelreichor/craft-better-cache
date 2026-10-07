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
| Re-rendering | Anonymous HTTP GET to the real URL (Guzzle pool, no cookies, bypass header, configurable concurrency), run as queue jobs. |
| Warming | All live element URLs per site plus all Page Variants known to the Tag Index. |
| Scheduled Changes | Cron command detects passed postDate/expiryDate and triggers an Invalidation. |
| Deploys | `better-cache/cache/invalidate` applies the global strategy; `better-cache/cache/clear` as escape hatch. |
| Cacheability | Blitz rules (see below). URIs are opt-in via include patterns. URLs with a query string are not cached by default. |
| Logged-in users | Served the Cached Page; their renders are not stored. |
| Dynamic content | CSRF endpoint + JS, plus Dynamic Includes. Always JS, for both drivers. |
| CDN | `Cache-Control` and cache tag headers, plus a `PurgerInterface`. No concrete CDN in v1. |
| Enabling | `enabled` setting, set per environment through Craft's multi-environment config. |
| CP | Utility page (counts per site, invalidate/clear/warm, single URL) plus an entry in Craft's "Clear Caches". |
| Server config | nginx/Apache snippets in the docs only. |
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
- Listen to `Elements::EVENT_INVALIDATE_CACHES`. One save fires it several times (propagation, nested elements), so buffer the tags per request and resolve them once at the end of the request.
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

## Open

- There is no test harness yet. Pick one before step 2 (Codeception as Craft ships it, or Pest).
