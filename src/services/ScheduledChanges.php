<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use DateTime;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use yii\base\Component;

class ScheduledChanges extends Component
{
    /**
     * Invalidates entries whose postDate or expiryDate passed since the last check.
     *
     * @return int the number of invalidated entries
     */
    public function invalidatePassed(): int
    {
        $cache = Craft::$app->getCache();
        $now = new DateTime();
        $lastCheck = $cache->get(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY);

        // Craft's cache is flushed on most deploys. Any page rendered before a passed date may be stale,
        // so the oldest render is a safe lower bound.
        $since = $lastCheck !== false
            ? (new DateTime())->setTimestamp((int)$lastCheck)
            : BetterCache::getInstance()->tagIndex->findOldestUpdate();

        $count = $since === null ? 0 : $this->invalidateBetween($since, $now);
        $cache->set(Constants::LAST_SCHEDULED_CHECK_CACHE_KEY, $now->getTimestamp());

        return $count;
    }

    private function invalidateBetween(DateTime $since, DateTime $now): int
    {
        $since = Db::prepareDateForDb($since);
        $until = Db::prepareDateForDb($now);
        $ids = (new Query())
            ->select('id')
            ->from(Table::ENTRIES)
            ->where(['between', 'postDate', $since, $until])
            ->orWhere(['between', 'expiryDate', $since, $until])
            ->column();

        if (!$ids) {
            return 0;
        }

        $entries = Entry::find()->id($ids)->site('*')->unique()->status(null)->all();
        foreach ($entries as $entry) {
            Craft::$app->getElements()->invalidateCachesForElement($entry);
        }

        return count($entries);
    }
}
