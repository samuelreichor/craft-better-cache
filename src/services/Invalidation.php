<?php

namespace samuelreichor\betterCache\services;

use Craft;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use Throwable;
use yii\base\Application;
use yii\base\Component;

class Invalidation extends Component
{
    /**
     * @var array<string, true>
     */
    private array $tags = [];

    private bool $flushRegistered = false;

    /**
     * Buffers the tags, since one save announces them several times (propagation, nested elements).
     *
     * @param string[] $tags
     */
    public function addTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }

        if (!$this->flushRegistered) {
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->flush());
            $this->flushRegistered = true;
        }
    }

    public function flush(): void
    {
        $tags = array_keys($this->tags);
        $this->tags = [];
        if (!$tags) {
            return;
        }

        $tagIndex = BetterCache::getInstance()->tagIndex;
        $pages = array_intersect(Constants::ALL_PAGES_TAGS, $tags)
            ? $tagIndex->findAllPages()
            : $tagIndex->findPagesByTags($tags);

        $this->clear($pages);
    }

    /**
     * @param array<int, PageVariant> $pages keyed by page ID
     */
    public function clear(array $pages): void
    {
        $plugin = BetterCache::getInstance();
        $deletedIds = [];
        foreach ($pages as $pageId => $variant) {
            // A failed delete keeps its row, so the next Invalidation retries it.
            try {
                $plugin->getStorageDriver()->delete($variant);
                $deletedIds[] = $pageId;
            } catch (Throwable $e) {
                Craft::warning("Could not clear “{$variant->path}”: {$e->getMessage()}", Constants::HANDLE);
            }
        }
        $plugin->tagIndex->deletePages($deletedIds);
    }
}
