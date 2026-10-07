# Better Cache

Easy scalable static caching for Craft CMS

## Requirements

This plugin requires Craft CMS 5 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Better Cache”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require samuelreichor/craft-better-cache

# tell Craft to install the plugin
./craft plugin/install better-cache
```

Cached pages end with `<!-- Cached by Better Cache on … -->`. When PHP serves a cached page instead of the web server, `<!-- Served by Better Cache on … -->` follows it.

## Console Commands

- `php craft better-cache/cache/invalidate` applies the refresh strategy to every cached page. Use it after deploys.
- `php craft better-cache/cache/clear` deletes all cached pages and the tag index.
- `php craft better-cache/cache/clear-url <url>` clears a single URL.
- `php craft better-cache/cache/warm` queues a render of all live pages.
- `php craft better-cache/cache/refresh-scheduled` updates pages whose entries reached their post or expiry date. Run it from cron, e.g. every minute.

Invalidate, warm and clear are also available in the control panel under Utilities, Better Cache. The entry sidebar shows whether the entry's page is cached and can clear it.

`php craft clear-caches/all` also clears all cached pages, the tag index and (with a purger) the CDN. In deploy scripts, prefer `better-cache/cache/invalidate`.

## Queue

Invalidations and re-renders run as queue jobs on Craft's `queue` component. To keep them apart from other jobs, define a second queue in `config/app.php`, point Better Cache to it and run a worker for its channel:

```php
'components' => [
    'betterCacheQueue' => [
        'class' => craft\queue\Queue::class,
        'channel' => 'better-cache',
    ],
    'plugins' => [
        'pluginConfigs' => [
            'better-cache' => ['queue' => 'betterCacheQueue'],
        ],
    ],
],
```

## Dynamic Content

A cached page is the same for every visitor. Two things are filled in by a small inline script after the page loads:

- CSRF tokens: every `{{ csrfInput() }}` and `<meta name="csrf-token">` on a cached page gets a fresh token per visitor. No template changes are needed.
- Dynamic Includes: `{{ craft.betterCache.include('_partials/cart', { label: 'Cart' }) }}` renders the template per visitor. Variables must be scalars or arrays.

Saving a Commerce order (a cart) never refreshes cached pages, so anything showing the cart, like a counter in the header, must be rendered with `craft.betterCache.include`.

The include variables travel in the request URL, so pass IDs rather than large arrays. Included templates are rendered without the page layout, so `{% js %}` and `{% css %}` inside them are lost.

## CDN

Cached pages rendered or served by PHP for guests carry `Cache-Control: public, s-maxage=…` (setting `cdnMaxAge`) and a tag header (setting `cacheTagHeader`, `Cache-Tag` by default, `Surrogate-Key` for Fastly, empty to disable). Logged-in visitors get `Cache-Control: private`, stale pages and uncacheable responses get no CDN headers. Craft's cache tags are hashed for the tag header.

To purge a CDN, set `purger` to a class implementing `samuelreichor\betterCache\purgers\PurgerInterface`. It receives the hashed tags on Invalidation, URLs when a single URL is cleared, and a purge of everything on clear.

CDN headers are only sent when a purger is configured. Pages served directly by nginx or Apache carry no PHP headers, so a CDN only sees these headers on responses from Craft.

Re-renders and warming request the public URL. If a CDN sits in front, it must not answer requests with the `X-Better-Cache-Bypass` header from its cache (on Cloudflare, a cache rule that bypasses the cache when the header is present).

## Server Configuration

With the File storage driver, Cached Pages are written to `@webroot/cache/better-cache/{host}/{uri}/{query}/index.html` (see the `cachePath` setting). The web server serves them without booting Craft. Only GET requests without the `X-Better-Cache-Bypass` header are served from the cache; everything else falls through to Craft. Better Cache sends that header when it re-renders or warms pages. Without the snippets below, PHP serves the cached pages early in the request instead.

The query string becomes a directory below the URI, so a cached `/blog/archive` is also served for `/blog?archive`.

### nginx

Replace the `try_files` line of your `location /` block:

```nginx
set $better_cache_file /better-cache-miss;
if ($request_method = GET) {
    set $better_cache_file /cache/better-cache/$host/$uri/$args/index.html;
}
if ($http_x_better_cache_bypass) {
    set $better_cache_file /better-cache-miss;
}

location / {
    try_files $better_cache_file $uri $uri/ /index.php?$query_string;
}
```

### Apache

Add these rules to `web/.htaccess`, before Craft's own rewrite rules:

```apache
RewriteEngine On
RewriteCond %{REQUEST_METHOD} GET
RewriteCond %{HTTP:X-Better-Cache-Bypass} ^$
RewriteCond %{DOCUMENT_ROOT}/cache/better-cache/%{HTTP_HOST}%{REQUEST_URI}/%{QUERY_STRING}/index.html -s
RewriteRule .* /cache/better-cache/%{HTTP_HOST}%{REQUEST_URI}/%{QUERY_STRING}/index.html [L]
```

Apache's `%{HTTP_HOST}` keeps the port and letter case the client sent, so such requests fall through to Craft.

If you change `cachePath`, adjust the paths above to match.

## Prior Art

- [riasvdv/craft-static-cache](https://github.com/riasvdv/craft-static-cache) (Craft 6)
