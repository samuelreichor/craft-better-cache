<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;

/**
 * Each page stores only the tags of its owner-restricted Matrix query, so these tests fail if a block change
 * reaches its owner's page through no other tag.
 */
class NestedEntryInvalidationTest extends TestCase
{
    private FieldInterface $field;

    /** @var array<string, PageVariant> */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->enabled = true;
        $this->useTempCachePath();
        $this->settings()->storageDriver = Constants::STORAGE_DRIVER_FILE;
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_CLEAR;

        $field = Craft::$app->getFields()->getFieldByHandle('blocks');
        if ($field === null || Craft::$app->getEntries()->getSectionByHandle('blog') === null) {
            $this->markTestSkipped('The blocks Matrix field or the blog section is missing.');
        }
        $this->field = $field;
    }

    public function testSavingBlockClearsOnlyItsOwnersPage(): void
    {
        $a = $this->createOwner('a');
        $block = $this->createBlock($a);
        $this->createOwner('b');
        $this->createBlock(Entry::find()->slug('b')->one());
        $this->storePages();

        $block->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($block));
        $this->flushInvalidation();

        $this->assertCleared('a');
        $this->assertKept('b');
    }

    public function testCreatingBlockClearsItsOwnersPage(): void
    {
        $a = $this->createOwner('a');
        $this->storePages();

        $this->createBlock($a);
        $this->flushInvalidation();

        $this->assertCleared('a');
    }

    public function testDeletingBlockClearsItsOwnersPage(): void
    {
        $block = $this->createBlock($this->createOwner('a'));
        $this->storePages();

        $this->assertTrue(Craft::$app->getElements()->deleteElement($block));
        $this->flushInvalidation();

        $this->assertCleared('a');
    }

    public function testSavingBlockOfDuplicatedOwnerClearsTheDuplicatesPage(): void
    {
        $this->createBlock($this->createOwner('a'));
        $b = Craft::$app->getElements()->duplicateElement(Entry::find()->slug('a')->one(), ['slug' => 'b']);
        $this->storePages();

        $block = Entry::find()->fieldId($this->field->id)->ownerId($b->id)->one();
        $block->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($block));
        $this->flushInvalidation();

        $this->assertCleared('b');
    }

    private function createOwner(string $slug): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        $owner = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => $slug,
            'slug' => $slug,
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($owner, false));

        return $owner;
    }

    private function createBlock(Entry $owner): Entry
    {
        $block = new Entry([
            'fieldId' => $this->field->id,
            'ownerId' => $owner->id,
            'siteId' => $owner->siteId,
            'typeId' => Craft::$app->getEntries()->getEntryTypeByHandle('block')->id,
            'title' => 'Block',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($block, false));

        return $block;
    }

    /**
     * Stores a page per owner with the tags its block query collects, after dropping the setup's own invalidations.
     */
    private function storePages(): void
    {
        $this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());

        $siteId = $this->siteWithUrl()->id;
        foreach (Entry::find()->section('blog')->slug(['a', 'b'])->all() as $owner) {
            $query = Entry::find()->fieldId($this->field->id)->ownerId($owner->id);
            // Craft normalizes the owner and field IDs only when the query runs, like it does while rendering.
            $query->all();
            $tags = $query->getCacheTags();
            $this->pages[$owner->slug] = new PageVariant($siteId, $owner->slug);
            BetterCache::getInstance()->tagIndex->upsertPage($this->pages[$owner->slug], $tags);
            BetterCache::getInstance()->getStorageDriver()->save($this->pages[$owner->slug], $owner->slug);
        }
    }

    private function assertCleared(string $slug): void
    {
        $this->assertNull(BetterCache::getInstance()->getStorageDriver()->get($this->pages[$slug]), "$slug is still stored");
    }

    private function assertKept(string $slug): void
    {
        $this->assertNotNull(BetterCache::getInstance()->getStorageDriver()->get($this->pages[$slug]), "$slug is not stored");
    }
}
