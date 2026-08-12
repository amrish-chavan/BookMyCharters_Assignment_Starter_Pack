<?php

declare(strict_types=1);

use yii\db\Migration;

class m240101_000001_create_category extends Migration
{
    public function safeUp()
    {
        $this->createTable('category', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('category');
    }
}
