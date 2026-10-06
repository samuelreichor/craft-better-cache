<?php

namespace samuelreichor\betterCache\models;

final class PageVariant
{
    public function __construct(
        public readonly int $siteId,
        public readonly string $path,
        public readonly string $query = '',
    ) {
    }

    public function getHash(): string
    {
        return sha1($this->path . '?' . $this->query);
    }
}
