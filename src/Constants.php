<?php

namespace samuelreichor\betterCache;

final class Constants
{
    public const HANDLE = 'better-cache';

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

    // A fragment never reaches the server, so `#` cannot occur in a request URI.
    public const URI_PATTERN_DELIMITER = '#';
}
