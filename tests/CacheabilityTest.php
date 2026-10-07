<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\User;
use craft\web\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\models\PageVariant;
use samuelreichor\betterCache\models\Settings;
use samuelreichor\betterCache\services\Cacheability;

class CacheabilityTest extends TestCase
{
    private Cacheability $cacheability;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheability = BetterCache::getInstance()->cacheability;
        $this->settings = $this->settings();
        $this->settings->enabled = true;
        $this->settings->includedUriPatterns = ['*' => ['.*']];
        $this->settings->excludedUriPatterns = [];
        $this->settings->cacheQueryStrings = false;
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getConfig()->getGeneral()->isSystemLive = null;
        parent::tearDown();
    }

    public function testCacheableRequestYieldsVariant(): void
    {
        $variant = $this->cacheability->getPageVariant($this->request('/blog/p2'));

        $this->assertNotNull($variant);
        $this->assertSame(Craft::$app->getSites()->getCurrentSite()->id, $variant->siteId);
        $this->assertSame('blog/p2', $variant->path);
        $this->assertSame('', $variant->query);
    }

    public function testDisabledIsRefused(): void
    {
        $this->settings->enabled = false;

        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog')));
    }

    public function testConsoleRequestIsRefused(): void
    {
        $request = $this->request('/blog');
        $request->setIsConsoleRequest(true);

        $this->assertNull($this->cacheability->getPageVariant($request));
    }

    public function testNonGetIsRefused(): void
    {
        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog', method: 'POST')));
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog', method: 'GET')));
    }

    public function testActionRequestIsRefused(): void
    {
        $this->assertNull($this->cacheability->getPageVariant($this->request('/actions/users/session-info')));
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function tokenRequests(): array
    {
        return [
            'preview param' => ['/blog?x-craft-preview=abc', []],
            'live preview param' => ['/blog?x-craft-live-preview=abc', []],
            'token param' => ['/blog?token=abc', []],
            'token header' => ['/blog', ['X-Craft-Token' => 'abc']],
            'site token param' => ['/blog?siteToken=abc', []],
            'site token header' => ['/blog', ['X-Craft-Site-Token' => 'abc']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('tokenRequests')]
    public function testPreviewAndTokenRequestsAreRefused(string $url, array $headers): void
    {
        $this->settings->cacheQueryStrings = true;

        $this->assertNull($this->cacheability->getPageVariant($this->request($url, $headers)));
    }

    public function testNoCacheParamIsRefused(): void
    {
        $this->settings->cacheQueryStrings = true;

        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog?no-cache=1')));
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog?no-cache=')));
    }

    public function testLoggedInUserIsServed(): void
    {
        Craft::$app->getUser()->setIdentity($this->admin());

        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog')));
    }

    public function testOfflineSiteWithoutPermissionIsRefused(): void
    {
        Craft::$app->getConfig()->getGeneral()->isSystemLive = false;
        Craft::$app->getUser()->setIdentity(new User(['admin' => false]));
        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog')));

        Craft::$app->getUser()->setIdentity($this->admin());
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog')));
    }

    public function testDebugToolbarIsRefused(): void
    {
        $admin = $this->admin();
        Craft::$app->getUsers()->saveUserPreferences($admin, ['enableDebugToolbarForSite' => true]);
        Craft::$app->getUser()->setIdentity($admin);

        try {
            $this->assertNull($this->cacheability->getPageVariant($this->request('/blog')));
        } finally {
            // Craft memoizes preferences, so the rollback alone would leak them into later tests.
            Craft::$app->getUsers()->saveUserPreferences($admin, ['enableDebugToolbarForSite' => false]);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'too long' => ['/' . str_repeat('a', 2048)],
            'dot segment' => ['/blog/../admin'],
            'encoded dot segment' => ['/blog/%2E%2e/admin'],
            'index.php' => ['/index.php'],
            'resource path' => ['/cpresources/abc/app.js'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRefused(string $url): void
    {
        $this->assertNull($this->cacheability->getPageVariant($this->request($url)));
    }

    public function testDotsInsideSegmentsPass(): void
    {
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog/v1.2')));
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/' . str_repeat('a', 2047))));
    }

    public function testNothingIsIncludedByDefault(): void
    {
        $this->settings->includedUriPatterns = [];

        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog')));
    }

    public function testIncludePatternsApplyPerSite(): void
    {
        $handle = Craft::$app->getSites()->getCurrentSite()->handle;
        $this->settings->includedUriPatterns = [$handle => ['^blog'], 'otherSite' => ['^about']];

        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog')));
        $this->assertNull($this->cacheability->getPageVariant($this->request('/about')));
    }

    public function testExcludeWins(): void
    {
        $this->settings->excludedUriPatterns = ['*' => ['^blog/secret']];

        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog/secret')));
        $this->assertNotNull($this->cacheability->getPageVariant($this->request('/blog/public')));
    }

    public function testQueryStringIsRefusedUnlessEnabled(): void
    {
        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog?page=2')));

        $this->settings->cacheQueryStrings = true;
        $variant = $this->cacheability->getPageVariant($this->request('/blog?page=2&a=1'));
        $this->assertSame('a=1&page=2', $variant?->query);
    }

    public function testTrackingParamsAreDropped(): void
    {
        $variant = $this->cacheability->getPageVariant($this->request('/blog?gclid=abc&fbclid=def'));

        $this->assertSame('', $variant?->query);
    }

    public function testUrlMapsToPageVariant(): void
    {
        $site = $this->siteWithUrl();
        $baseUrl = rtrim((string)$site->getBaseUrl(), '/');

        $variant = $this->cacheability->getPageVariantForUrl("$baseUrl/blog/p2?page=2&a=1&gclid=x");

        $this->assertEquals(new PageVariant($site->id, 'blog/p2', 'a=1&page=2'), $variant);
        $this->assertSame('', $this->cacheability->getPageVariantForUrl("$baseUrl/")?->path);
        $this->assertNull($this->cacheability->getPageVariantForUrl('https://unknown.example/blog'));
        $upperHost = preg_replace_callback('#^https?://[^/]+#', fn(array $m) => strtoupper($m[0]), $baseUrl);
        $this->assertSame('blog', $this->cacheability->getPageVariantForUrl("$upperHost/blog")?->path);
    }

    public function testStorableResponse(): void
    {
        $this->assertTrue($this->cacheability->isStorable($this->htmlResponse()));
    }

    public function testLoggedInRenderIsNotStored(): void
    {
        Craft::$app->getUser()->setIdentity($this->admin());

        $this->assertFalse($this->cacheability->isStorable($this->htmlResponse()));
    }

    public function testNonSuccessfulResponseIsNotStored(): void
    {
        $this->assertFalse($this->cacheability->isStorable($this->htmlResponse(statusCode: 301)));
        $this->assertFalse($this->cacheability->isStorable($this->htmlResponse(statusCode: 404)));
    }

    public function testNonHtmlResponseIsNotStored(): void
    {
        $json = $this->htmlResponse();
        $json->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');
        $this->assertFalse($this->cacheability->isStorable($json));

        $withoutContentType = $this->htmlResponse();
        $withoutContentType->getHeaders()->remove('Content-Type');
        $this->assertFalse($this->cacheability->isStorable($withoutContentType));

        $xml = $this->htmlResponse();
        $xml->getHeaders()->set('Content-Type', 'application/xml');
        $this->assertFalse($this->cacheability->isStorable($xml));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uncacheableBodies(): array
    {
        return [
            'transform url' => ['<img src="https://example.test/actions/assets/generate-transform?transformId=1">'],
            'escaped transform url' => ['<script>var u = "actions\/assets\/generate-transform";</script>'],
            'totp form' => ['<input class="code auth-totp-code">'],
            'recovery code form' => ['<input class="code auth-recovery-code">'],
        ];
    }

    #[DataProvider('uncacheableBodies')]
    public function testUncacheableBodyIsNotStored(string $body): void
    {
        $this->assertFalse($this->cacheability->isStorable($this->htmlResponse($body)));
    }

    private function admin(): User
    {
        $admin = User::find()->admin()->status(null)->one();
        $this->assertInstanceOf(User::class, $admin);

        return $admin;
    }
}
