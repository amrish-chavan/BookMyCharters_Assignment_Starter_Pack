<?php

declare(strict_types=1);

use yii\db\Migration;

class m240101_000002_create_product extends Migration
{
    public function safeUp()
    {
        $this->createTable('product', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'category_id' => $this->integer()->notNull(),
            'price' => $this->decimal(10, 2)->notNull(),
            'description' => $this->text()->null(),
        ]);

        $this->createIndex('idx-product-category_id', 'product', 'category_id');
        $this->addForeignKey(
            'fk-product-category',
            'product',
            'category_id',
            'category',
            'id',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk-product-category', 'product');
        $this->dropIndex('idx-product-category_id', 'product');
        $this->dropTable('product');
    }
}
