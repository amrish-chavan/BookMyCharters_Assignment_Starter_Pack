# Assignment 1 — Product Cache Service

A Yii2 JSON API that exposes products and category listings with FileCache.
This submission fixes a customer-facing cache-invalidation bug and introduces
a reusable `CacheManager` component.

**Stack:** PHP 8.2 · Yii2 2.0.55 · MySQL 8.0 · Docker · FileCache

---

## Table of contents

- [Bug summary](#bug-summary)
- [Quick start](#quick-start)
- [Architecture](#architecture)
- [API reference](#api-reference)
- [CacheManager component](#cachemanager-component)
- [Testing](#testing)
- [Project structure](#project-structure)
- [Configuration](#configuration)

---

## Bug summary

**Reported issue:** After editing a product, category listing pages continue
to show the old details for up to 60 seconds, even though the product's own
page updates immediately.

**Root cause:** `ProductController::actionUpdate` only invalidated the
single-product cache key (`product:{id}`). It never touched the
`category:{id}:products` key, which also contains the product's data.

**Fix:** The `CacheManager` component registers dependency generators for each
entity type. When a product is updated, `invalidateEntity('product', $id)`
deletes both the product cache and any category list cache that includes
that product.

Full reproduction steps and design rationale are in
[`DECISIONS.md`](DECISIONS.md).

---

## Quick start

### With Docker (recommended)

```bash
# From the assignment-1-cache-service/ directory:

# Build and start containers (first run takes ~2 min)
docker compose up -d

# Run database migrations (schema + seed data)
docker compose exec app php yii migrate --interactive=0

# Run the test suite
docker compose exec app php tests/run.php

# Test the API
curl http://localhost:8080/products/1
curl http://localhost:8080/categories/1/products
curl -X PUT http://localhost:8080/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name":"Airbus H125 (2024)","price":47000}'

# Stop containers
docker compose down
```

### Without Docker

```bash
# Prerequisites: PHP 8.0+, MySQL 5.7+, Composer

composer install
mysql -u root -e "CREATE DATABASE bmc_cache CHARACTER SET utf8mb4;"
# Edit config/db.php if your credentials differ
php yii migrate --interactive=0
php yii serve --docroot=web
# Service runs at http://localhost:8080
```

---

## Architecture

```
HTTP Request
    │
    ▼
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  Controller  │────▶│ CacheManager │────▶│  FileCache   │
│  (action)    │     │  (get/set/   │     │  (runtime/)  │
│              │     │   invalidate)│     │              │
└─────────────┘     └──────┬───────┘     └─────────────┘
                           │
                    ┌──────▼───────┐
                    │  Dependency   │
                    │  Generators   │
                    │  (per entity) │
                    └──────────────┘
```

### Request flow

1. **Read path:** Controller calls `$cache->get($key)`. On miss, queries the
   database, stores the result via `$cache->set($key, $data)`, returns it.

2. **Write path:** Controller saves to the database, then calls
   `$cache->invalidateEntity('product', $id)`. The CacheManager iterates all
   registered dependency generators for the `product` entity type, builds the
   cache keys, and deletes them from FileCache.

3. **No stale data:** The next read request for any affected cache key is a
   cache miss and re-populates from the database with fresh data.

### Key design decisions

| Decision | Rationale |
|---|---|
| CacheManager as a Yii component | Centralises cache logic; controllers never touch the cache backend directly |
| Dependency generators as closures | Each entity type declares its own cache keys; adding a new entity means adding one `registerDependencies()` call |
| Key prefix (`bmc_`) | Prevents collisions if multiple apps share the same cache directory |
| Explicit 60s TTL | Bounds staleness as a safety net; correct invalidation handles the rest |

Alternatives considered (TagDependency, ActiveRecord events) are discussed in
[`DECISIONS.md`](DECISIONS.md).

---

## API reference

All responses are JSON. The service runs at `http://localhost:8080`.

### `GET /products/{id}`

Returns a single product.

```bash
curl http://localhost:8080/products/1
```

```json
{
  "id": 1,
  "name": "Airbus H125",
  "category_id": 1,
  "price": "45000.00",
  "description": "Single-engine light utility helicopter."
}
```

### `GET /categories/{id}/products`

Returns all products in a category, sorted by ID.

```bash
curl http://localhost:8080/categories/1/products
```

```json
[
  {"id":1, "name":"Airbus H125", "category_id":1, "price":"45000.00", ...},
  {"id":2, "name":"Bell 407", "category_id":1, "price":"52000.00", ...}
]
```

### `PUT /products/{id}`

Updates a product. Accepts a JSON body. Returns the updated product.
All dependent caches are invalidated automatically.

```bash
curl -X PUT http://localhost:8080/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name":"Airbus H125 (2024)","price":47000}'
```

---

## CacheManager component

**File:** `components/CacheManager.php`
**Registered as:** `Yii::$app->cacheManager`

### Public methods

| Method | Signature | Description |
|---|---|---|
| `get` | `get(string $key): mixed` | Retrieve a value from cache (prefix applied) |
| `set` | `set(string $key, mixed $data, ?int $duration = null): void` | Store a value in cache |
| `invalidateEntity` | `invalidateEntity(string $entityType, int $id): int` | Delete all cache entries for an entity; returns count deleted |
| `registerDependencies` | `registerDependencies(string $entityType, array $generators): void` | Register cache-key generators for an entity type |

### Adding a new entity type

```php
Yii::$app->cacheManager->registerDependencies('booking', [
    fn(int $id): string => "booking:{$id}",
    fn(int $id): string => "user:" . Booking::findOne($id)->user_id . ":bookings",
]);

// Later, after a booking update:
Yii::$app->cacheManager->invalidateEntity('booking', $bookingId);
```

---

## Testing

```bash
docker compose exec app php tests/run.php
```

### Test suite (9 tests)

**CacheManager unit tests (5):**

| Test | Verifies |
|---|---|
| `set and get with prefix` | Key prefix is applied correctly |
| `get returns false on miss` | Cache miss returns `false` |
| `invalidateEntity deletes all dependent keys` | Custom entity invalidation works |
| `invalidateEntity returns 0 for unknown type` | Graceful handling of unregistered types |
| `duration is respected` | TTL config propagates to the manager |

**Integration tests (4):**

| Test | Verifies |
|---|---|
| `THE BUG: product update invalidates category cache` | **The original bug fix** — would fail with the old code |
| `product cache is invalidated on update` | Product-level cache is cleared |
| `get product returns correct data` | Basic model query works |
| `category 1 has 2 products` | Seed data is intact |

---

## Project structure

```
assignment-1-cache-service/
├── components/
│   └── CacheManager.php           # Reusable cache abstraction
├── config/
│   ├── console.php                # Console app config
│   ├── db.php                     # Database connection
│   └── web.php                    # Web app config (routes, cache, components)
├── controllers/
│   ├── CategoryController.php     # GET /categories/{id}/products
│   └── ProductController.php      # GET/PUT /products/{id}
├── migrations/
│   ├── m240101_000001_create_category.php
│   ├── m240101_000002_create_product.php
│   └── m240101_000003_seed_data.php
├── models/
│   ├── Category.php               # Category ActiveRecord
│   └── Product.php                # Product ActiveRecord
├── tests/
│   ├── bootstrap.php              # PHPUnit bootstrap
│   ├── CacheInvalidationTest.php  # PHPUnit integration tests
│   ├── CacheManagerTest.php       # PHPUnit unit tests
│   └── run.php                    # Standalone test runner
├── web/
│   ├── .htaccess                  # Apache rewrite rules
│   └── index.php                  # Web entry point
├── DECISIONS.md                   # Design rationale
├── Dockerfile                     # PHP 8.2 CLI image
├── docker-compose.yml             # MySQL + PHP containers
├── composer.json                  # Dependencies
├── phpunit.xml                    # PHPUnit configuration
├── yii                            # CLI entry point
└── README.md                      # This file
```

---

## Configuration

### Database (`config/db.php`)

Uses environment variables when running in Docker:

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_NAME` | `bmc_cache` | Database name |
| `DB_USER` | `root` | MySQL username |
| `DB_PASS` | (empty) | MySQL password |

### CacheManager (`config/web.php`)

| Property | Value | Description |
|---|---|---|
| `duration` | `60` | Default TTL in seconds |
| `keyPrefix` | `bmc_` | Prefix for all cache keys |

### FileCache

Cache files are stored in `runtime/cache/`. No Redis, Memcached, or
third-party cache is used.
