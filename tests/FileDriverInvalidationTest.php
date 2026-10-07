<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;

class FileDriverInvalidationTest extends InvalidationTestCase
{
    protected function storageDriver(): string
    {
        return Constants::STORAGE_DRIVER_FILE;
    }

    public function testBrokenPageDoesNotBlockOthers(): void
    {
        $siteWithoutHost = null;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!parse_url((string)$site->getBaseUrl(), PHP_URL_HOST)) {
                $siteWithoutHost = $site;
            }
        }
        if ($siteWithoutHost === null) {
            $this->markTestSkipped('No site without a host to break the file path.');
        }
        $broken = new PageVariant($siteWithoutHost->id, 'broken');
        $brokenId = BetterCache::getInstance()->tagIndex->upsertPage($broken, [self::ENTRY]);

        BetterCache::getInstance()->invalidation->addTags([self::ENTRY]);
        $this->flushInvalidation();

        $this->assertCleared('blog');
        $this->assertArrayHasKey($brokenId, BetterCache::getInstance()->tagIndex->findAllPages());
    }
}
