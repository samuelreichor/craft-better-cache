<?php

namespace samuelreichor\betterCache\tests;

use craft\helpers\FileHelper;
use samuelreichor\betterCache\drivers\FileDriver;
use samuelreichor\betterCache\models\PageVariant;

class FileDriverTest extends TestCase
{
    private string $cachePath;
    private FileDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = $this->useTempCachePath();
        $this->driver = new FileDriver();
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

    public function testDeleteAllRemovesEveryFile(): void
    {
        $siteId = $this->siteWithUrl()->id;
        $this->driver->save(new PageVariant($siteId, 'a'), 'a');
        $this->driver->save(new PageVariant($siteId, 'b', 'x=1'), 'b');

        $this->driver->deleteAll();

        $this->assertDirectoryDoesNotExist($this->cachePath);
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
}
