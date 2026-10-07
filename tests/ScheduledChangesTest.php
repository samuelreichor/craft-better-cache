<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use craft\events\InvalidateElementCachesEvent;
use craft\helpers\Db;
use craft\services\Elements;
use DateTime;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use yii\base\Event;

class ScheduledChangesTest extends TestCase
{
    private mixed $originalLastCheck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLastCheck = Craft::$app->getCache()->get(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY);
        if (!Craft::$app->getEntries()->getSectionByHandle('blog')) {
            $this->markTestSkipped('The blog section is missing.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalLastCheck === false) {
            Craft::$app->getCache()->delete(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY);
        } else {
            Craft::$app->getCache()->set(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY, $this->originalLastCheck);
        }
        parent::tearDown();
    }

    public function testWithoutKeyAndPagesNothingIsInvalidated(): void
    {
        Craft::$app->getCache()->delete(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY);
        BetterCache::getInstance()->tagIndex->deleteAllPages();
        $this->saveEntry(postDate: new DateTime('-1 minute'));

        $this->assertSame([], $this->invalidatedElementIds(fn() => BetterCache::getInstance()->scheduledChanges->invalidatePassed()));
        $this->assertNotFalse(Craft::$app->getCache()->get(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY));
    }

    public function testWithoutKeyTheOldestPageIsTheLowerBound(): void
    {
        Craft::$app->getCache()->delete(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY);
        BetterCache::getInstance()->tagIndex->deleteAllPages();
        $pageId = BetterCache::getInstance()->tagIndex->upsertPage(new PageVariant($this->siteWithUrl()->id, 'blog'), []);
        Db::update(Constants::TABLE_PAGES, ['dateUpdated' => Db::prepareDateForDb(new DateTime('-1 hour'))], ['id' => $pageId], updateTimestamp: false);
        $published = $this->saveEntry(postDate: new DateTime('-1 minute'));
        $before = $this->saveEntry(postDate: new DateTime('-2 hours'));

        $invalidated = $this->invalidatedElementIds(fn() => BetterCache::getInstance()->scheduledChanges->invalidatePassed());

        $this->assertContains($published->id, $invalidated);
        $this->assertNotContains($before->id, $invalidated);
    }

    public function testEntriesWithPassedDatesAreInvalidated(): void
    {
        $published = $this->saveEntry(postDate: new DateTime('-1 minute'));
        $expired = $this->saveEntry(postDate: new DateTime('-1 day'), expiryDate: new DateTime('-1 minute'));
        $untouched = $this->saveEntry(postDate: new DateTime('-1 day'));
        $pending = $this->saveEntry(postDate: new DateTime('+1 day'));
        Craft::$app->getCache()->set(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY, (new DateTime('-1 hour'))->getTimestamp());

        $invalidated = $this->invalidatedElementIds(fn() => BetterCache::getInstance()->scheduledChanges->invalidatePassed());

        $this->assertContains($published->id, $invalidated);
        $this->assertContains($expired->id, $invalidated);
        $this->assertNotContains($untouched->id, $invalidated);
        $this->assertNotContains($pending->id, $invalidated);
    }

    private function saveEntry(DateTime $postDate, ?DateTime $expiryDate = null): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Better Cache scheduled test',
            'postDate' => $postDate,
            'expiryDate' => $expiryDate,
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry, false));

        return $entry;
    }

    /**
     * @return int[]
     */
    private function invalidatedElementIds(callable $callback): array
    {
        $ids = [];
        $handler = function(InvalidateElementCachesEvent $event) use (&$ids) {
            foreach ($event->tags as $tag) {
                if (preg_match('/^element::(\d+)$/', $tag, $match)) {
                    $ids[] = (int)$match[1];
                }
            }
        };
        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, $handler);
        try {
            $callback();
        } finally {
            Event::off(Elements::class, Elements::EVENT_INVALIDATE_CACHES, $handler);
        }

        return $ids;
    }
}
