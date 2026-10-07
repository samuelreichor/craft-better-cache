<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use PHPUnit\Framework\Attributes\DataProvider;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\helpers\CachePath;
use yii\base\InvalidConfigException;

class CachePathTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'webroot' => ['@webroot'],
            'webroot with slash' => ['@webroot/'],
            'project root' => ['@root'],
            'empty' => [''],
            'filesystem root' => ['/'],
            'storage' => ['@storage'],
            'templates' => ['@templates'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsFailValidation(string $path): void
    {
        $this->settings()->cachePath = $path;

        $this->assertFalse(CachePath::isSafe($path));
        $this->assertFalse($this->settings()->validate(['cachePath']));
    }

    public function testParentOfWebrootIsUnsafe(): void
    {
        $this->assertFalse(CachePath::isSafe(dirname((string)Craft::getAlias('@webroot'))));
    }

    public function testCacheDirectoriesAreSafe(): void
    {
        $this->assertTrue(CachePath::isSafe('@webroot/cache/better-cache'));
        $this->assertTrue(CachePath::isSafe('@storage/better-cache'));
    }

    public function testDeleteAllRefusesUnsafePath(): void
    {
        $this->settings()->cachePath = '@webroot';

        $this->expectException(InvalidConfigException::class);
        (new FileDriver())->deleteAll();
    }
}
