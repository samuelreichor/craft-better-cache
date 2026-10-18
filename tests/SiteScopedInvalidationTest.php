<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\InvalidateElementCachesEvent;
use craft\models\Site;
use craft\services\Elements;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;

/**
 * The news section does not propagate, so its entries exist on a single site.
 */
class SiteScopedInvalidationTest extends TestCase
{
    private Site $siteA;
    private Site $siteB;

    /** @var array<string, PageVariant> */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings()->enabled = true;
        $this->useTempCachePath();
        $this->settings()->storageDriver = Constants::STORAGE_DRIVER_FILE;
        $this->settings()->refreshStrategy = Constants::REFRESH_STRATEGY_CLEAR;

        $sites = Craft::$app->getSites()->getAllSites();
        if (count($sites) < 2 || Craft::$app->getEntries()->getSectionByHandle('news') === null) {
            $this->markTestSkipped('Two sites and the news section are needed.');
        }
        [$this->siteA, $this->siteB] = $sites;
    }

    public function testSingleSiteQueryTagsCarryTheSite(): void
    {
        $query = Entry::find()->section('news')->siteId($this->siteA->id);
        $query->all();

        $sectionId = Craft::$app->getEntries()->getSectionByHandle('news')->id;
        $this->assertContains('element::' . Entry::class . "::section:$sectionId@site:{$this->siteA->id}", $query->getCacheTags());
        $this->assertNotContains('element::' . Entry::class . "::section:$sectionId", $query->getCacheTags());
    }

    public function testSingleSiteQueryWithoutSectionGetsTheSiteWildcard(): void
    {
        $query = Entry::find()->siteId($this->siteA->id);
        $query->all();

        $this->assertContains('element::' . Entry::class . "::*@site:{$this->siteA->id}", $query->getCacheTags());
        $this->assertNotContains('element::' . Entry::class . '::*', $query->getCacheTags());
    }

    public function testSavingEntryKeepsSectionlessListsOfSitesItIsNotOn(): void
    {
        $entry = $this->createNewsEntry($this->siteA);
        $this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());
        foreach ([$this->siteA, $this->siteB] as $site) {
            $query = Entry::find()->siteId($site->id);
            $query->all();
            $this->store(new PageVariant($site->id, "all-entries-on-$site->handle"), $query->getCacheTags());
        }

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->flushInvalidation();

        $this->assertCleared("all-entries-on-{$this->siteA->handle}");
        $this->assertKept("all-entries-on-{$this->siteB->handle}");
    }

    public function testMultiSiteQueryWithoutSectionKeepsThePlainWildcard(): void
    {
        $query = Entry::find()->siteId('*');
        $query->all();

        $this->assertContains('element::' . Entry::class . '::*', $query->getCacheTags());
    }

    public function testMultiSiteQueryTagsStayPlain(): void
    {
        $query = Entry::find()->section('news')->siteId('*');
        $query->all();

        $sectionId = Craft::$app->getEntries()->getSectionByHandle('news')->id;
        $this->assertContains('element::' . Entry::class . "::section:$sectionId", $query->getCacheTags());
    }

    public function testSavingNonLocalizedElementClearsListsOnEverySite(): void
    {
        $this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());
        $query = User::find()->siteId($this->siteB->id)->admin();
        $query->all();
        $this->store(new PageVariant($this->siteB->id, 'admins-on-b'), $query->getCacheTags());

        $user = new User(['username' => 'better-cache-test', 'email' => 'better-cache-test@example.test']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false));
        $this->flushInvalidation();

        $this->assertCleared('admins-on-b');
    }

    public function testSavingEntryKeepsListsOfSitesItIsNotOn(): void
    {
        $entry = $this->createNewsEntry($this->siteA);
        $this->storeLists();

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->flushInvalidation();

        $this->assertCleared('list-a');
        $this->assertKept('list-b');
    }

    public function testCreatingEntryKeepsListsOfSitesItIsNotOn(): void
    {
        $this->storeLists();

        $this->createNewsEntry($this->siteA);
        $this->flushInvalidation();

        $this->assertCleared('list-a');
        $this->assertKept('list-b');
    }

    public function testSavingEntryClearsMultiSiteListsOnOtherSites(): void
    {
        $entry = $this->createNewsEntry($this->siteA);
        $this->storeLists();

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->flushInvalidation();

        $this->assertCleared('list-all-on-b');
    }

    public function testSavingEntryClearsPagesStoredWithPlainTags(): void
    {
        $entry = $this->createNewsEntry($this->siteA);
        $this->storeLists();

        $entry->title = 'Changed';
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $this->flushInvalidation();

        $this->assertCleared('plain-on-b');
    }

    public function testInvalidationWithoutElementClearsListsOfAllSites(): void
    {
        $this->storeLists();

        $sectionId = Craft::$app->getEntries()->getSectionByHandle('news')->id;
        Craft::$app->getElements()->trigger(Elements::EVENT_INVALIDATE_CACHES, new InvalidateElementCachesEvent([
            'tags' => ['element::' . Entry::class . "::section:$sectionId"],
        ]));
        $this->flushInvalidation();

        $this->assertCleared('list-a');
        $this->assertCleared('list-b');
    }

    public function testAffectedSitesIncludeSitesTheElementLeftDuringItsSave(): void
    {
        $entry = Entry::find()->section('home')->siteId($this->siteA->id)->status(null)->one();
        if ($entry === null || Entry::find()->id($entry->id)->siteId($this->siteB->id)->status(null)->exists() === false) {
            $this->markTestSkipped('The home entry must exist on both sites.');
        }

        $changes = BetterCache::getInstance()->elementChanges;
        $changes->beforeSave($entry);
        Craft::$app->getElements()->deleteElementForSite(Entry::find()->id($entry->id)->siteId($this->siteB->id)->status(null)->one());

        $this->assertEqualsCanonicalizing([$this->siteA->id, $this->siteB->id], $changes->getAffectedSiteIds($entry));
    }

    public function testAffectedSitesAreUnknownOutsideOfASave(): void
    {
        $entry = $this->createNewsEntry($this->siteA);

        $this->assertNull(BetterCache::getInstance()->elementChanges->getAffectedSiteIds($entry));
        $this->assertNull(BetterCache::getInstance()->elementChanges->getAffectedSiteIds(null));
    }

    private function createNewsEntry(Site $site): Entry
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('news');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => $site->id,
            'title' => 'Site scoped test',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($entry, false));

        return $entry;
    }

    /**
     * Stores pages with the tags their news query collects, after dropping the setup's own invalidations.
     */
    private function storeLists(): void
    {
        $this->capturePushedJobs(fn() => BetterCache::getInstance()->invalidation->flush());

        $sectionId = Craft::$app->getEntries()->getSectionByHandle('news')->id;
        $this->store(new PageVariant($this->siteA->id, 'list-a'), $this->newsQueryTags($this->siteA->id));
        $this->store(new PageVariant($this->siteB->id, 'list-b'), $this->newsQueryTags($this->siteB->id));
        $this->store(new PageVariant($this->siteB->id, 'list-all-on-b'), $this->newsQueryTags('*'));
        $this->store(new PageVariant($this->siteB->id, 'plain-on-b'), ['element', 'element::' . Entry::class, 'element::' . Entry::class . "::section:$sectionId"]);
    }

    /**
     * @return string[]
     */
    private function newsQueryTags(int|string $siteId): array
    {
        $query = Entry::find()->section('news')->siteId($siteId);
        // Craft collects the tags after preparing the query, like it does while rendering.
        $query->all();

        return $query->getCacheTags();
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

    private function assertCleared(string $path): void
    {
        $this->assertNull(BetterCache::getInstance()->getStorageDriver()->get($this->pages[$path]), "$path is still stored");
    }

    private function assertKept(string $path): void
    {
        $this->assertNotNull(BetterCache::getInstance()->getStorageDriver()->get($this->pages[$path]), "$path is not stored");
    }
}
