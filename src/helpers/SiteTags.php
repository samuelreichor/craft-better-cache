<?php

namespace samuelreichor\betterCache\helpers;

use samuelreichor\betterCache\Constants;

final class SiteTags
{
    public static function scope(string $tag, int $siteId): string
    {
        return $tag . Constants::SITE_TAG_SEPARATOR . $siteId;
    }

    /**
     * Element ID and element type tags have no site variant, only query tags like `element::{type}::section:1` do.
     *
     * @param string[] $tags
     * @param int[] $siteIds
     * @return string[]
     */
    public static function withSiteVariants(array $tags, array $siteIds): array
    {
        $variants = [];
        foreach ($tags as $tag) {
            if (substr_count($tag, '::') < 2) {
                continue;
            }
            foreach ($siteIds as $siteId) {
                $variants[] = self::scope($tag, $siteId);
            }
        }

        return [...$tags, ...$variants];
    }
}
