<?php

namespace samuelreichor\betterCache\helpers;

use Craft;
use samuelreichor\betterCache\Constants;

final class BlitzConfig
{
    private const REFRESH_MODE_EXPIRE = 0;
    private const QUERY_STRINGS_DO_NOT_CACHE = 0;
    private const QUERY_STRINGS_SAME_PAGE = 2;

    private const REFRESH_STRATEGIES = [
        self::REFRESH_MODE_EXPIRE => Constants::REFRESH_STRATEGY_EXPIRE,
        1 => Constants::REFRESH_STRATEGY_CLEAR,
        2 => Constants::REFRESH_STRATEGY_EXPIRE,
        3 => Constants::REFRESH_STRATEGY_REFRESH,
    ];

    private const STORAGE_DRIVERS = [
        'putyourlightson\blitz\drivers\storage\FileStorage' => Constants::STORAGE_DRIVER_FILE,
        'putyourlightson\blitz\drivers\storage\YiiCacheStorage' => Constants::STORAGE_DRIVER_CRAFT_CACHE,
    ];

    private const TRANSLATED_SETTINGS = [
        'cachingEnabled',
        'refreshMode',
        'includedUriPatterns',
        'excludedUriPatterns',
        'queryStringCaching',
        'includedQueryStringParams',
        'excludedQueryStringParams',
        'cacheStorageType',
        'cacheGeneratorSettings',
    ];

    // Blitz settings whose value is what Better Cache always does.
    private const MATCHING_SETTINGS = [
        'refreshCacheEnabled' => true,
        'refreshCacheWhenElementSavedUnchanged' => false,
        'refreshCacheWhenElementSavedNotLive' => false,
        'cacheNonHtmlResponses' => false,
        'cacheActionRequests' => false,
    ];

    /**
     * @param array<string, mixed> $blitz the settings of one environment
     * @return array{array<string, mixed>, string[]} the Better Cache settings and notes on what was not translated
     */
    public static function translate(array $blitz): array
    {
        $config = [];
        $notes = [];

        if (array_key_exists('cachingEnabled', $blitz)) {
            $config['enabled'] = (bool)$blitz['cachingEnabled'];
        }

        if (array_key_exists('refreshMode', $blitz)) {
            $config['refreshStrategy'] = self::REFRESH_STRATEGIES[$blitz['refreshMode']] ?? Constants::REFRESH_STRATEGY_CLEAR;
            if ($blitz['refreshMode'] === self::REFRESH_MODE_EXPIRE) {
                $notes[] = 'refreshMode: Blitz expires pages until they are visited, Better Cache re-renders them right away.';
            }
        }

        foreach (['includedUriPatterns', 'excludedUriPatterns'] as $setting) {
            if (isset($blitz[$setting])) {
                $config[$setting] = self::bySite($blitz[$setting], 'uriPattern', $notes);
            }
        }

        if (array_key_exists('queryStringCaching', $blitz)) {
            if ($blitz['queryStringCaching'] === self::QUERY_STRINGS_DO_NOT_CACHE) {
                $config['includedQueryParams'] = [];
            } elseif ($blitz['queryStringCaching'] === self::QUERY_STRINGS_SAME_PAGE) {
                $config['excludedQueryParams'] = [Constants::ALL_SITES_KEY => ['.*']];
            } else {
                $config['includedQueryParams'] = isset($blitz['includedQueryStringParams'])
                    ? self::bySite($blitz['includedQueryStringParams'], 'queryStringParam', $notes)
                    : [Constants::ALL_SITES_KEY => ['.*']];
            }
        }
        if (isset($blitz['excludedQueryStringParams']) && !isset($config['excludedQueryParams'])) {
            $config['excludedQueryParams'] = self::bySite($blitz['excludedQueryStringParams'], 'queryStringParam', $notes);
        }

        if (isset($blitz['cacheStorageType'])) {
            if (isset(self::STORAGE_DRIVERS[$blitz['cacheStorageType']])) {
                $config['storageDriver'] = self::STORAGE_DRIVERS[$blitz['cacheStorageType']];
            } else {
                $notes[] = "cacheStorageType: {$blitz['cacheStorageType']} has no Better Cache driver.";
            }
        }

        if (isset($blitz['cacheGeneratorSettings']['concurrency'])) {
            $config['warmConcurrency'] = (int)$blitz['cacheGeneratorSettings']['concurrency'];
        }

        $untranslated = [];
        foreach ($blitz as $setting => $value) {
            if (
                !in_array($setting, self::TRANSLATED_SETTINGS, true) &&
                !(array_key_exists($setting, self::MATCHING_SETTINGS) && self::MATCHING_SETTINGS[$setting] === $value)
            ) {
                $untranslated[] = $setting;
            }
        }
        if ($untranslated) {
            $notes[] = 'Not translated: ' . implode(', ', $untranslated) . '.';
        }

        return [$config, $notes];
    }

    /**
     * @param array<array<string, mixed>> $rows Blitz's `[enabled, siteId, <key>]` rows
     * @param string[] $notes
     * @return array<string, string[]>
     */
    private static function bySite(array $rows, string $key, array &$notes): array
    {
        $bySite = [];
        foreach ($rows as $row) {
            if (!($row['enabled'] ?? true)) {
                continue;
            }

            $siteId = $row['siteId'] ?? '';
            if ($siteId === '') {
                $handle = Constants::ALL_SITES_KEY;
            } else {
                $handle = Craft::$app->getSites()->getSiteById((int)$siteId, true)?->handle;
                if ($handle === null) {
                    $notes[] = "$key {$row[$key]}: site $siteId does not exist.";
                    continue;
                }
            }

            if ($key === 'uriPattern' && str_contains($row[$key], '\\?')) {
                $notes[] = "uriPattern {$row[$key]}: matches the query string in Blitz, Better Cache matches the path only. Use includedQueryParams.";
            }

            $bySite[$handle][] = $row[$key];
        }

        return $bySite;
    }
}
