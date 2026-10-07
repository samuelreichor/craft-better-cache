<?php

namespace samuelreichor\betterCache\tests;

use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\jobs\RenderPagesJob;
use samuelreichor\betterCache\models\PageVariant;

class WarmingTest extends TestCase
{
    public function testWarmQueuesKnownVariantsWithTheirIds(): void
    {
        $site = $this->siteWithUrl();
        $this->settings()->includedUriPatterns = ['*' => ['.*']];
        $variant = new PageVariant($site->id, 'blog', 'page=2');
        $pageId = BetterCache::getInstance()->tagIndex->upsertPage($variant, ['element']);

        $pages = $this->warmedPages();

        $url = BetterCache::getInstance()->warming->getUrl($variant);
        $this->assertStringEndsWith('/blog?page=2', $url);
        $this->assertEquals([$pageId, $variant], $pages[$url] ?? null);
    }

    public function testWarmSplitsPagesIntoBatches(): void
    {
        $site = $this->siteWithUrl();
        $tagIndex = BetterCache::getInstance()->tagIndex;
        $expected = [];
        for ($i = 0; $i <= Constants::RENDER_BATCH_SIZE; $i++) {
            $variant = new PageVariant($site->id, "batch-$i");
            $expected[$tagIndex->upsertPage($variant, [])] = $variant;
        }

        $jobs = $this->capturePushedJobs(fn() => BetterCache::getInstance()->warming->warm());

        $this->assertGreaterThan(1, count($jobs));
        $rendered = [];
        foreach ($jobs as $job) {
            $this->assertLessThanOrEqual(Constants::RENDER_BATCH_SIZE, count($job->pages));
            foreach ($job->pages as [$pageId, $variant]) {
                $rendered[$pageId] = $variant;
            }
        }
        foreach ($expected as $pageId => $variant) {
            $this->assertEquals($variant, $rendered[$pageId] ?? null);
        }
    }

    public function testWarmSkipsElementUrisThatAreNotIncluded(): void
    {
        $this->settings()->includedUriPatterns = [];

        foreach ($this->warmedPages() as [$pageId]) {
            $this->assertNotNull($pageId);
        }
    }

    /**
     * @return array<string, array{int|null, PageVariant}> pages of all pushed jobs, keyed by URL
     */
    private function warmedPages(): array
    {
        $pages = [];
        foreach ($this->capturePushedJobs(fn() => BetterCache::getInstance()->warming->warm()) as $job) {
            $this->assertInstanceOf(RenderPagesJob::class, $job);
            $pages += $job->pages;
        }

        return $pages;
    }
}
