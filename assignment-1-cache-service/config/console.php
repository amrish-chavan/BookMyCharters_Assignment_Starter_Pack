<?php

declare(strict_types=1);

$db = require __DIR__ . '/db.php';

return [
    'id' => 'bmc-cache-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'components' => [
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'cacheManager' => [
            'class' => 'app\components\CacheManager',
            'duration' => 60,
            'keyPrefix' => 'bmc_',
        ],
        'db' => $db,
        'log' => [
            'targets' => [
                [
                    'class' => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
    ],
    'params' => [],
];
