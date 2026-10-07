<?php

namespace samuelreichor\betterCache\tests;

use craft\elements\Entry;

class QueryCacheTagsTest extends TestCase
{
    private const WILDCARD_TAG = 'element::craft\elements\Entry::*';

    public function testIdRestrictedQueryIsTaggedWithItsIds(): void
    {
        $tags = Entry::find()->andWhere(['elements.id' => [5, 7]])->getCacheTags();

        $this->assertContains('element::5', $tags);
        $this->assertContains('element::7', $tags);
        $this->assertNotContains(self::WILDCARD_TAG, $tags);
    }

    public function testFurtherConditionsKeepTheIds(): void
    {
        $tags = Entry::find()
            ->andWhere(['elements.id' => [5]])
            ->andWhere(['not', ['elements.id' => 9]])
            ->getCacheTags();

        $this->assertContains('element::5', $tags);
        $this->assertNotContains(self::WILDCARD_TAG, $tags);
    }

    public function testEmptyIdListIsNotTaggedWithTheWildcard(): void
    {
        $this->assertNotContains(self::WILDCARD_TAG, Entry::find()->andWhere(['elements.id' => []])->getCacheTags());
    }

    public function testUnrestrictedQueryKeepsTheWildcard(): void
    {
        $this->assertContains(self::WILDCARD_TAG, Entry::find()->andWhere(['elements.dateCreated' => null])->getCacheTags());
        $this->assertContains(self::WILDCARD_TAG, Entry::find()->andWhere(['or', ['elements.id' => 5], ['elements.id' => null]])->getCacheTags());
    }
}
