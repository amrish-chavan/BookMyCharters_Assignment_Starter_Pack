<?php

declare(strict_types=1);

$db = require __DIR__ . '/db.php';

return [
    'id' => 'bmc-cache-service',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => [
            // Replace this before any real deployment.
            'cookieValidationKey' => 'assignment-seed-key-change-me',
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
        ],
        'cache' => [
            'class' => 'yii\caching\FileCache',
        ],
        'cacheManager' => [
            'class' => 'app\components\CacheManager',
            'duration' => 60,
            'keyPrefix' => 'bmc_',
        ],
        'db' => $db,
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
        ],
        'errorHandler' => [
            'errorAction' => null,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                'GET products/<id:\d+>' => 'product/view',
                'PUT products/<id:\d+>' => 'product/update',
                'GET categories/<id:\d+>/products' => 'category/products',
            ],
        ],
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
