<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use samuelreichor\betterCache\BetterCache;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\jobs\RenderPagesJob;
use samuelreichor\betterCache\models\PageVariant;
use yii\base\Component;

class Warming extends Component
{
    /**
     * Queues a render of all live element URLs and every Page Variant in the Tag Index.
     */
    public function warm(): void
    {
        $this->push([
            ...$this->keyByUrl(array_map(fn(PageVariant $variant) => [null, $variant], $this->getElementPages())),
            ...$this->keyByUrl($this->withIds(BetterCache::getInstance()->tagIndex->findAllPages())),
        ]);
    }

    /**
     * @param array<int, PageVariant> $pages keyed by page ID
     */
    public function render(array $pages): void
    {
        $this->push($this->keyByUrl($this->withIds($pages)));
    }

    public function getUrl(PageVariant $variant): string
    {
        return UrlHelper::siteUrl($variant->path, $variant->query ?: null, null, $variant->siteId);
    }

    /**
     * @param array<string, array{int|null, PageVariant}> $pages
     */
    private function push(array $pages): void
    {
        foreach (array_chunk($pages, Constants::RENDER_BATCH_SIZE, true) as $batch) {
            BetterCache::getInstance()->getQueue()->push(new RenderPagesJob(['pages' => $batch]));
        }
    }

    /**
     * @param array<int, PageVariant> $pages keyed by page ID
     * @return array<array{int, PageVariant}>
     */
    private function withIds(array $pages): array
    {
        return array_map(null, array_keys($pages), array_values($pages));
    }

    /**
     * @param array<array{int|null, PageVariant}> $pages
     * @return array<string, array{int|null, PageVariant}>
     */
    private function keyByUrl(array $pages): array
    {
        $byUrl = [];
        foreach ($pages as $page) {
            $byUrl[$this->getUrl($page[1])] = $page;
        }

        return $byUrl;
    }

    /**
     * @return PageVariant[]
     */
    private function getElementPages(): array
    {
        $cacheability = BetterCache::getInstance()->cacheability;
        $now = Db::prepareDateForDb(new DateTime());
        $pages = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$site->hasUrls || !$site->getBaseUrl()) {
                continue;
            }

            $uris = (new Query())
                ->select(['es.uri'])
                ->from(['es' => Table::ELEMENTS_SITES])
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
                ->leftJoin(['en' => Table::ENTRIES], '[[en.id]] = [[e.id]]')
                ->where([
                    'es.siteId' => $site->id,
                    'es.enabled' => true,
                    'e.enabled' => true,
                    'e.archived' => false,
                    'e.dateDeleted' => null,
                    'e.draftId' => null,
                    'e.revisionId' => null,
                ])
                ->andWhere(['not', ['es.uri' => null]])
                ->andWhere(['or', ['en.id' => null], ['<=', 'en.postDate', $now]])
                ->andWhere(['or', ['en.expiryDate' => null], ['>', 'en.expiryDate', $now]])
                ->column();

            foreach ($uris as $uri) {
                $path = $uri === Element::HOMEPAGE_URI ? '' : $uri;
                if ($cacheability->isIncludedUri($path, $site)) {
                    $pages[] = new PageVariant($site->id, $path);
                }
            }
        }

        return $pages;
    }
}
