<?php

namespace samuelreichor\betterCache\drivers;

use samuelreichor\betterCache\models\PageVariant;

interface StorageDriverInterface
{
    public function get(PageVariant $variant): ?string;

    public function save(PageVariant $variant, string $html): void;

    public function delete(PageVariant $variant): void;

    public function deleteAll(): void;
}
