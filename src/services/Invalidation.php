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

        $this->apply($pages);
    }

    /**
     * @param array<int, PageVariant> $pages keyed by page ID
     */
    public function apply(array $pages): void
    {
        $plugin = BetterCache::getInstance();
        $byStrategy = [];
        foreach ($pages as $pageId => $variant) {
            $byStrategy[$plugin->cacheability->getRefreshStrategy($variant)][$pageId] = $variant;
        }

        $this->clear($byStrategy[Constants::REFRESH_STRATEGY_CLEAR] ?? []);

        $expired = $byStrategy[Constants::REFRESH_STRATEGY_EXPIRE] ?? [];
        $plugin->tagIndex->markStale(array_keys($expired));
        $plugin->warming->render($expired);

        $refreshed = $byStrategy[Constants::REFRESH_STRATEGY_REFRESH] ?? [];
        $this->deleteFiles($refreshed);
        $plugin->warming->render($refreshed);
    }

    /**
     * @param array<int, PageVariant> $pages keyed by page ID
     */
    public function clear(array $pages): void
    {
        BetterCache::getInstance()->tagIndex->deletePages($this->deleteFiles($pages));
    }

    /**
     * A failed delete is left out of the returned IDs, so its row stays and the next Invalidation retries it.
     *
     * @param array<int, PageVariant> $pages keyed by page ID
     * @return int[] IDs of the pages whose file is gone
     */
    private function deleteFiles(array $pages): array
    {
        $driver = BetterCache::getInstance()->getStorageDriver();
        $deletedIds = [];
        foreach ($pages as $pageId => $variant) {
            try {
                $driver->delete($variant);
                $deletedIds[] = $pageId;
            } catch (Throwable $e) {
                Craft::warning("Could not clear “{$variant->path}”: {$e->getMessage()}", Constants::HANDLE);
            }
        }

        return $deletedIds;
    }
}
