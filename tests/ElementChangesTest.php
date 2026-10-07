<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use samuelreichor\betterCache\BetterCache;

class ElementChangesTest extends TestCase
{
    public function testNewEntryInvalidates(): void
    {
        $this->createEntry();

        $this->assertTrue($this->flushQueuedJob());
    }

    public function testUnchangedSaveDoesNotInvalidate(): void
    {
        $entry = $this->reload($this->createEntry());
        $this->flushQueuedJob();

        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertFalse($this->flushQueuedJob());
    }

    public function testChangedTitleInvalidates(): void
    {
        $entry = $this->reload($this->createEntry());
        $this->flushQueuedJob();

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertTrue($this->flushQueuedJob());
    }

    public function testEditingDisabledEntryDoesNotInvalidate(): void
    {
        $entry = $this->reload($this->createEntry(false));
        $this->flushQueuedJob();

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertFalse($this->flushQueuedJob());
    }

    public function testEnablingEntryInvalidates(): void
    {
        $entry = $this->reload($this->createEntry(false));
        $this->flushQueuedJob();

        $entry->enabled = true;
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertTrue($this->flushQueuedJob());
    }

    public function testDisablingEntryInvalidates(): void
    {
        $entry = $this->reload($this->createEntry());
        $this->flushQueuedJob();

        $entry->enabled = false;
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $this->assertTrue($this->flushQueuedJob());
    }

    public function testInvalidatingElementThatIsNotBeingSavedInvalidates(): void
    {
        $entry = $this->reload($this->createEntry());
        $this->flushQueuedJob();

        Craft::$app->getElements()->invalidateCachesForElement($entry);

        $this->assertTrue($this->flushQueuedJob());
    }

    public function testSavingDraftDoesNotInvalidate(): void
    {
        $draft = Craft::$app->getDrafts()->createDraft($this->reload($this->createEntry()));
        $this->flushQueuedJob();

        $draft->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));

        $this->assertFalse($this->flushQueuedJob());
    }

    public function testApplyingUnchangedDraftDoesNotInvalidate(): void
    {
        $entry = $this->reload($this->createEntry());
        $draft = Craft::$app->getDrafts()->createDraft($entry);
        $this->flushQueuedJob();

        Craft::$app->getDrafts()->applyDraft($draft);

        $this->assertFalse($this->flushQueuedJob());
    }

    public function testApplyingChangedDraftInvalidates(): void
    {
        $entry = $this->reload($this->createEntry());
        $draft = Craft::$app->getDrafts()->createDraft($entry);
        $draft->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));
        $this->flushQueuedJob();

        Craft::$app->getDrafts()->applyDraft($draft);

        $this->assertTrue($this->flushQueuedJob());
    }

    private function createEntry(bool $enabled = true): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        if ($section === null) {
            $this->markTestSkipped('The blog section is missing.');
        }

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Better Cache test',
            'enabled' => $enabled,
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));

        return $entry;
    }

    private function reload(Entry $entry): Entry
    {
        return Entry::find()->id($entry->id)->status(null)->one();
    }

    /**
     * @return bool whether the buffered tags queued an invalidation
     */
    private function flushQueuedJob(): bool
    {
        return (bool)$this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());
    }
}
