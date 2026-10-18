<?php

namespace samuelreichor\betterCache\helpers;

use samuelreichor\betterCache\Constants;

final class SiteRows
{
    /**
     * @param array<string, string[]> $patternsBySite
     * @return array<array{site: string, pattern: string}>
     */
    public static function fromPatterns(array $patternsBySite): array
    {
        $rows = [];
        foreach ($patternsBySite as $site => $patterns) {
            foreach ($patterns as $pattern) {
                $rows[] = ['site' => (string)$site, 'pattern' => $pattern];
            }
        }

        return $rows;
    }

    /**
     * @param array<array<string, string>> $rows
     * @return array<string, string[]>
     */
    public static function toPatterns(array $rows): array
    {
        $patternsBySite = [];
        foreach ($rows as $row) {
            $pattern = trim($row['pattern'] ?? '');
            if ($pattern !== '') {
                // Single-site installs hide the site column.
                $patternsBySite[$row['site'] ?? Constants::ALL_SITES_KEY][] = $pattern;
            }
        }

        return $patternsBySite;
    }
}
