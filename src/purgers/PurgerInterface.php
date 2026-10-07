<?php

namespace samuelreichor\betterCache\purgers;

/**
 * Tells a CDN to drop its copies. Tags arrive in the same normalized form as the tag header.
 */
interface PurgerInterface
{
    /**
     * @param string[] $tags
     */
    public function purgeTags(array $tags): void;

    /**
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): void;

    public function purgeAll(): void;
}
