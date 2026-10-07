<?php

namespace samuelreichor\betterCache\tests;

use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

class CraftCacheDriverInvalidationTest extends InvalidationTestCase
{
    protected function storageDriver(): string
    {
        return Constants::STORAGE_DRIVER_CRAFT_CACHE;
    }

    protected function tearDown(): void
    {
        foreach ($this->pages as $variant) {
            BetterCache::getInstance()->getStorageDriver()->delete($variant);
        }
        parent::tearDown();
    }
}
