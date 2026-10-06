<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\db\Query;
use craft\helpers\FileHelper;
use craft\models\Site;
use craft\web\Request;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\Settings;

class CaptureTest extends TestCase
{
    private Settings $originalSettings;
    private Site $originalSite;
    private ?string $originalRequestMethod;
    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSite = Craft::$app->getSites()->getCurrentSite();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $settings = BetterCache::getInstance()->getSettings();
        $this->originalSettings = clone $settings;
        $this->cachePath = Craft::$app->getPath()->getTempPath() . '/better-cache-test';
        $settings->enabled = true;
        $settings->includedUriPatterns = ['*' => ['.*']];
        $settings->cachePath = $this->cachePath;
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->cachePath);
        BetterCache::getInstance()->setSettings($this->originalSettings->toArray());
        Craft::$app->getSites()->setCurrentSite($this->originalSite);
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
        parent::tearDown();
    }

    public function testStorageErrorDoesNotBreakResponse(): void
    {
        $site = $this->siteWithUrl();
        Craft::$app->getSites()->setCurrentSite($site);
        $path = str_repeat('a', 300);

        $capture = BetterCache::getInstance()->capture;
        $capture->start($this->request("/$path"));
        $response = new Response();
        $response->content = '<html></html>';
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        $capture->finish($response);

        $this->assertTrue((new Query())->from(Constants::TABLE_PAGES)->where(['siteId' => $site->id, 'path' => $path])->exists());
        $this->assertSame('<html></html>', $response->content);
    }

    private function request(string $url): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        /** @var Request $request */
        $request = Craft::createObject([
            'class' => Request::class,
            'isConsoleRequest' => false,
            'hostInfo' => 'https://example.test',
            'scriptUrl' => '/index.php',
            'scriptFile' => Craft::getAlias('@webroot/index.php'),
            'url' => $url,
            'pathInfo' => ltrim($url, '/'),
            'queryParams' => [],
        ]);

        return $request;
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
