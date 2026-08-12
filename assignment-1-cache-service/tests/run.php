<?php

declare(strict_types=1);

/**
 * Standalone test script for the cache invalidation fix.
 *
 * Run inside Docker:
 *   docker compose exec app php tests/run.php
 */

require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../vendor/autoload.php';

use yii\console\Application;

$config = require __DIR__ . '/../config/console.php';
new Application($config);

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assert_true(mixed $value, string $msg = ''): void
{
    if (!$value) {
        throw new \RuntimeException("Expected true, got " . var_export($value, true) . ". {$msg}");
    }
}

function assert_false(mixed $value, string $msg = ''): void
{
    if ($value !== false) {
        throw new \RuntimeException("Expected false, got " . var_export($value, true) . ". {$msg}");
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            "Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". {$msg}"
        );
    }
}

echo "CacheManager unit tests\n";
echo str_repeat('-', 50) . "\n";

test('set and get with prefix', function () {
    $cm = new \app\components\CacheManager(['duration' => 300, 'keyPrefix' => 'test_']);
    $cm->init();
    $cm->set('foo', ['bar' => 1]);
    assert_same(['bar' => 1], $cm->get('foo'));
});

test('get returns false on miss', function () {
    $cm = new \app\components\CacheManager(['duration' => 300, 'keyPrefix' => 'test_']);
    $cm->init();
    assert_false($cm->get('nonexistent_key_12345'));
});

test('invalidateEntity deletes all dependent keys', function () {
    $cm = new \app\components\CacheManager(['duration' => 300, 'keyPrefix' => 'test_']);
    $cm->init();
    $cm->registerDependencies('widget', [
        fn(int $id): string => "widget:{$id}",
        fn(int $id): string => "widget:{$id}:details",
    ]);
    $cm->set('widget:42', ['name' => 'W-42']);
    $cm->set('widget:42:details', ['extra' => true]);

    $deleted = $cm->invalidateEntity('widget', 42);

    assert_same(2, $deleted, 'Should delete 2 entries');
    assert_false($cm->get('widget:42'), 'widget:42 should be gone');
    assert_false($cm->get('widget:42:details'), 'widget:42:details should be gone');
});

test('invalidateEntity returns 0 for unknown type', function () {
    $cm = new \app\components\CacheManager(['duration' => 300, 'keyPrefix' => 'test_']);
    $cm->init();
    assert_same(0, $cm->invalidateEntity('unknown', 1));
});

test('duration is respected', function () {
    $cm = new \app\components\CacheManager(['duration' => 300, 'keyPrefix' => 'test_']);
    $cm->init();
    assert_same(300, $cm->getDuration());
});

echo "\nIntegration tests — Cache invalidation bug fix\n";
echo str_repeat('-', 50) . "\n";

test('THE BUG: product update invalidates category cache', function () {
    $cm = Yii::$app->cacheManager;

    // Warm the category list cache.
    $catKey = 'category:1:products';
    $products = \app\models\Product::find()
        ->where(['category_id' => 1])
        ->orderBy(['id' => SORT_ASC])
        ->all();
    $data = array_map(fn($p) => $p->toArray(), $products);
    $cm->set($catKey, $data);

    $before = $cm->get($catKey);
    assert_true($before !== false, 'Category cache should be warm');
    assert_same('Airbus H125', $before[0]['name']);

    // Update the product.
    $product = \app\models\Product::findOne(1);
    $product->name = 'Airbus H125 (BUG TEST)';
    $product->save();

    // Invalidate via CacheManager.
    $cm->invalidateEntity('product', $product->id);

    // The category cache should now be gone.
    $after = $cm->get($catKey);
    assert_false($after, 'Category cache should be invalidated after product update');

    // Cleanup.
    $product->name = 'Airbus H125';
    $product->save();
});

test('product cache is invalidated on update', function () {
    $cm = Yii::$app->cacheManager;
    $key = 'product:1';
    $cm->set($key, ['id' => 1, 'name' => 'Original']);

    $cm->invalidateEntity('product', 1);

    assert_false($cm->get($key), 'Product cache should be gone');
});

test('get product returns correct data', function () {
    $product = \app\models\Product::findOne(1);
    assert_true($product !== null, 'Product should exist');
    assert_same('Airbus H125', $product->name);
    assert_same(1, $product->category_id);
});

test('category 1 has 2 products', function () {
    $products = \app\models\Product::find()
        ->where(['category_id' => 1])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    assert_same(2, count($products), 'Category 1 should have 2 products');
    assert_same('Airbus H125', $products[0]->name);
    assert_same('Bell 407', $products[1]->name);
});

echo "\n" . str_repeat('-', 50) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
