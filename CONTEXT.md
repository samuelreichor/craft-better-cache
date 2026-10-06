# Better Cache

Full-page static caching for Craft CMS that relies on Craft's own cache tags for dependency tracking instead of its own query tracking. It trades precision (coarse, section-level invalidation) for speed and reliability.

## Language

### Pages

**Cached Page**:
The stored HTML response of one Page Variant, kept in the active Storage Driver.
_Avoid_: Cache entry, static file, HTML cache

**Storage Driver**:
Where Cached Pages live and who serves them: the File driver (static files served by the web server without booting Craft) or the Craft Cache driver (Craft's cache component, served by Craft early in the request, shared across servers).
_Avoid_: Backend, adapter

**Page Variant**:
One site and one path. Only when query string caching is turned on does the normalized query string (without tracking params like gclid) also distinguish variants.
_Avoid_: URL, cache key

**Included URI**:
A URI matching the site's include patterns and none of its exclude patterns. Only Included URIs become Cached Pages; nothing is included by default.

**Stale Page**:
A Cached Page that was invalidated but is still served until its re-render replaces it.

**Dynamic Include**:
A part of a Cached Page that is rendered fresh per visitor and filled in by the browser after load. The CSRF token is the built-in case.
_Avoid_: Island, hole, ESI

### Dependencies

**Cache Tag**:
A tag Craft itself collects while a page renders (an element, a section, an element type). Better Cache never invents its own.
_Avoid_: Dependency, surrogate key

**Tag Index**:
The record of which Cached Pages carry which Cache Tags. It outlives the files and doubles as the list of known Page Variants.

**Invalidation**:
Craft announcing that some Cache Tags changed. Every Cached Page carrying one of them is then handled by the Refresh Strategy.
_Avoid_: Purge (reserved for CDNs), bust

**Scheduled Change**:
An element's postDate or expiryDate passing. Craft does not announce it, so Better Cache detects it and triggers an Invalidation itself.

### Refreshing

**Refresh Strategy**:
The single, site-wide rule for what happens to invalidated Cached Pages: Clear, Expire or Refresh.

**Clear**:
Delete the Cached Page; the next visitor renders it.

**Expire**:
Keep serving the page as a Stale Page while it is re-rendered in the background.

**Refresh**:
Delete the Cached Page and re-render it in the background immediately.

**Warming**:
Rendering Page Variants ahead of visitors by requesting their real URLs anonymously.
_Avoid_: Generating, preloading, crawling

### CDN

**Purge**:
Telling a CDN to drop its copies of pages, by URL or by Cache Tag.
