<div align="center">
	<a href="https://packagist.org/packages/samuelreichor/craft-better-cache" align="center">
      <img src="https://online-images-sr.netlify.app/assets/craft-better-cache.png" width="100" alt="Better Cache">
	</a>
  <br>
	<h1 align="center">Easy Scalable Static Caching for Craft CMS</h1>
  <p align="center">
    Better Cache stores the rendered HTML of your Craft pages and serves it straight from the web server. When content changes, it reuses Craft's own cache tags to find the affected pages and refreshes them in the background.
  <br/>
</div>

<p align="center">
  <a href="https://packagist.org/packages/samuelreichor/craft-better-cache">
    <img src="https://img.shields.io/packagist/v/samuelreichor/craft-better-cache?label=version&color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-better-cache">
    <img src="https://img.shields.io/packagist/dt/samuelreichor/craft-better-cache?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-better-cache">
    <img src="https://img.shields.io/packagist/php-v/samuelreichor/craft-better-cache?color=blue">
  </a>
  <a href="https://packagist.org/packages/samuelreichor/craft-better-cache">
    <img src="https://img.shields.io/packagist/l/samuelreichor/craft-better-cache?color=blue">
  </a>
</p>

## Features

- **Static HTML Caching**: Pages are served by nginx or Apache without booting Craft
- **Craft Cache Tags**: Affected pages are found with Craft's own cache tags, without tracking every element query a second time
- **Refresh Strategies**: Clear, expire or refresh pages when content changes
- **Background Refreshes**: Invalidated pages are re-rendered and warmed in the queue
- **Dynamic Content**: CSRF tokens and dynamic includes are filled in per visitor
- **Query Strings**: Cache query params as separate pages or ignore them per site
- **CDN Ready**: `Cache-Control` and cache tag headers plus a purger interface
- **Scheduled Entries**: Pages refresh when entries reach their post or expiry date
- **Switch from Blitz**: Translate your Blitz config with a single command

## Performance

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://online-images-sr.netlify.app/assets/craft-better-cache-benchmark-dark.svg">
  <img src="https://online-images-sr.netlify.app/assets/craft-better-cache-benchmark-light.svg" alt="With 10,000 cached pages, saving an article takes 511 s with Better Cache and 1,046 s with Blitz 5.14. Warming the cache takes 2,363 s and 4,148 s.">
</picture>

Measured on a Craft 5 site with 14 sites and 10,000 cached pages, with 3 pages rendered in parallel for both plugins. The [blog post](https://samuelreichor.at/blogs/why-better-cache#benchmark) has the full numbers and how they were measured.

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Documentation

Visit the [Better Cache documentation](https://samuelreichor.at/libraries/craft-better-cache) for all documentation, guides and developer resources.

## Support

If you encounter bugs or have feature requests, [please submit an issue](/../../issues/new). Your feedback helps improve the plugin!
