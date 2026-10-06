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
        $rows = (new Query())
            ->select(['p.id', 'p.siteId', 'p.path', 'p.query'])
            ->distinct()
            ->from(['p' => Constants::TABLE_PAGES])
            ->innerJoin(['t' => Constants::TABLE_PAGE_TAGS], '[[t.pageId]] = [[p.id]]')
            ->where(['t.tag' => $tags])
            ->all();

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['id']] = new PageVariant((int)$row['siteId'], $row['path'], $row['query']);
        }

        return $pages;
    }
}
