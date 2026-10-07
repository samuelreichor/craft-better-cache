<?php

namespace samuelreichor\betterCache\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\drivers\CraftCacheDriver;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\models\PageVariant;

class StorageDriverTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return [
            'file' => [Constants::STORAGE_DRIVER_FILE],
            'craft cache' => [Constants::STORAGE_DRIVER_CRAFT_CACHE],
        ];
    }

    #[DataProvider('drivers')]
    public function testSaveGetDelete(string $driver): void
    {
        $this->useTempCachePath();
        $this->settings()->storageDriver = $driver;
        $storage = BetterCache::getInstance()->getStorageDriver();
        $siteId = $this->siteWithUrl()->id;
        $page = new PageVariant($siteId, 'better-cache-test');
        $other = new PageVariant($siteId, 'better-cache-test', 'x=1');

        $this->assertNull($storage->get($page));
        $storage->save($page, 'old');
        $storage->save($page, 'new');
        $storage->save($other, 'other');
        $this->assertSame('new', $storage->get($page));

        $storage->delete($page);
        $this->assertNull($storage->get($page));
        $this->assertSame('other', $storage->get($other));

        $storage->deleteAll();
        $this->assertNull($storage->get($other));
    }

    public function testSettingSelectsDriver(): void
    {
        $this->settings()->storageDriver = Constants::STORAGE_DRIVER_CRAFT_CACHE;
        $this->assertInstanceOf(CraftCacheDriver::class, BetterCache::getInstance()->getStorageDriver());

        $this->settings()->storageDriver = Constants::STORAGE_DRIVER_FILE;
        $this->assertInstanceOf(FileDriver::class, BetterCache::getInstance()->getStorageDriver());
    }
}
