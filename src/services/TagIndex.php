<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
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
        return Craft::$app->getDb()->transaction(function() use ($variant, $tags): int {
            Db::upsert(Constants::TABLE_PAGES, [
                'siteId' => $variant->siteId,
                'path' => $variant->path,
                'query' => $variant->query,
                'variantHash' => $variant->getHash(),
                'stale' => false,
            ], [
                'stale' => false,
            ]);

            $pageId = (int)$this->findPageId($variant);

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

    public function findPageId(PageVariant $variant): ?int
    {
        $id = (new Query())
            ->select('id')
            ->from(Constants::TABLE_PAGES)
            ->where(['siteId' => $variant->siteId, 'variantHash' => $variant->getHash()])
            ->scalar();

        return $id === false ? null : (int)$id;
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

    /**
     * @return array{stale: bool, tags: string[]}|null
     */
    public function findPageState(PageVariant $variant): ?array
    {
        $page = (new Query())
            ->select(['id', 'stale'])
            ->from(Constants::TABLE_PAGES)
            ->where(['siteId' => $variant->siteId, 'variantHash' => $variant->getHash()])
            ->one();
        if ($page === null) {
            return null;
        }

        return [
            'stale' => (bool)$page['stale'],
            'tags' => (new Query())->select('tag')->from(Constants::TABLE_PAGE_TAGS)->where(['pageId' => $page['id']])->column(),
        ];
    }

    /**
     * @return array<int, array{total: int, stale: int}> keyed by site ID
     */
    public function countPagesBySite(): array
    {
        $rows = (new Query())
            ->select(['siteId', 'total' => 'COUNT(*)', 'stale' => 'SUM([[stale]])'])
            ->from(Constants::TABLE_PAGES)
            ->groupBy('siteId')
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['siteId']] = ['total' => (int)$row['total'], 'stale' => (int)$row['stale']];
        }

        return $counts;
    }

    public function findOldestUpdate(): ?DateTime
    {
        $date = (new Query())->from(Constants::TABLE_PAGES)->min('dateUpdated');

        return $date ? DateTimeHelper::toDateTime($date) ?: null : null;
    }

    public function deleteAllPages(): void
    {
        Db::delete(Constants::TABLE_PAGES);
    }

    /**
     * @return array<int, PageVariant> keyed by page ID
     */
    public function findPagesBySite(int $siteId): array
    {
        return $this->toPages($this->pagesQuery()->where(['p.siteId' => $siteId])->all());
    }

    /**
     * Pages of trashed sites are left out, since their files and URLs can no longer be resolved.
     */
    private function pagesQuery(): Query
    {
        return (new Query())
            ->select(['p.id', 'p.siteId', 'p.path', 'p.query'])
            ->from(['p' => Constants::TABLE_PAGES])
            ->innerJoin(['s' => Table::SITES], '[[s.id]] = [[p.siteId]] AND [[s.dateDeleted]] IS NULL');
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
