<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\helpers\FileHelper;
use craft\models\Site;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\models\PageVariant;

class FileDriverTest extends TestCase
{
    private string $originalCachePath;
    private string $cachePath;
    private FileDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = BetterCache::getInstance()->getSettings();
        $this->originalCachePath = $settings->cachePath;
        $this->cachePath = Craft::$app->getPath()->getTempPath() . '/better-cache-test';
        $settings->cachePath = $this->cachePath;
        $this->driver = new FileDriver();
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->cachePath);
        BetterCache::getInstance()->getSettings()->cachePath = $this->originalCachePath;
        parent::tearDown();
    }

    public function testFilePathMirrorsHostUriAndQuery(): void
    {
        $site = $this->siteWithUrl();
        $base = $this->cachePath . '/' . strtolower((string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST));
        $basePath = trim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');
        $base .= $basePath !== '' ? '/' . $basePath : '';

        $this->assertSame("$base/index.html", $this->driver->getFilePath(new PageVariant($site->id, '')));
        $this->assertSame("$base/blog/p2/index.html", $this->driver->getFilePath(new PageVariant($site->id, 'blog/p2')));
        $this->assertSame("$base/blog/a=1&page=2/index.html", $this->driver->getFilePath(new PageVariant($site->id, 'blog', 'a=1&page=2')));
    }

    public function testSaveReplacesFileWithoutLeavingTempFiles(): void
    {
        $variant = new PageVariant($this->siteWithUrl()->id, 'blog');

        $this->driver->save($variant, 'old');
        $this->driver->save($variant, 'new');

        $file = $this->driver->getFilePath($variant);
        $this->assertSame('new', file_get_contents($file));
        $this->assertSame([$file], FileHelper::findFiles(dirname($file)));
    }

    private function siteWithUrl(): Site
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (parse_url((string)$site->getBaseUrl(), PHP_URL_HOST)) {
                return $site;
            }
        }

        $this->markTestSkipped('No site with an absolute base URL.');
    }
}
