<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\db\Query;
use craft\events\InvalidateElementCachesEvent;
use craft\services\Elements;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

class OrderTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->enabled = true;
        $this->settings()->includedUriPatterns = ['*' => ['.*']];
        $this->useTempCachePath();
    }

    public function testOrderTagsAreNotStored(): void
    {
        $site = $this->siteWithUrl();
        Craft::$app->getSites()->setCurrentSite($site);

        $capture = BetterCache::getInstance()->capture;
        $capture->start($this->request('/shop'));
        Craft::$app->getElements()->collectCacheTags([Constants::ORDER_TAG, Constants::ORDER_TAG . '::*', 'element::5']);
        $capture->finish($this->htmlResponse());

        $tags = (new Query())
            ->select('t.tag')
            ->from(['t' => Constants::TABLE_PAGE_TAGS])
            ->innerJoin(['p' => Constants::TABLE_PAGES], '[[p.id]] = [[t.pageId]]')
            ->where(['p.siteId' => $site->id, 'p.path' => 'shop'])
            ->column();
        $this->assertSame(['element::5'], $tags);
    }

    public function testSavingAnOrderInvalidatesNothing(): void
    {
        $jobs = $this->capturePushedJobs(function() {
            Craft::$app->getElements()->trigger(Elements::EVENT_INVALIDATE_CACHES, new InvalidateElementCachesEvent([
                'tags' => [Constants::ORDER_TAG . '::*', 'element::5'],
            ]));
            BetterCache::getInstance()->invalidation->flush();
        });

        $this->assertSame([], $jobs);
    }
}
