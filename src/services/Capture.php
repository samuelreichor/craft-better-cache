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
        if ($this->variant === null) {
            return;
        }

        if (!$request->getHeaders()->has(Constants::BYPASS_HEADER)) {
            $html = $this->getCachedHtml($this->variant);
            if ($html !== null) {
                $this->variant = null;
                $this->serve($html);
            }
        }

        Craft::$app->getElements()->startCollectingCacheInfo();
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

        $this->stripCookies($response);

        $mutex = Craft::$app->getMutex();
        $lockName = Constants::MUTEX_PREFIX . $variant->getKey();
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

    private function getCachedHtml(PageVariant $variant): ?string
    {
        try {
            return BetterCache::getInstance()->getStorageDriver()->get($variant);
        } catch (Throwable $e) {
            Craft::warning("Could not read “{$variant->path}”: {$e->getMessage()}", Constants::HANDLE);
            return null;
        }
    }

    private function serve(string $html): void
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $html;
        $headers = $response->getHeaders();
        $headers->set('Content-Type', Constants::HTML_CONTENT_TYPE . '; charset=' . $response->charset);

        // Craft adds these in handleRequest(), which a served page never reaches.
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        if ($generalConfig->disallowRobots) {
            $headers->set('X-Robots-Tag', 'none');
        }
        if ($generalConfig->sendPoweredByHeader) {
            $headers->set('X-Powered-By', Craft::$app->name);
        } else {
            header_remove('X-Powered-By');
        }

        // Logged-in visitors are served too, but must keep their session and identity cookies.
        if (Craft::$app->getUser()->getIsGuest()) {
            $this->stripCookies($response);
        }

        Craft::$app->end();
    }

    private function stripCookies(Response $response): void
    {
        $response->getCookies()->removeAll();
        // The session cookie is set by PHP itself, not through the response.
        header_remove('Set-Cookie');
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
