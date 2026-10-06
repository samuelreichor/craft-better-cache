<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\models\Settings;
use samuelreichor\betterCache\services\Cacheability;

class CacheabilityTest extends TestCase
{
    private Cacheability $cacheability;
    private Settings $originalSettings;
    private Settings $settings;
    private ?string $originalRequestMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->cacheability = BetterCache::getInstance()->cacheability;
        $this->settings = BetterCache::getInstance()->getSettings();
        $this->originalSettings = clone $this->settings;
        $this->settings->enabled = true;
        $this->settings->includedUriPatterns = ['*' => ['.*']];
        $this->settings->excludedUriPatterns = [];
        $this->settings->cacheQueryStrings = false;
    }

    protected function tearDown(): void
    {
        BetterCache::getInstance()->setSettings($this->originalSettings->toArray());
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getConfig()->getGeneral()->isSystemLive = null;
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
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

        $this->assertNull($this->cacheability->getPageVariant($this->request('/blog')));
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

    public function testStorableResponse(): void
    {
        $this->assertTrue($this->cacheability->isStorable($this->response()));
    }

    public function testLoggedInRenderIsNotStored(): void
    {
        Craft::$app->getUser()->setIdentity($this->admin());

        $this->assertFalse($this->cacheability->isStorable($this->response()));
    }

    public function testNonSuccessfulResponseIsNotStored(): void
    {
        $this->assertFalse($this->cacheability->isStorable($this->response(statusCode: 301)));
        $this->assertFalse($this->cacheability->isStorable($this->response(statusCode: 404)));
    }

    public function testNonHtmlResponseIsNotStored(): void
    {
        $json = $this->response();
        $json->getHeaders()->set('Content-Type', 'application/json; charset=UTF-8');
        $this->assertFalse($this->cacheability->isStorable($json));

        $withoutContentType = $this->response();
        $withoutContentType->getHeaders()->remove('Content-Type');
        $this->assertFalse($this->cacheability->isStorable($withoutContentType));

        $xml = $this->response();
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
        $this->assertFalse($this->cacheability->isStorable($this->response($body)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $url, array $headers = [], string $method = 'GET'): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        /** @var Request $request */
        $request = Craft::createObject([
            'class' => Request::class,
            'isConsoleRequest' => false,
            'hostInfo' => 'https://example.test',
            'scriptUrl' => '/index.php',
            'scriptFile' => Craft::getAlias('@webroot/index.php'),
            'url' => $url,
            'pathInfo' => ltrim((string)parse_url($url, PHP_URL_PATH), '/'),
            'queryParams' => $query,
        ]);
        foreach ($headers as $name => $value) {
            $request->getHeaders()->set($name, $value);
        }

        return $request;
    }

    private function response(string $body = '<html><body>Hi</body></html>', int $statusCode = 200): Response
    {
        $response = new Response();
        $response->content = $body;
        $response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8');
        $response->setStatusCode($statusCode);

        return $response;
    }

    private function admin(): User
    {
        $admin = User::find()->admin()->status(null)->one();
        $this->assertInstanceOf(User::class, $admin);

        return $admin;
    }
}
