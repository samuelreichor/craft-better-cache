<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\purgers\PurgerInterface;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Cdn extends Component
{
    /**
     * @param string[] $tags Craft's cache tags of the page
     */
    public function addPublicHeaders(Response $response, array $tags): void
    {
        $settings = BetterCache::getInstance()->getSettings();
        // Without a purger a CDN would keep pages for the whole max age, since invalidations never reach it.
        if ($settings->purger === null) {
            return;
        }

        $headers = $response->getHeaders();
        $headers->set(Constants::CACHE_CONTROL_HEADER, sprintf(Constants::PUBLIC_CACHE_CONTROL, $settings->cdnMaxAge));

        if ($settings->cacheTagHeader !== '' && $tags) {
            $headers->set($settings->cacheTagHeader, $this->buildTagHeader($tags));
        }
    }

    public function addPrivateHeaders(Response $response): void
    {
        $response->getHeaders()->set(Constants::CACHE_CONTROL_HEADER, Constants::PRIVATE_CACHE_CONTROL);
    }

    /**
     * CDNs restrict the characters and length of tags, so Craft's tags are hashed. Header and purge both use this.
     */
    public function normalizeTag(string $tag): string
    {
        return substr(md5($tag), 0, Constants::CDN_TAG_LENGTH);
    }

    /**
     * @param string[] $tags Craft's cache tags
     */
    public function purgeTags(array $tags): void
    {
        $this->withPurger(fn(PurgerInterface $purger) => $purger->purgeTags($this->normalizeTags($tags)));
    }

    /**
     * @param string[] $urls
     */
    public function purgeUrls(array $urls): void
    {
        if ($urls) {
            $this->withPurger(fn(PurgerInterface $purger) => $purger->purgeUrls(array_values($urls)));
        }
    }

    public function purgeAll(): void
    {
        $this->withPurger(fn(PurgerInterface $purger) => $purger->purgeAll());
    }

    /**
     * @param string[] $tags
     */
    private function buildTagHeader(array $tags): string
    {
        $header = '';
        foreach ($this->normalizeTags($tags) as $tag) {
            $next = $header === '' ? $tag : $header . Constants::CDN_TAG_SEPARATOR . $tag;
            if (strlen($next) > Constants::MAX_CDN_TAG_HEADER_LENGTH) {
                Craft::warning('The cache tag header was truncated, some tags cannot be purged on the CDN.', Constants::HANDLE);
                break;
            }
            $header = $next;
        }

        return $header;
    }

    /**
     * @param string[] $tags
     * @return string[]
     */
    private function normalizeTags(array $tags): array
    {
        return array_values(array_unique(array_map($this->normalizeTag(...), $tags)));
    }

    private function withPurger(callable $callback): void
    {
        $class = BetterCache::getInstance()->getSettings()->purger;
        if ($class === null) {
            return;
        }

        try {
            $purger = Craft::createObject($class);
            if (!$purger instanceof PurgerInterface) {
                throw new InvalidConfigException("$class must implement " . PurgerInterface::class);
            }
            $callback($purger);
        } catch (Throwable $e) {
            Craft::warning("CDN purge failed: {$e->getMessage()}", Constants::HANDLE);
        }
    }
}
