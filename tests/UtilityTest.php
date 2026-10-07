<?php

namespace samuelreichor\betterCache\tests;

use craft\db\Query;
use craft\utilities\ClearCaches;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;

class UtilityTest extends TestCase
{
    public function testCountsPagesPerSiteFromTheTagIndex(): void
    {
        $tagIndex = BetterCache::getInstance()->tagIndex;
        $tagIndex->deleteAllPages();
        $siteId = $this->siteWithUrl()->id;
        $tagIndex->upsertPage(new PageVariant($siteId, 'a'), []);
        $staleId = $tagIndex->upsertPage(new PageVariant($siteId, 'b'), []);
        $tagIndex->markStale([$staleId]);

        $this->assertSame([$siteId => ['total' => 2, 'stale' => 1]], $tagIndex->countPagesBySite());
    }

    public function testClearCachesOptionClearsEverything(): void
    {
        $this->useTempCachePath();
        BetterCache::getInstance()->tagIndex->upsertPage(new PageVariant($this->siteWithUrl()->id, 'a'), []);

        $options = array_filter(ClearCaches::cacheOptions(), fn(array $option) => $option['key'] === Constants::HANDLE);
        $this->assertCount(1, $options);

        (reset($options)['action'])();

        $this->assertFalse((new Query())->from(Constants::TABLE_PAGES)->exists());
    }
}
