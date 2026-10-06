<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use yii\db\Transaction;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    private Transaction $transaction;

    protected function setUp(): void
    {
        $this->transaction = Craft::$app->getDb()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->transaction->rollBack();
    }
}
