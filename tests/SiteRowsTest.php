<?php

namespace samuelreichor\betterCache\tests;

use samuelreichor\betterCache\helpers\SiteRows;

class SiteRowsTest extends TestCase
{
    public function testTurnsPatternsBySiteIntoRows(): void
    {
        $this->assertSame([
            ['site' => '*', 'pattern' => '.*'],
            ['site' => 'de', 'pattern' => '^news'],
            ['site' => 'de', 'pattern' => '^blog'],
        ], SiteRows::fromPatterns(['*' => ['.*'], 'de' => ['^news', '^blog']]));
    }

    public function testTurnsRowsIntoPatternsBySite(): void
    {
        $this->assertSame(
            ['*' => ['.*'], 'de' => ['^news', '^blog']],
            SiteRows::toPatterns([
                'new1' => ['site' => '*', 'pattern' => '.*'],
                'new2' => ['site' => 'de', 'pattern' => '^news'],
                'new3' => ['site' => 'de', 'pattern' => '^blog'],
            ]),
        );
    }

    public function testRowsWithoutSiteApplyToAllSites(): void
    {
        $this->assertSame(['*' => ['^news']], SiteRows::toPatterns([['pattern' => '^news']]));
    }

    public function testSkipsBlankPatterns(): void
    {
        $this->assertSame([], SiteRows::toPatterns([['site' => '*', 'pattern' => '  ']]));
    }
}
