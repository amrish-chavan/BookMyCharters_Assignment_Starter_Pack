<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use app\models\Category;
use app\models\Product;
use app\components\CacheManager;

/**
 * Categories API.
 */
class CategoryController extends Controller
{
    public $enableCsrfValidation = false;

    private function cache(): CacheManager
    {
        return Yii::$app->cacheManager;
    }

    /**
     * GET /categories/{id}/products
     */
    public function actionProducts(int $id): array
    {
        $category = Category::findOne($id);
        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        $key = 'category:' . $id . ':products';

        $data = $this->cache()->get($key);
        if ($data !== false) {
            return $data;
        }

        $products = Product::find()
            ->where(['category_id' => $id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $data = array_map(static fn(Product $p): array => $p->toArray(), $products);
        $this->cache()->set($key, $data);

        return $data;
    }
}
