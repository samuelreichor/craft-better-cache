<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\User;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use samuelreichor\betterCache\services\Cdn;

class CdnTest extends TestCase
{
    private Cdn $cdn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cdn = BetterCache::getInstance()->cdn;
        RecordingPurger::$calls = [];
        RecordingPurger::$fail = false;
        $this->settings()->purger = RecordingPurger::class;
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        parent::tearDown();
    }

    public function testPublicHeadersCarryMaxAgeAndNormalizedTags(): void
    {
        $this->settings()->cdnMaxAge = 600;
        $response = $this->htmlResponse();

        $this->cdn->addPublicHeaders($response, ['element', 'element::craft\elements\Entry::*', 'element']);

        $this->assertSame('public, s-maxage=600, max-age=0', $response->getHeaders()->get('Cache-Control'));
        $tags = explode(',', (string)$response->getHeaders()->get('Cache-Tag'));
        $this->assertSame([$this->cdn->normalizeTag('element'), $this->cdn->normalizeTag('element::craft\elements\Entry::*')], $tags);
        foreach ($tags as $tag) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{' . Constants::CDN_TAG_LENGTH . '}$/', $tag);
        }
    }

    public function testTagHeaderNameIsConfigurableAndCanBeDisabled(): void
    {
        $this->settings()->cacheTagHeader = 'Surrogate-Key';
        $response = $this->htmlResponse();
        $this->cdn->addPublicHeaders($response, ['element']);
        $this->assertTrue($response->getHeaders()->has('Surrogate-Key'));
        $this->assertFalse($response->getHeaders()->has('Cache-Tag'));

        $this->settings()->cacheTagHeader = '';
        $response = $this->htmlResponse();
        $this->cdn->addPublicHeaders($response, ['element']);
        $this->assertFalse($response->getHeaders()->has('Surrogate-Key'));
        $this->assertTrue($response->getHeaders()->has('Cache-Control'));
    }

    public function testLongTagHeaderIsTruncated(): void
    {
        $response = $this->htmlResponse();

        $this->cdn->addPublicHeaders($response, array_map(fn(int $id) => "element::$id", range(1, 5000)));

        $this->assertLessThanOrEqual(Constants::MAX_CDN_TAG_HEADER_LENGTH, strlen((string)$response->getHeaders()->get('Cache-Tag')));
    }

    public function testPurgerReceivesNormalizedTags(): void
    {
        $this->cdn->purgeTags(['element::1', 'element::1', 'element::2']);

        $this->assertSame([['tags', [$this->cdn->normalizeTag('element::1'), $this->cdn->normalizeTag('element::2')]]], RecordingPurger::$calls);
    }

    public function testPurgerErrorsAreSwallowed(): void
    {
        RecordingPurger::$fail = true;

        $this->cdn->purgeAll();

        $this->assertSame([], RecordingPurger::$calls);
    }

    public function testWithoutPurgerNothingHappens(): void
    {
        $this->settings()->purger = null;

        $this->cdn->purgeAll();

        $this->assertSame([], RecordingPurger::$calls);
    }

    public function testInvalidationPurgesTagsOrEverything(): void
    {
        $invalidation = BetterCache::getInstance()->invalidation;

        $invalidation->addTags(['element::42']);
        $this->flushInvalidation();
        $invalidation->addTags(['element::42', Constants::ALL_PAGES_TAGS[0]]);
        $this->flushInvalidation();

        $this->assertSame([['tags', [$this->cdn->normalizeTag('element::42')]], ['all', null]], RecordingPurger::$calls);
    }

    public function testClearCommandsPurge(): void
    {
        $this->useTempCachePath();
        $invalidation = BetterCache::getInstance()->invalidation;
        $url = BetterCache::getInstance()->warming->getUrl(new PageVariant($this->siteWithUrl()->id, 'blog'));

        $invalidation->clearUrl($url);
        $invalidation->clearAll();

        $this->assertSame([['urls', [$url]], ['all', null]], RecordingPurger::$calls);
    }

    public function testClearingASitePurgesEverything(): void
    {
        BetterCache::getInstance()->invalidation->clearSite($this->siteWithUrl()->id);

        $this->assertSame([['all', null]], RecordingPurger::$calls);
    }

    public function testStoredGuestRenderGetsPublicHeaders(): void
    {
        $response = $this->finishCapture('/cdn-test');

        $this->assertStringStartsWith('public', (string)$response->getHeaders()->get('Cache-Control'));
    }

    public function testWithoutPurgerNoPublicHeadersAreSent(): void
    {
        $this->settings()->purger = null;

        $response = $this->finishCapture('/cdn-test');

        $this->assertFalse($response->getHeaders()->has('Cache-Control'));
        $this->assertFalse($response->getHeaders()->has('Cache-Tag'));
    }

    public function testLoggedInRenderIsPrivate(): void
    {
        Craft::$app->getUser()->setIdentity(User::find()->admin()->status(null)->one());

        $response = $this->finishCapture('/cdn-test');

        $this->assertSame(Constants::PRIVATE_CACHE_CONTROL, $response->getHeaders()->get('Cache-Control'));
        $this->assertFalse($response->getHeaders()->has('Cache-Tag'));
    }

    public function testUncacheableResponsesGetNoCdnHeaders(): void
    {
        $notFound = $this->finishCapture('/cdn-test', 404);
        $this->assertFalse($notFound->getHeaders()->has('Cache-Control'));

        $this->settings()->includedQueryParams = [];
        $withQuery = $this->finishCapture('/cdn-test?x=1');
        $this->assertFalse($withQuery->getHeaders()->has('Cache-Control'));
        $this->assertFalse($withQuery->getHeaders()->has('Cache-Tag'));
    }

    private function finishCapture(string $url, int $statusCode = 200): Response
    {
        $this->useTempCachePath();
        $this->settings()->enabled = true;
        $this->settings()->includedUriPatterns = ['*' => ['.*']];
        Craft::$app->getSites()->setCurrentSite($this->siteWithUrl());

        $capture = BetterCache::getInstance()->capture;
        $capture->start($this->request($url));
        $response = $this->htmlResponse(statusCode: $statusCode);
        $capture->finish($response);

        return $response;
    }
}
