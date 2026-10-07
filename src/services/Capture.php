<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\events\DefineValueEvent;
use craft\helpers\ArrayHelper;
use craft\web\Request;
use craft\web\Response;
use DateTime;
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
            $variant = $this->variant;
            $html = $this->getCachedHtml($variant);
            if ($html !== null) {
                $this->variant = null;
                $this->serve($html, $variant);
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
            $this->addCdnHeaders($response, null);
            return;
        }

        $this->addCdnHeaders($response, $tags);
        $this->stripCookies($response);
        $response->content = $plugin->dynamicContent->prepareForStorage((string)$response->content)
            . $this->comment(Constants::CACHED_COMMENT);
        if ($response->getHeaders()->has(Constants::CONTENT_LENGTH_HEADER)) {
            $response->getHeaders()->set(Constants::CONTENT_LENGTH_HEADER, (string)strlen($response->content));
        }

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

    /**
     * Relation field values restrict their query with a where condition instead of `id`, which Craft
     * tags with the element type wildcard. That would put nearly every page under every save.
     */
    public function defineQueryTags(DefineValueEvent $event): void
    {
        if ($event->value) {
            return;
        }

        /** @var ElementQuery $query */
        $query = $event->sender;
        $ids = $this->findFixedIds($query->where);
        if ($ids === null) {
            return;
        }

        $event->value = $ids
            ? array_map(fn(int $id) => "element::$id", $ids)
            : [Constants::NO_ELEMENTS_TAG];
    }

    /**
     * @return int[]|null the IDs the condition limits the result to, or null if it does not
     */
    private function findFixedIds(mixed $condition): ?array
    {
        if (!is_array($condition)) {
            return null;
        }

        if (is_string($condition[0] ?? null) && strtolower($condition[0]) === 'and') {
            foreach (array_slice($condition, 1) as $operand) {
                $ids = $this->findFixedIds($operand);
                if ($ids !== null) {
                    return $ids;
                }
            }
            return null;
        }

        $ids = $condition['elements.id'] ?? null;
        if (is_numeric($ids)) {
            return [(int)$ids];
        }
        if (is_array($ids) && ArrayHelper::isNumeric($ids)) {
            return array_map('intval', $ids);
        }

        return null;
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

    private function serve(string $html, PageVariant $variant): void
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $html . $this->comment(Constants::SERVED_COMMENT);
        $headers = $response->getHeaders();
        $headers->set(Constants::CONTENT_TYPE_HEADER, Constants::HTML_CONTENT_TYPE . '; charset=' . $response->charset);

        // Craft adds these in handleRequest(), which a served page never reaches.
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        if ($generalConfig->disallowRobots) {
            $headers->set(Constants::ROBOTS_HEADER, Constants::ROBOTS_NONE);
        }
        if ($generalConfig->sendPoweredByHeader) {
            $headers->set(Constants::POWERED_BY_HEADER, Craft::$app->name);
        } else {
            header_remove(Constants::POWERED_BY_HEADER);
        }

        // Logged-in visitors are served too, but must keep their session and identity cookies.
        if (Craft::$app->getUser()->getIsGuest()) {
            $this->stripCookies($response);
        }

        $state = BetterCache::getInstance()->tagIndex->findPageState($variant);
        $this->addCdnHeaders($response, $state !== null && !$state['stale'] ? $state['tags'] : null);

        Craft::$app->end();
    }

    /**
     * @param string[]|null $tags null for a page a CDN must not keep, like a Stale Page
     */
    private function addCdnHeaders(Response $response, ?array $tags): void
    {
        $cdn = BetterCache::getInstance()->cdn;
        if (!Craft::$app->getUser()->getIsGuest()) {
            $cdn->addPrivateHeaders($response);
        } elseif ($tags !== null) {
            $cdn->addPublicHeaders($response, $tags);
        }
    }

    private function comment(string $format): string
    {
        return sprintf($format, (new DateTime())->format(DATE_ATOM));
    }

    private function stripCookies(Response $response): void
    {
        $response->getCookies()->removeAll();
        // The session cookie is set by PHP itself, not through the response.
        header_remove(Constants::SET_COOKIE_HEADER);
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
