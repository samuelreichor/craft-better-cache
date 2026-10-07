<?php

namespace samuelreichor\betterCache\console\controllers;

use Craft;
use craft\console\Controller;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
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
        $this->stdout(Craft::t(Constants::HANDLE, Constants::MESSAGE_PAGES_INVALIDATED, ['count' => $count]) . "\n");

        return ExitCode::OK;
    }

    /**
     * Deletes every Cached Page and the Tag Index.
     */
    public function actionClear(): int
    {
        BetterCache::getInstance()->invalidation->clearAll();
        $this->stdout(Craft::t(Constants::HANDLE, Constants::MESSAGE_CACHE_CLEARED) . "\n");

        return ExitCode::OK;
    }

    /**
     * Deletes the Cached Page of one URL.
     */
    public function actionClearUrl(string $url): int
    {
        if (!BetterCache::getInstance()->invalidation->clearUrl($url)) {
            $this->stderr(Craft::t(Constants::HANDLE, Constants::MESSAGE_NO_SITE_FOR_URL, ['url' => $url]) . "\n");
            return ExitCode::DATAERR;
        }
        $this->stdout(Craft::t(Constants::HANDLE, Constants::MESSAGE_URL_CLEARED, ['url' => $url]) . "\n");

        return ExitCode::OK;
    }

    /**
     * Queues a render of all live element URLs and known Page Variants.
     */
    public function actionWarm(): int
    {
        BetterCache::getInstance()->warming->warm();
        $this->stdout(Craft::t(Constants::HANDLE, Constants::MESSAGE_WARMING_QUEUED) . "\n");

        return ExitCode::OK;
    }

    /**
     * Invalidates entries whose post or expiry date passed since the last run. Run it from cron.
     */
    public function actionRefreshScheduled(): int
    {
        $count = BetterCache::getInstance()->scheduledChanges->invalidatePassed();
        $this->stdout("$count entries with passed dates invalidated.\n");

        return ExitCode::OK;
    }
}
