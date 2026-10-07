<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\web\View;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\helpers\EntrySidebar;

class EntrySidebarTest extends TestCase
{
    private Entry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $section = Craft::$app->getEntries()->getSectionByHandle('blog');
        if ($section === null) {
            $this->markTestSkipped('The blog section is missing.');
        }
        $this->entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'title' => 'Better Cache sidebar test',
            'slug' => 'better-cache-sidebar-test',
        ]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($this->entry), implode(', ', $this->entry->getErrorSummary(true)));
        if ($this->entry->getUrl() === null) {
            $this->markTestSkipped('Blog entries have no URL.');
        }
        Craft::$app->getUser()->setIdentity(User::find()->admin()->status(null)->one());
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_SITE);
        parent::tearDown();
    }

    public function testHiddenWithoutPermission(): void
    {
        Craft::$app->getUser()->setIdentity(new User(['admin' => false]));

        $this->assertSame('', EntrySidebar::html($this->entry));
    }

    public function testUncachedEntryHasNoClearButton(): void
    {
        $html = EntrySidebar::html($this->entry);

        $this->assertStringContainsString('Not cached', $html);
        $this->assertStringNotContainsString(Constants::SIDEBAR_URL_ATTRIBUTE, $html);
    }

    public function testCachedEntryOffersClearing(): void
    {
        $plugin = BetterCache::getInstance();
        $variant = $plugin->cacheability->getPageVariantForUrl((string)$this->entry->getUrl());
        $plugin->tagIndex->upsertPage($variant, []);

        $html = EntrySidebar::html($this->entry);

        $this->assertStringContainsString('Cached', $html);
        $this->assertStringContainsString(Constants::SIDEBAR_URL_ATTRIBUTE . '="' . $this->entry->getUrl() . '"', $html);
    }
}
