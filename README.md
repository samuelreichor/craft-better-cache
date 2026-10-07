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

## Server Configuration

With the File storage driver, Cached Pages are written to `@webroot/cache/better-cache/{host}/{uri}/{query}/index.html` (see the `cachePath` setting). The web server serves them without booting Craft. Only GET requests without the `X-Better-Cache-Bypass` header are served from the cache; everything else falls through to Craft. Better Cache sends that header when it re-renders or warms pages.

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
