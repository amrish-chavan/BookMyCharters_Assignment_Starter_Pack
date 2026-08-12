<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../vendor/autoload.php';

use yii\console\Application;

$config = require __DIR__ . '/../config/console.php';

new Application($config);
