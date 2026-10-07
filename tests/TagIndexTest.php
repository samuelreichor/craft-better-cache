<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use craft\db\Query;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use samuelreichor\betterCache\services\TagIndex;

class TagIndexTest extends TestCase
{
    private TagIndex $tagIndex;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tagIndex = BetterCache::getInstance()->tagIndex;
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    }

    public function testUpsertTwiceYieldsOneRow(): void
    {
        $variant = new PageVariant($this->siteId, 'blog/hello');

        $firstId = $this->tagIndex->upsertPage($variant, ['a']);
        $secondId = $this->tagIndex->upsertPage($variant, ['b']);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, (int)(new Query())->from(Constants::TABLE_PAGES)->where(['id' => $firstId])->count());
        $this->assertSame([], $this->tagIndex->findPagesByTags(['a']));
        $this->assertArrayHasKey($firstId, $this->tagIndex->findPagesByTags(['b']));
    }

    public function testQueryDistinguishesVariants(): void
    {
        $plainId = $this->tagIndex->upsertPage(new PageVariant($this->siteId, 'blog'), ['a']);
        $pagedId = $this->tagIndex->upsertPage(new PageVariant($this->siteId, 'blog', 'page=2'), ['a']);

        $this->assertNotSame($plainId, $pagedId);
    }

    public function testLookupByAnyTagReturnsPage(): void
    {
        $variant = new PageVariant($this->siteId, 'blog', 'page=2');
        $pageId = $this->tagIndex->upsertPage($variant, ['element', 'element::section:1', 'element::42']);
        $otherId = $this->tagIndex->upsertPage(new PageVariant($this->siteId, 'about'), ['element::7']);

        foreach (['element', 'element::section:1', 'element::42'] as $tag) {
            $pages = $this->tagIndex->findPagesByTags([$tag]);
            $this->assertEquals($variant, $pages[$pageId] ?? null, $tag);
            $this->assertArrayNotHasKey($otherId, $pages, $tag);
        }
        $pages = $this->tagIndex->findPagesByTags(['element::42', 'element::7']);
        $this->assertArrayHasKey($pageId, $pages);
        $this->assertArrayHasKey($otherId, $pages);
    }

    public function testFindPageId(): void
    {
        $variant = new PageVariant($this->siteId, 'blog', 'page=2');
        $pageId = $this->tagIndex->upsertPage($variant, []);

        $this->assertSame($pageId, $this->tagIndex->findPageId($variant));
        $this->assertNull($this->tagIndex->findPageId(new PageVariant($this->siteId, 'blog', 'page=3')));
    }

    public function testUpsertMarksStalePageFresh(): void
    {
        $variant = new PageVariant($this->siteId, 'blog');
        $pageId = $this->tagIndex->upsertPage($variant, ['a']);
        Craft::$app->getDb()->createCommand()->update(Constants::TABLE_PAGES, ['stale' => true], ['id' => $pageId])->execute();

        $this->tagIndex->upsertPage($variant, ['a']);

        $this->assertSame(0, (int)(new Query())->select('stale')->from(Constants::TABLE_PAGES)->where(['id' => $pageId])->scalar());
    }
}
