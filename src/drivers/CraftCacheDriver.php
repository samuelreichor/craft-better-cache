<?php

namespace samuelreichor\betterCache\drivers;

use Craft;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use yii\caching\TagDependency;

class CraftCacheDriver implements StorageDriverInterface
{
    public function get(PageVariant $variant): ?string
    {
        $html = Craft::$app->getCache()->get($this->getKey($variant));

        return is_string($html) ? $html : null;
    }

    public function save(PageVariant $variant, string $html): void
    {
        Craft::$app->getCache()->set($this->getKey($variant), $html, 0, new TagDependency([
            'tags' => [Constants::CACHE_DEPENDENCY_TAG],
        ]));
    }

    public function delete(PageVariant $variant): void
    {
        Craft::$app->getCache()->delete($this->getKey($variant));
    }

    public function deleteAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), Constants::CACHE_DEPENDENCY_TAG);
    }

    private function getKey(PageVariant $variant): string
    {
        return Constants::CACHE_KEY_PREFIX . $variant->getKey();
    }
}
