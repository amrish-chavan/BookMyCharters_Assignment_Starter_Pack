<?php

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use Yii;
use app\components\CacheManager;

class CacheManagerTest extends TestCase
{
    private function createCacheManager(): CacheManager
    {
        $cm = new CacheManager([
            'duration' => 300,
            'keyPrefix' => 'test_',
        ]);
        $cm->init();
        return $cm;
    }

    public function testSetAndGetWithPrefix(): void
    {
        $cm = $this->createCacheManager();
        $cm->set('foo', ['bar' => 1]);
        $this->assertSame(['bar' => 1], $cm->get('foo'));
    }

    public function testGetReturnsFalseOnMiss(): void
    {
        $cm = $this->createCacheManager();
        $this->assertFalse($cm->get('nonexistent'));
    }

    public function testInvalidateEntityDeletesAllDependentKeys(): void
    {
        $cm = $this->createCacheManager();

        $cm->registerDependencies('widget', [
            fn(int $id): string => "widget:{$id}",
            fn(int $id): string => "widget:{$id}:details",
        ]);

        $cm->set('widget:42', ['name' => 'W-42']);
        $cm->set('widget:42:details', ['extra' => true]);

        $deleted = $cm->invalidateEntity('widget', 42);

        $this->assertSame(2, $deleted);
        $this->assertFalse($cm->get('widget:42'));
        $this->assertFalse($cm->get('widget:42:details'));
    }

    public function testInvalidateEntityReturnsZeroForUnknownType(): void
    {
        $cm = $this->createCacheManager();
        $this->assertSame(0, $cm->invalidateEntity('unknown', 1));
    }

    public function testDurationIsRespected(): void
    {
        $cm = $this->createCacheManager();
        $this->assertSame(300, $cm->getDuration());
    }
}
