<?php

namespace samuelreichor\betterCache\utilities;

use Craft;
use craft\base\Utility;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

class BetterCacheUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t(Constants::HANDLE, 'Better Cache');
    }

    public static function id(): string
    {
        return Constants::UTILITY_ID;
    }

    public static function icon(): ?string
    {
        return 'bolt';
    }

    public static function contentHtml(): string
    {
        $counts = BetterCache::getInstance()->tagIndex->countPagesBySite();
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = [
                'name' => $site->getName(),
                'total' => $counts[$site->id]['total'] ?? 0,
                'stale' => $counts[$site->id]['stale'] ?? 0,
            ];
        }

        return Craft::$app->getView()->renderTemplate(Constants::HANDLE . '/_utility.twig', [
            'sites' => $sites,
            'actions' => Constants::UTILITY_ACTIONS,
        ]);
    }
}
