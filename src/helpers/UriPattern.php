<?php

namespace samuelreichor\betterCache\helpers;

use samuelreichor\betterCache\Constants;

final class UriPattern
{
    public static function toRegex(string $pattern): string
    {
        $delimiter = Constants::URI_PATTERN_DELIMITER;
        $escaped = preg_replace('/(?<!\\\\)' . preg_quote($delimiter, '/') . '/', '\\' . $delimiter, $pattern);

        return $delimiter . $escaped . $delimiter;
    }

    public static function isValid(string $pattern): bool
    {
        return @preg_match(self::toRegex($pattern), '') !== false;
    }
}
