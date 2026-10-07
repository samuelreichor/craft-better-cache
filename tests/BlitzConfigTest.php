<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\BlitzConfig;

class BlitzConfigTest extends TestCase
{
    public function testSettingsAreTranslated(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();

        [$config, $notes] = BlitzConfig::translate([
            'cachingEnabled' => true,
            'refreshMode' => 3,
            'includedUriPatterns' => [
                ['enabled' => true, 'siteId' => $site->id, 'uriPattern' => '.*'],
                ['enabled' => false, 'siteId' => $site->id, 'uriPattern' => 'disabled'],
            ],
            'excludedUriPatterns' => [
                ['enabled' => true, 'siteId' => '', 'uriPattern' => 'shop/.*'],
            ],
            'queryStringCaching' => 1,
            'includedQueryStringParams' => [
                ['enabled' => true, 'siteId' => '', 'queryStringParam' => 'page'],
            ],
            'excludedQueryStringParams' => [
                ['enabled' => true, 'siteId' => '', 'queryStringParam' => 'gclid'],
            ],
            'cacheStorageType' => 'putyourlightson\blitz\drivers\storage\YiiCacheStorage',
            'cacheGeneratorSettings' => ['concurrency' => 2],
            'refreshCacheWhenElementSavedUnchanged' => false,
        ]);

        $this->assertSame([
            'enabled' => true,
            'refreshStrategy' => Constants::REFRESH_STRATEGY_REFRESH,
            'includedUriPatterns' => [$site->handle => ['.*']],
            'excludedUriPatterns' => [Constants::ALL_SITES_KEY => ['shop/.*']],
            'includedQueryParams' => [Constants::ALL_SITES_KEY => ['page']],
            'excludedQueryParams' => [Constants::ALL_SITES_KEY => ['gclid']],
            'storageDriver' => Constants::STORAGE_DRIVER_CRAFT_CACHE,
            'warmConcurrency' => 2,
        ], $config);
        $this->assertSame([], $notes);
    }

    public function testQueryStringModesAreTranslated(): void
    {
        [$doNotCache] = BlitzConfig::translate(['queryStringCaching' => 0]);
        [$samePage] = BlitzConfig::translate(['queryStringCaching' => 2]);
        [$uniqueWithDefaults] = BlitzConfig::translate(['queryStringCaching' => 1]);

        $this->assertSame([], $doNotCache['includedQueryParams']);
        $this->assertSame([Constants::ALL_SITES_KEY => ['.*']], $samePage['excludedQueryParams']);
        $this->assertSame([Constants::ALL_SITES_KEY => ['.*']], $uniqueWithDefaults['includedQueryParams']);
    }

    public function testUnsupportedSettingsAreNoted(): void
    {
        [$config, $notes] = BlitzConfig::translate([
            'refreshMode' => 0,
            'cachePurgerType' => 'some\Purger',
            'includedUriPatterns' => [['enabled' => true, 'siteId' => PHP_INT_MAX, 'uriPattern' => '.*']],
        ]);

        $this->assertSame(Constants::REFRESH_STRATEGY_EXPIRE, $config['refreshStrategy']);
        $this->assertSame([], $config['includedUriPatterns']);
        $this->assertCount(3, $notes);
        $this->assertStringContainsString('cachePurgerType', implode("\n", $notes));
    }
}
