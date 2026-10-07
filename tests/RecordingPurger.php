<?php

namespace samuelreichor\betterCache\tests;

use RuntimeException;
use samuelreichor\betterCache\purgers\PurgerInterface;

class RecordingPurger implements PurgerInterface
{
    /** @var array<array{string, mixed}> */
    public static array $calls = [];
    public static bool $fail = false;

    public function purgeTags(array $tags): void
    {
        $this->record('tags', $tags);
    }

    public function purgeUrls(array $urls): void
    {
        $this->record('urls', $urls);
    }

    public function purgeAll(): void
    {
        $this->record('all', null);
    }

    private function record(string $type, mixed $value): void
    {
        if (self::$fail) {
            throw new RuntimeException('CDN down');
        }
        self::$calls[] = [$type, $value];
    }
}
