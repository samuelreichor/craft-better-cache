<?php

namespace samuelreichor\betterCache\helpers;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use samuelreichor\betterCache\Constants;

final class CachePath
{
    public static function resolve(string $cachePath): string
    {
        return rtrim(FileHelper::normalizePath(App::parseEnv($cachePath)), '/');
    }

    public static function isSafe(string $cachePath): bool
    {
        $path = self::resolve($cachePath);
        if ($path === '') {
            return false;
        }

        foreach (Constants::PROTECTED_ROOT_ALIASES as $alias) {
            $protected = Craft::getAlias($alias, false);
            if ($protected !== false && str_starts_with(self::resolve($protected) . '/', $path . '/')) {
                return false;
            }
        }

        foreach (Constants::PROTECTED_PATH_ALIASES as $alias) {
            $protected = Craft::getAlias($alias, false);
            if ($protected !== false && self::resolve($protected) === $path) {
                return false;
            }
        }

        return true;
    }
}
