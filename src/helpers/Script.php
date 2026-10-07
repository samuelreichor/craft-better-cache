<?php

namespace samuelreichor\betterCache\helpers;

use craft\helpers\Json;

final class Script
{
    /**
     * The scripts in src/web are function expressions, so they get their config without hardcoded names.
     *
     * @param array<string, mixed> $config
     */
    public static function invoke(string $file, array $config): string
    {
        $js = trim((string)file_get_contents(dirname(__DIR__) . '/web/' . $file));

        return $js . '(' . Json::encode($config) . ');';
    }
}
