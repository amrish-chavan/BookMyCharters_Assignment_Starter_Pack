<?php

declare(strict_types=1);

use yii\db\Migration;

class m240101_000003_seed_data extends Migration
{
    public function safeUp()
    {
        $this->batchInsert('category', ['id', 'name'], [
            [1, 'Helicopters'],
            [2, 'Light Jets'],
        ]);

        $this->batchInsert('product', ['id', 'name', 'category_id', 'price', 'description'], [
            [1, 'Airbus H125', 1, 45000.00, 'Single-engine light utility helicopter.'],
            [2, 'Bell 407', 1, 52000.00, 'Four-blade single-engine helicopter.'],
            [3, 'Embraer Phenom 100', 2, 89000.00, 'Entry-level light jet.'],
            [4, 'Cessna Citation M2', 2, 95000.00, 'Light business jet.'],
        ]);
    }

    public function safeDown()
    {
        $this->delete('product');
        $this->delete('category');
    }
}
