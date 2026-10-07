<?php

namespace samuelreichor\betterCache\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;

final class CachePath
{
    public static function resolve(string $cachePath): string
    {
        return rtrim(FileHelper::normalizePath(App::parseEnv($cachePath)), '/');
    }

    /**
     * A cache path that is the webroot, the project root or a parent of them would delete the site on clear.
     */
    public static function isSafe(string $cachePath): bool
    {
        $path = self::resolve($cachePath);
        if ($path === '') {
            return false;
        }

        foreach (['@webroot', '@root'] as $alias) {
            $protected = Craft::getAlias($alias, false);
            if ($protected !== false && str_starts_with(self::resolve($protected) . '/', $path . '/')) {
                return false;
            }
        }

        return true;
    }
}
