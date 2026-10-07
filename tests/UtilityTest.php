<?php

namespace samuelreichor\betterCache\tests;

use samuelreichor\betterCache\BetterCache;
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
}
