<?php

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();


cd /home/amrish/Documents/PHP/BookMyCharters_Assignment_Starter_Pack/assignment-1-cache-service

# Start MySQL + PHP server (first time builds the Docker image, ~2 min)
docker compose up -d

# Run migrations
docker compose exec app php yii migrate --interactive=0

# Run tests
docker compose exec app php tests/run.php

# Test the API
curl http://localhost:8080/products/1
curl http://localhost:8080/categories/1/products
curl -X PUT http://localhost:8080/products/1 -H "Content-Type: application/json" -d '{"name":"Test","price":100}'

# Stop everything
docker compose down
