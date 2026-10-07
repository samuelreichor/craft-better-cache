<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\db\Query;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

class CaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->enabled = true;
        $this->settings()->includedUriPatterns = ['*' => ['.*']];
        $this->useTempCachePath();
    }

    public function testStorageErrorDoesNotBreakResponse(): void
    {
        $site = $this->siteWithUrl();
        Craft::$app->getSites()->setCurrentSite($site);
        $path = str_repeat('a', 300);

        $capture = BetterCache::getInstance()->capture;
        $capture->start($this->request("/$path"));
        $response = $this->htmlResponse('<html></html>');
        $capture->finish($response);

        $this->assertTrue((new Query())->from(Constants::TABLE_PAGES)->where(['siteId' => $site->id, 'path' => $path])->exists());
        $this->assertSame('<html></html>', $response->content);
    }
}
