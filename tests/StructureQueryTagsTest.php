<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;

/**
 * Uses the pages section (a structure) and the news section (a channel) of the showcase.
 */
class StructureQueryTagsTest extends TestCase
{
    private const WILDCARD_TAG = 'element::craft\elements\Entry::*';

    private Entry $page;
    private int $pagesSectionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->enabled = true;
        $this->useTempCachePath();
        $this->settings()->storageDriver = Constants::STORAGE_DRIVER_FILE;
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_CLEAR;

        $section = Craft::$app->getEntries()->getSectionByHandle('pages');
        $page = $section ? Entry::find()->sectionId($section->id)->one() : null;
        if ($page === null || Craft::$app->getEntries()->getSectionByHandle('news') === null) {
            $this->markTestSkipped('The pages section with an entry and the news section are needed.');
        }
        $this->page = $page;
        $this->pagesSectionId = $section->id;
    }

    public function testStructureParamsWithoutSectionAreTaggedWithTheSection(): void
    {
        foreach ([
            Entry::find()->ancestorOf($this->page),
            Entry::find()->descendantOf($this->page),
            Entry::find()->siblingOf($this->page),
        ] as $query) {
            $tags = $this->tagsOf($query->siteId($this->page->siteId));
            $this->assertContains("element::craft\\elements\\Entry::section:$this->pagesSectionId@site:{$this->page->siteId}", $tags);
            $this->assertNotContains(self::WILDCARD_TAG . "@site:{$this->page->siteId}", $tags);
        }
    }

    public function testElementStructureMethodsAreTaggedWithTheSection(): void
    {
        $tags = $this->tagsOf($this->page->getSiblings());

        $this->assertContains("element::craft\\elements\\Entry::section:$this->pagesSectionId@site:{$this->page->siteId}", $tags);
        $this->assertNotContains(self::WILDCARD_TAG . "@site:{$this->page->siteId}", $tags);
    }

    public function testStructureIdWithoutSectionIsTaggedWithTheSection(): void
    {
        $tags = $this->tagsOf(Entry::find()->structureId($this->page->structureId)->siteId('*'));

        $this->assertContains("element::craft\\elements\\Entry::section:$this->pagesSectionId", $tags);
        $this->assertNotContains(self::WILDCARD_TAG, $tags);
    }

    public function testSavingEntryOfAnotherSectionKeepsStructureNavigation(): void
    {
        $plugin = BetterCache::getInstance();
        $this->capturePushedJobs(fn() => $plugin->invalidation->flush());
        $navigation = new PageVariant($this->page->siteId, 'navigation');
        $plugin->tagIndex->upsertPage($navigation, $this->tagsOf($this->page->getSiblings()));
        $plugin->getStorageDriver()->save($navigation, 'navigation');

        $this->createEntry('news');
        $this->flushInvalidation();
        $this->assertNotNull($plugin->getStorageDriver()->get($navigation), 'saving a news entry cleared the page navigation');

        $this->createEntry('pages');
        $this->flushInvalidation();
        $this->assertNull($plugin->getStorageDriver()->get($navigation), 'saving a page kept the page navigation');
    }

    private function createEntry(string $sectionHandle): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => $this->page->siteId,
            'title' => 'Structure tags test',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry, false));
    }

    /**
     * @return string[]
     */
    private function tagsOf(mixed $query): array
    {
        $query->all();

        return $query->getCacheTags();
    }
}
