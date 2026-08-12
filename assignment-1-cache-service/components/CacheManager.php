<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * Reusable cache abstraction built on top of Yii's cache backend.
 *
 * Provides a simple registry of cache-key patterns grouped by entity type.
 * When an entity is modified, call invalidateEntity() to drop every cache
 * entry that could be affected — controllers never need to know which
 * keys exist or how they are constructed.
 *
 * Usage:
 *   $cache = new CacheManager(['duration' => 300]);
 *   $cache->registerDependencies('product', [
 *       fn(int $id) => "product:{$id}",
 *       fn(int $id) => "category:" . Product::findOne($id)->category_id . ":products",
 *   ]);
 *   // Later, after a product update:
 *   $cache->invalidateEntity('product', $productId);
 */
class CacheManager extends Component
{
    /**
     * Default TTL in seconds used when set() is called without an explicit
     * duration.  Can be overridden per-call.
     */
    public int $duration = 60;

    /**
     * Optional prefix applied to every cache key.  Useful when multiple
     * applications share the same cache directory.
     */
    public string $keyPrefix = '';

    /**
     * Registry of dependency generators keyed by entity type.
     *
     * Each value is an array of callables: fn(int $id): string
     */
    private array $dependencies = [];

    /**
     * The underlying Yii cache component.
     */
    private ?CacheInterface $cache = null;

    public function init(): void
    {
        parent::init();
        $this->cache = Yii::$app->cache;
        $this->registerDefaultDependencies();
    }

    private function registerDefaultDependencies(): void
    {
        $this->registerDependencies('product', [
            fn(int $id): string => "product:{$id}",
            fn(int $id): string => 'category:'
                . \app\models\Product::findOne($id)->category_id
                . ':products',
        ]);

        $this->registerDependencies('category', [
            fn(int $id): string => "category:{$id}:products",
        ]);
    }

    // ------------------------------------------------------------------
    //  Registration
    // ------------------------------------------------------------------

    /**
     * Register one or more cache-key generators for an entity type.
     *
     * @param string     $entityType   e.g. 'product', 'category'
     * @param callable[] $generators   Array of callables: fn(int $id): string
     */
    public function registerDependencies(string $entityType, array $generators): void
    {
        $this->dependencies[$entityType] = array_merge(
            $this->dependencies[$entityType] ?? [],
            $generators,
        );
    }

    // ------------------------------------------------------------------
    //  Read / Write helpers
    // ------------------------------------------------------------------

    /**
     * Retrieve a value from cache (with prefix applied).
     */
    public function get(string $key): mixed
    {
        return $this->cache->get($this->keyPrefix . $key);
    }

    /**
     * Store a value in cache.
     *
     * @param mixed      $data
     * @param int|null   $duration  TTL in seconds; falls back to $this->duration.
     */
    public function set(string $key, mixed $data, ?int $duration = null): void
    {
        $this->cache->set(
            $this->keyPrefix . $key,
            $data,
            $duration ?? $this->duration,
        );
    }

    // ------------------------------------------------------------------
    //  Invalidation
    // ------------------------------------------------------------------

    /**
     * Drop every cached value that depends on the given entity instance.
     *
     * Returns the number of cache entries actually deleted, which is
     * useful for logging / debugging but not required by callers.
     */
    public function invalidateEntity(string $entityType, int $id): int
    {
        $generators = $this->dependencies[$entityType] ?? [];
        $deleted = 0;

        foreach ($generators as $generator) {
            $key = $this->keyPrefix . $generator($id);
            if ($this->cache->delete($key)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ------------------------------------------------------------------
    //  Accessors
    // ----------------------------------------------------------------++

    /**
     * Direct access to the underlying cache component, for rare cases
     * where callers need a capability not wrapped here.
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }
}
