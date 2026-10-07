<?php

namespace samuelreichor\betterCache\jobs;

use Craft;
use craft\queue\BaseJob;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;

class InvalidateTagsJob extends BaseJob
{
    /**
     * @var string[]
     */
    public array $tags = [];

    public function execute($queue): void
    {
        BetterCache::getInstance()->invalidation->invalidateTags($this->tags);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t(Constants::HANDLE, 'Invalidating cached pages');
    }
}
