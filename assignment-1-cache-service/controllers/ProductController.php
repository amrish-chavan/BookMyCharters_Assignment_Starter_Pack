<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use app\models\Product;
use app\components\CacheManager;

/**
 * Products API.
 *
 * Caching is handled by the CacheManager component, which centralises
 * cache key construction and invalidation so that controllers never
 * need to know which downstream caches are affected by a change.
 */
class ProductController extends Controller
{
    public $enableCsrfValidation = false;

    private function cache(): CacheManager
    {
        return Yii::$app->cacheManager;
    }

    /**
     * GET /products/{id}
     */
    public function actionView(int $id): array
    {
        $key = 'product:' . $id;

        $data = $this->cache()->get($key);
        if ($data !== false) {
            return $data;
        }

        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $data = $product->toArray();
        $this->cache()->set($key, $data);

        return $data;
    }

    /**
     * PUT /products/{id}
     */
    public function actionUpdate(int $id): array
    {
        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $product->load(Yii::$app->request->getBodyParams(), '');

        if (!$product->save()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $product->getErrors()];
        }

        // Invalidate all caches that depend on this product.
        $this->cache()->invalidateEntity('product', $product->id);

        return $product->toArray();
    }
}
