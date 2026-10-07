<?php

namespace samuelreichor\betterCache;

use craft\elements\GlobalSet;

final class Constants
{
    public const HANDLE = 'better-cache';

    public const TABLE_PAGES = '{{%bettercache_pages}}';
    public const TABLE_PAGE_TAGS = '{{%bettercache_pagetags}}';

    public const STORAGE_DRIVER_FILE = 'file';
    public const STORAGE_DRIVER_CRAFT_CACHE = 'craftCache';
    public const STORAGE_DRIVERS = [
        self::STORAGE_DRIVER_FILE,
        self::STORAGE_DRIVER_CRAFT_CACHE,
    ];

    public const REFRESH_STRATEGY_CLEAR = 'clear';
    public const REFRESH_STRATEGY_EXPIRE = 'expire';
    public const REFRESH_STRATEGY_REFRESH = 'refresh';
    public const REFRESH_STRATEGIES = [
        self::REFRESH_STRATEGY_CLEAR,
        self::REFRESH_STRATEGY_EXPIRE,
        self::REFRESH_STRATEGY_REFRESH,
    ];

    public const ALL_SITES_KEY = '*';

    // Craft announces the bare tag when fields or sites change. Global sets are memoized per request,
    // so pages using them may not carry their tags.
    public const ALL_PAGES_TAGS = [
        'element',
        'element::' . GlobalSet::class . '::*',
    ];

    // Makes the web server skip the Cached Page, so re-renders and warming reach Craft.
    public const BYPASS_HEADER = 'X-Better-Cache-Bypass';

    // Small batches keep each render job well below the queue's time to reserve.
    public const RENDER_BATCH_SIZE = 50;
    public const RENDER_TIMEOUT = 30;

    // Clearing deletes the whole cache path, so it must not be one of these or a parent of them.
    public const PROTECTED_ROOT_ALIASES = ['@webroot', '@root'];
    // Their children are fine (e.g. @storage/better-cache), only the directories themselves are protected.
    public const PROTECTED_PATH_ALIASES = ['@storage', '@config', '@templates', '@vendor', '@translations'];

    public const UTILITY_ID = self::HANDLE;
    public const UTILITY_ACTIONS = [
        'invalidate' => self::HANDLE . '/utility/invalidate',
        'clear' => self::HANDLE . '/utility/clear',
        'clearUrl' => self::HANDLE . '/utility/clear-url',
        'warm' => self::HANDLE . '/utility/warm',
    ];

    // Shared by the console commands and the utility, translated with Craft::t(self::HANDLE, ...).
    public const MESSAGE_PAGES_INVALIDATED = '{count} pages invalidated.';
    public const MESSAGE_CACHE_CLEARED = 'Cache cleared.';
    public const MESSAGE_URL_CLEARED = 'Cleared {url}.';
    public const MESSAGE_NO_SITE_FOR_URL = 'No site matches {url}.';
    public const MESSAGE_WARMING_QUEUED = 'Warming queued.';

    public const CSRF_ACTION = self::HANDLE . '/dynamic/csrf';
    public const INCLUDE_ACTION = self::HANDLE . '/dynamic/include';
    public const INCLUDE_PAYLOAD_PARAM = 'payload';
    public const CSRF_PLACEHOLDER_ATTRIBUTE = 'data-better-cache-csrf';
    public const CSRF_META_NAME = 'csrf-token';
    public const INCLUDE_PLACEHOLDER_ATTRIBUTE = 'data-better-cache-include';
    public const SCRIPT_MARKER_ATTRIBUTE = 'data-better-cache-script';
    public const NO_STORE_CACHE_CONTROL = 'no-store, private';

    public const CACHE_CONTROL_HEADER = 'Cache-Control';
    public const PUBLIC_CACHE_CONTROL = 'public, s-maxage=%d, max-age=0';
    public const PRIVATE_CACHE_CONTROL = 'private, no-cache';
    public const DEFAULT_CDN_MAX_AGE = 31536000;
    // Cloudflare's name. Fastly uses Surrogate-Key, Akamai Edge-Cache-Tag.
    public const DEFAULT_CACHE_TAG_HEADER = 'Cache-Tag';
    public const CDN_TAG_SEPARATOR = ',';
    public const CDN_TAG_LENGTH = 12;
    // Cloudflare's limit for the tag header.
    public const MAX_CDN_TAG_HEADER_LENGTH = 16 * 1024;

    public const CACHE_FILE_NAME = 'index.html';
    public const CACHE_KEY_PREFIX = self::HANDLE . ':page:';
    public const CACHE_DEPENDENCY_TAG = self::HANDLE . ':pages';
    public const LAST_SCHEDULED_CHECK_CACHE_KEY = self::HANDLE . ':lastScheduledCheck';
    public const MUTEX_PREFIX = self::HANDLE . ':';
    public const MUTEX_TIMEOUT = 1;

    public const HTML_CONTENT_TYPE = 'text/html';

    public const MAX_URI_LENGTH = 2048;
    public const NO_CACHE_PARAM = 'no-cache';
    public const PREVIEW_PARAMS = ['x-craft-preview', 'x-craft-live-preview'];
    public const TOKEN_HEADERS = ['X-Craft-Token', 'X-Craft-Site-Token'];
    public const TRACKING_PARAMS = ['gclid', 'fbclid'];

    // Transform URLs only resolve once, and two-step verification forms belong to one visitor.
    public const UNCACHEABLE_BODY_MARKERS = [
        'assets/generate-transform',
        'auth-totp-code',
        'auth-recovery-code',
    ];

    // A fragment never reaches the server, so `#` cannot occur in a request URI.
    public const URI_PATTERN_DELIMITER = '#';
}
