<?php

namespace samuelreichor\betterCache\services;

use Craft;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\jobs\InvalidateTagsJob;
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
     * They are resolved on the queue, so saving content does not wait for pages to be cleared or purged.
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

        BetterCache::getInstance()->getQueue()->push(new InvalidateTagsJob(['tags' => $tags]));
    }

    /**
     * @param string[] $tags
     */
    public function invalidateTags(array $tags): void
    {
        // Re-renders go through the public URL, so the CDN has to forget the page before they run.
        $plugin = BetterCache::getInstance();
        if (array_intersect(Constants::ALL_PAGES_TAGS, $tags)) {
            $plugin->cdn->purgeAll();
            $this->apply($plugin->tagIndex->findAllPages());
        } else {
            $plugin->cdn->purgeTags($tags);
            $this->apply($plugin->tagIndex->findPagesByTags($tags));
        }
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
     * @return int the number of invalidated pages
     */
    public function invalidateAll(): int
    {
        $pages = BetterCache::getInstance()->tagIndex->findAllPages();
        BetterCache::getInstance()->cdn->purgeAll();
        $this->apply($pages);

        return count($pages);
    }

    /**
     * Removes all stored files, including ones the Tag Index does not know.
     */
    public function clearAll(): void
    {
        $plugin = BetterCache::getInstance();
        $plugin->getStorageDriver()->deleteAll();
        $plugin->tagIndex->deleteAllPages();
        $plugin->cdn->purgeAll();
    }

    /**
     * Runs before a site is deleted, while its pages can still be resolved to files.
     */
    public function clearSite(int $siteId): void
    {
        $plugin = BetterCache::getInstance();
        $this->clear($plugin->tagIndex->findPagesBySite($siteId));
        $plugin->cdn->purgeAll();
    }

    /**
     * @return bool whether the URL belongs to a site
     */
    public function clearUrl(string $url): bool
    {
        $plugin = BetterCache::getInstance();
        $variant = $plugin->cacheability->getPageVariantForUrl($url);
        if ($variant === null) {
            return false;
        }

        $pageId = $plugin->tagIndex->findPageId($variant);
        if ($pageId === null) {
            $plugin->getStorageDriver()->delete($variant);
        } else {
            $this->clear([$pageId => $variant]);
        }
        $plugin->cdn->purgeUrls([$url]);

        return true;
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
