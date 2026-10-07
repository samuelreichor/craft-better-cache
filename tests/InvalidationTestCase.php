<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\db\Query;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\jobs\InvalidateTagsJob;
use samuelreichor\betterCache\jobs\RenderPagesJob;
use samuelreichor\betterCache\models\PageVariant;
use yii\queue\ExecEvent;
use yii\queue\Queue;

abstract class InvalidationTestCase extends TestCase
{
    protected const ENTRY = 'element::' . Entry::class;

    /** @var array<string, PageVariant> */
    protected array $pages = [];

    abstract protected function storageDriver(): string;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTempCachePath();
        $this->settings()->storageDriver = $this->storageDriver();
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_CLEAR;

        $sections = Craft::$app->getEntries();
        $blogId = $sections->getSectionByHandle('blog')?->id;
        $newsId = $sections->getSectionByHandle('news')?->id;
        if (!$blogId || !$newsId) {
            $this->markTestSkipped('The blog and news sections are missing.');
        }

        $siteId = $this->siteWithUrl()->id;
        $this->store(new PageVariant($siteId, 'blog'), ['element', self::ENTRY, self::ENTRY . "::section:$blogId"]);
        $this->store(new PageVariant($siteId, 'news'), ['element', self::ENTRY, self::ENTRY . "::section:$newsId"]);
        $this->store(new PageVariant($siteId, 'static'), []);
    }

    public function testSavingEntryClearsOnlyPagesOfItsSection(): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Better Cache test',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry, false));

        $this->flushInvalidation();

        $this->assertCleared('blog');
        $this->assertKept('news');
        $this->assertKept('static');
    }

    public function testSavingEntryOnlyQueuesOneJob(): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Better Cache test',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry, false));

        $jobs = $this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(InvalidateTagsJob::class, $jobs[0]);
        $this->assertSame(array_unique($jobs[0]->tags), $jobs[0]->tags);
        $this->assertKept('blog');
    }

    public function testRepeatedTagsAreResolvedOnFlush(): void
    {
        $invalidation = BetterCache::getInstance()->invalidation;
        $invalidation->addTags(['element::unrelated']);
        $invalidation->addTags([self::ENTRY]);
        $invalidation->addTags([self::ENTRY]);
        $this->assertKept('blog');

        $this->flushInvalidation();

        $this->assertCleared('blog');
        $this->assertCleared('news');
        $this->assertKept('static');
    }

    public function testTagsAreResolvedAfterEachQueueJob(): void
    {
        BetterCache::getInstance()->invalidation->addTags([self::ENTRY]);

        $jobs = $this->capturePushedJobs(fn() => Craft::$app->getQueue()->trigger(Queue::EVENT_AFTER_EXEC, new ExecEvent()));

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(InvalidateTagsJob::class, $jobs[0]);
        $this->assertSame([self::ENTRY], $jobs[0]->tags);
    }

    public function testExpireKeepsPageMarksStaleAndQueuesRender(): void
    {
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_EXPIRE;

        $job = $this->invalidateAndCaptureJob([self::ENTRY . '::section:' . Craft::$app->getEntries()->getSectionByHandle('blog')->id]);

        $this->assertKept('blog');
        $this->assertTrue($this->isStale('blog'));
        $this->assertFalse($this->isStale('news'));
        $this->assertJobRenders($job, 'blog');
    }

    public function testRefreshDeletesPageKeepsRowAndQueuesRender(): void
    {
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_REFRESH;

        $job = $this->invalidateAndCaptureJob([self::ENTRY . '::section:' . Craft::$app->getEntries()->getSectionByHandle('blog')->id]);

        $this->assertFalse($this->isStored('blog'));
        $this->assertTrue($this->isIndexed('blog'));
        $this->assertKept('news');
        $this->assertJobRenders($job, 'blog');
    }

    public function testClearAllRemovesRowsAndPages(): void
    {
        BetterCache::getInstance()->invalidation->clearAll();

        $this->assertSame([], BetterCache::getInstance()->tagIndex->findAllPages());
        $this->assertFalse($this->isStored('static'));
    }

    public function testClearUrlRemovesOnlyThatPage(): void
    {
        $url = BetterCache::getInstance()->warming->getUrl($this->pages['blog']);

        $this->assertTrue(BetterCache::getInstance()->invalidation->clearUrl($url));
        $this->assertFalse(BetterCache::getInstance()->invalidation->clearUrl('https://unknown.example/blog'));

        $this->assertCleared('blog');
        $this->assertKept('news');
    }

    public function testInvalidateAllAppliesTheStrategy(): void
    {
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_EXPIRE;

        BetterCache::getInstance()->invalidation->invalidateAll();

        $this->assertKept('static');
        $this->assertTrue($this->isStale('static'));
    }

    public function testClearSiteRemovesOnlyThatSitesPages(): void
    {
        $otherSite = null;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ($site->id !== $this->pages['blog']->siteId) {
                $otherSite = $site;
            }
        }
        if ($otherSite === null) {
            $this->markTestSkipped('Needs a second site.');
        }
        $this->pages['other'] = new PageVariant($otherSite->id, 'other');
        BetterCache::getInstance()->tagIndex->upsertPage($this->pages['other'], []);

        BetterCache::getInstance()->invalidation->clearSite($this->pages['blog']->siteId);

        $this->assertCleared('blog');
        $this->assertCleared('static');
        $this->assertTrue($this->isIndexed('other'));
    }

    public function testBareElementTagClearsAllPages(): void
    {
        Craft::$app->getElements()->invalidateAllCaches();
        $this->flushInvalidation();

        $this->assertCleared('blog');
        $this->assertCleared('news');
        $this->assertCleared('static');
    }

    public function testSavingGlobalsClearsAllPages(): void
    {
        Craft::$app->getElements()->invalidateCachesForElement(new GlobalSet(['id' => PHP_INT_MAX]));
        $this->flushInvalidation();

        $this->assertCleared('blog');
        $this->assertCleared('news');
        $this->assertCleared('static');
    }

    /**
     * @param string[] $tags
     */
    private function invalidateAndCaptureJob(array $tags): RenderPagesJob
    {
        BetterCache::getInstance()->invalidation->addTags($tags);
        $jobs = $this->flushInvalidation();

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(RenderPagesJob::class, $jobs[0]);

        return $jobs[0];
    }

    private function assertJobRenders(RenderPagesJob $job, string $path): void
    {
        $variants = array_map(fn(array $page) => $page[1], $job->pages);
        $this->assertContainsEquals($this->pages[$path], $variants);
        $this->assertNotContainsEquals($this->pages['news'], $variants);
        foreach ($job->pages as [$pageId]) {
            $this->assertNotNull($pageId);
        }
    }

    private function isStale(string $path): bool
    {
        return (bool)(new Query())
            ->select('stale')
            ->from(Constants::TABLE_PAGES)
            ->where(['siteId' => $this->pages[$path]->siteId, 'variantHash' => $this->pages[$path]->getHash()])
            ->scalar();
    }

    /**
     * @param string[] $tags
     */
    private function store(PageVariant $variant, array $tags): void
    {
        $plugin = BetterCache::getInstance();
        $plugin->tagIndex->upsertPage($variant, $tags);
        $plugin->getStorageDriver()->save($variant, $variant->path);
        $this->pages[$variant->path] = $variant;
    }

    protected function assertCleared(string $path): void
    {
        $this->assertFalse($this->isIndexed($path), "$path is still indexed");
        $this->assertFalse($this->isStored($path), "$path is still stored");
    }

    protected function assertKept(string $path): void
    {
        $this->assertTrue($this->isIndexed($path), "$path is not indexed");
        $this->assertTrue($this->isStored($path), "$path is not stored");
    }

    private function isStored(string $path): bool
    {
        return BetterCache::getInstance()->getStorageDriver()->get($this->pages[$path]) !== null;
    }

    protected function isIndexed(string $path): bool
    {
        return in_array($this->pages[$path], BetterCache::getInstance()->tagIndex->findAllPages());
    }
}
