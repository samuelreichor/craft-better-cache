<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\models\PageVariant;
use yii\base\Component;

class TagIndex extends Component
{
    /**
     * Stores the page as fresh and replaces its tags.
     *
     * @param string[] $tags
     * @return int the page ID
     */
    public function upsertPage(PageVariant $variant, array $tags): int
    {
        $hash = $variant->getHash();

        return Craft::$app->getDb()->transaction(function() use ($variant, $hash, $tags): int {
            Db::upsert(Constants::TABLE_PAGES, [
                'siteId' => $variant->siteId,
                'path' => $variant->path,
                'query' => $variant->query,
                'variantHash' => $hash,
                'stale' => false,
            ], [
                'stale' => false,
            ]);

            $pageId = (int)(new Query())
                ->select('id')
                ->from(Constants::TABLE_PAGES)
                ->where(['siteId' => $variant->siteId, 'variantHash' => $hash])
                ->scalar();

            Db::delete(Constants::TABLE_PAGE_TAGS, ['pageId' => $pageId]);
            if ($tags) {
                Db::batchInsert(
                    Constants::TABLE_PAGE_TAGS,
                    ['pageId', 'tag'],
                    array_map(fn(string $tag) => [$pageId, $tag], array_unique($tags)),
                );
            }

            return $pageId;
        });
    }

    /**
     * @param string[] $tags
     * @return array<int, PageVariant> keyed by page ID
     */
    public function findPagesByTags(array $tags): array
    {
        return $this->toPages($this->pagesQuery()
            ->distinct()
            ->innerJoin(['t' => Constants::TABLE_PAGE_TAGS], '[[t.pageId]] = [[p.id]]')
            ->where(['t.tag' => $tags])
            ->all());
    }

    /**
     * @return array<int, PageVariant> keyed by page ID
     */
    public function findAllPages(): array
    {
        return $this->toPages($this->pagesQuery()->all());
    }

    /**
     * @param int[] $pageIds
     */
    public function markStale(array $pageIds): void
    {
        Db::update(Constants::TABLE_PAGES, ['stale' => true], ['id' => $pageIds]);
    }

    /**
     * @param int[] $pageIds
     */
    public function deletePages(array $pageIds): void
    {
        Db::delete(Constants::TABLE_PAGES, ['id' => $pageIds]);
    }

    private function pagesQuery(): Query
    {
        return (new Query())
            ->select(['p.id', 'p.siteId', 'p.path', 'p.query'])
            ->from(['p' => Constants::TABLE_PAGES]);
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return array<int, PageVariant>
     */
    private function toPages(array $rows): array
    {
        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['id']] = new PageVariant((int)$row['siteId'], $row['path'], $row['query']);
        }

        return $pages;
    }
}
