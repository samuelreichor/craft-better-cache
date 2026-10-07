<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\base\ElementInterface;
use craft\web\Request;
use craft\web\Response;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use Throwable;
use yii\base\Component;

class Capture extends Component
{
    private ?PageVariant $variant = null;

    public function start(Request $request): void
    {
        $this->variant = BetterCache::getInstance()->cacheability->getPageVariant($request);
        if ($this->variant !== null) {
            Craft::$app->getElements()->startCollectingCacheInfo();
        }
    }

    public function finish(Response $response): void
    {
        if ($this->variant === null) {
            return;
        }

        $variant = $this->variant;
        $this->variant = null;
        $tags = $this->stopCollectingTags();

        $plugin = BetterCache::getInstance();
        if (!$plugin->cacheability->isStorable($response)) {
            return;
        }

        $response->getCookies()->removeAll();
        // The session cookie is set by PHP itself, not through the response.
        header_remove('Set-Cookie');

        $mutex = Craft::$app->getMutex();
        $lockName = Constants::MUTEX_PREFIX . $variant->siteId . ':' . $variant->getHash();
        $locked = false;

        // The index row comes first: a row without a file heals on the next visit, a file without a row is never invalidated.
        try {
            $locked = $mutex->acquire($lockName, Constants::MUTEX_TIMEOUT);
            if ($locked) {
                $plugin->tagIndex->upsertPage($variant, $tags);
                $plugin->getStorageDriver()->save($variant, (string)$response->content);
            }
        } catch (Throwable $e) {
            Craft::warning("Could not store “{$variant->path}”: {$e->getMessage()}", Constants::HANDLE);
        } finally {
            if ($locked) {
                $mutex->release($lockName);
            }
        }
    }

    /**
     * @return string[]
     */
    private function stopCollectingTags(): array
    {
        $elements = Craft::$app->getElements();

        // The matched element is loaded during routing, so make sure its own tags are part of the page.
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if ($element instanceof ElementInterface) {
            $elements->collectCacheInfoForElement($element);
        }

        [$dependency] = $elements->stopCollectingCacheInfo();

        return $dependency->tags ?? [];
    }
}
