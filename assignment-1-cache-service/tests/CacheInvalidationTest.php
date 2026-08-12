<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Yii;
use yii\web\Application;
use yii\web\NotFoundHttpException;

/**
 * Integration test that reproduces the original cache-invalidation bug.
 *
 * This test would have FAILED with the original code (before the fix)
 * because the category product list cache was not invalidated when a
 * product was updated.
 */
class CacheInvalidationTest extends TestCase
{
    private static ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        if (self::$app !== null) {
            return;
        }

        $config = require __DIR__ . '/../config/web.php';
        self::$app = new Application($config);
    }

    private function request(string $method, string $url, array $body = []): array
    {
        $request = self::$app->request;
        $request->setMethod($method);
        $request->setRawBody(json_encode($body));
        $request->setContentType('application/json');

        $response = self::$app->response;
        $response->statusCode = 200;

        try {
            $result = self::$app->runAction(ltrim($url, '/'));
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'code' => $response->statusCode];
        }

        return (array) $result;
    }

    public function testProductUpdateInvalidatesCategoryCache(): void
    {
        $cache = self::$app->cacheManager;

        // 1. Warm the category list cache.
        $catKey = 'category:1:products';
        $data = $cache->get($catKey);
        if ($data === false) {
            $products = \app\models\Product::find()
                ->where(['category_id' => 1])
                ->orderBy(['id' => SORT_ASC])
                ->all();
            $data = array_map(fn($p) => $p->toArray(), $products);
            $cache->set($catKey, $data);
        }

        $before = $cache->get($catKey);
        $this->assertNotFalse($before, 'Category cache should be warm');
        $this->assertSame('Airbus H125', $before[0]['name']);

        // 2. Update the product (this should invalidate the category cache).
        $product = \app\models\Product::findOne(1);
        $product->name = 'Airbus H125 (TEST)';
        $product->save();

        $cache->invalidateEntity('product', $product->id);

        // 3. The category cache should now be gone.
        $after = $cache->get($catKey);
        $this->assertFalse($after, 'Category cache should be invalidated after product update');

        // Cleanup: restore original name.
        $product->name = 'Airbus H125';
        $product->save();
    }

    public function testProductCacheIsInvalidatedOnUpdate(): void
    {
        $cache = self::$app->cacheManager;

        // Warm product cache.
        $key = 'product:1';
        $cache->set($key, ['id' => 1, 'name' => 'Original']);

        // Invalidate.
        $cache->invalidateEntity('product', 1);

        $this->assertFalse($cache->get($key), 'Product cache should be gone');
    }

    public function testGetProductReturnsCorrectData(): void
    {
        $product = \app\models\Product::findOne(1);
        $this->assertNotNull($product);
        $this->assertSame('Airbus H125', $product->name);
        $this->assertSame(1, $product->category_id);
    }

    public function testCategoryProductsContainExpectedItems(): void
    {
        $products = \app\models\Product::find()
            ->where(['category_id' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $this->assertCount(2, $products);
        $this->assertSame('Airbus H125', $products[0]->name);
        $this->assertSame('Bell 407', $products[1]->name);
    }
}
