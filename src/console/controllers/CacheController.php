<?php

namespace samuelreichor\betterCache\console\controllers;

use craft\console\Controller;
use samuelreichor\betterCache\BetterCache;
use yii\console\ExitCode;

/**
 * Manages Cached Pages.
 */
class CacheController extends Controller
{
    /**
     * Applies the Refresh Strategy to every Cached Page, e.g. after a deploy.
     */
    public function actionInvalidate(): int
    {
        $count = BetterCache::getInstance()->invalidation->invalidateAll();
        $this->stdout("$count pages invalidated.\n");

        return ExitCode::OK;
    }

    /**
     * Deletes every Cached Page and the Tag Index.
     */
    public function actionClear(): int
    {
        BetterCache::getInstance()->invalidation->clearAll();
        $this->stdout("Cache cleared.\n");

        return ExitCode::OK;
    }

    /**
     * Deletes the Cached Page of one URL.
     */
    public function actionClearUrl(string $url): int
    {
        if (!BetterCache::getInstance()->invalidation->clearUrl($url)) {
            $this->stderr("No site matches $url.\n");
            return ExitCode::DATAERR;
        }
        $this->stdout("Cleared $url.\n");

        return ExitCode::OK;
    }

    /**
     * Queues a render of all live element URLs and known Page Variants.
     */
    public function actionWarm(): int
    {
        BetterCache::getInstance()->warming->warm();
        $this->stdout("Warming queued.\n");

        return ExitCode::OK;
    }
}
