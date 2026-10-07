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

    public const CACHE_FILE_NAME = 'index.html';
    public const MUTEX_PREFIX = 'better-cache:';
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
