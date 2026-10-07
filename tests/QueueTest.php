<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\jobs\InvalidateTagsJob;
use samuelreichor\betterCache\jobs\RenderPagesJob;
use samuelreichor\betterCache\models\PageVariant;
use yii\queue\PushEvent;
use yii\queue\Queue;

class QueueTest extends TestCase
{
    private const QUEUE_ID = 'betterCacheTestQueue';

    private mixed $originalQueue;

    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app->set(self::QUEUE_ID, ['class' => \craft\queue\Queue::class, 'channel' => 'better-cache-test']);
        $this->originalQueue = BetterCache::getInstance()->queue;
        BetterCache::getInstance()->queue = self::QUEUE_ID;
    }

    protected function tearDown(): void
    {
        BetterCache::getInstance()->queue = $this->originalQueue;
        Craft::$app->clear(self::QUEUE_ID);
        parent::tearDown();
    }

    public function testJobsArePushedToTheConfiguredQueue(): void
    {
        $plugin = BetterCache::getInstance();
        $pushed = [];
        Craft::$app->get(self::QUEUE_ID)->on(Queue::EVENT_AFTER_PUSH, function(PushEvent $event) use (&$pushed) {
            $pushed[] = $event->job;
        });

        $defaultJobs = $this->capturePushedJobs(function() use ($plugin) {
            $plugin->invalidation->addTags(['element::5']);
            $plugin->invalidation->flush();
            $plugin->warming->render([1 => new PageVariant($this->siteWithUrl()->id, 'queue-test')]);
        });

        $this->assertSame([], $defaultJobs);
        $this->assertCount(2, $pushed);
        $this->assertInstanceOf(InvalidateTagsJob::class, $pushed[0]);
        $this->assertInstanceOf(RenderPagesJob::class, $pushed[1]);
    }
}
