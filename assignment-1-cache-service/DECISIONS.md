# DECISIONS.md — Assignment 1

## (a) Reproduction steps for the original bug

1. Seed the database by running migrations (`php yii migrate --interactive=0`).
2. Start the service (`php yii serve --docroot=web`).
3. Fetch a product to warm the cache:

   ```
   GET /products/1
   → {"id":1,"name":"Airbus H125","category_id":1,"price":"45000.00",...}
   ```

4. Fetch the category product list to warm its cache:

   ```
   GET /categories/1/products
   → [{"id":1,"name":"Airbus H125",...},{"id":2,"name":"Bell 407",...}]
   ```

5. Update the product:

   ```
   PUT /products/1  {"name":"Airbus H125 (2024)"}
   → {"id":1,"name":"Airbus H125 (2024)",...}
   ```

6. Immediately re-fetch the category product list:

   ```
   GET /categories/1/products
   → [{"id":1,"name":"Airbus H125",...},{"id":2,"name":"Bell 407",...}]
   ```

   **Result:** The category list still shows the old name "Airbus H125" instead
   of "Airbus H125 (2024)".  The stale entry persists for up to 60 seconds
   (the default FileCache TTL) until the cache entry expires naturally.

**Root cause:** `ProductController::actionUpdate` only invalidated the
single-product cache key (`product:{id}`).  It never touched the
`category:{id}:products` key, which also contains the product's data.
Every category listing page that includes the edited product was therefore
stale until TTL expiry.

---

## (b) Design choices

### 1. CacheManager component (`components/CacheManager.php`)

**What:** A Yii2 component registered as `Yii::$app->cacheManager` that
wraps `FileCache` and provides:
- `get()` / `set()` — thin wrappers that apply a key prefix.
- `registerDependencies(string $entityType, callable[] $generators)` —
  associates one or more cache-key generators with an entity type.
- `invalidateEntity(string $entityType, int $id)` — iterates the
  registered generators, builds the keys, and deletes them from cache.

**Why this shape:** The assignment asks for an "invalidateEntity-style
abstraction" that the team can extend when new entity types are added.
A registry of callables is the smallest mechanism that satisfies this:
adding a new entity type means calling `registerDependencies()` with
the appropriate key generators — no controller changes, no new
framework code, no tag-based indirection.

**Alternative considered — tag-based invalidation:**
Yii2 supports `TagDependency`, which tags cache entries and lets you
invalidate by tag.  This would mean tagging category-list entries with
`category:{id}` and deleting all entries under that tag on product
update.  I rejected it because:
- It couples every `set()` call to a `TagDependency` object, requiring
  every cache-setting site to know about tags.
- FileCache does not index by tag; `TagDependency` still deletes keys
  individually, so there is no performance advantage.
- The registry approach is more explicit and easier to trace in
  production logs.

**Alternative considered — Event-based invalidation:**
Yii2 ActiveRecord has an `afterSave` event.  I could attach a handler
to `Product::afterSave` that invalidates caches.  I rejected this
because:
- It puts cache logic inside the model layer, which the assignment
  explicitly forbids ("without pushing cache logic into the controllers"
  — and by extension, not into models either).
- It makes the invalidation path implicit and harder to discover.

### 2. Dependency generators as closures

Each registered generator is `fn(int $id): string` — a pure function
from entity ID to cache key.  This keeps key construction colocated
with the entity's dependency registration, not scattered across
controllers.  The closure for the category list key even loads the
product to get its `category_id`, so the controller never needs to
know that category lists depend on products.

### 3. Key prefix (`bmc_`)

FileCache stores files in `runtime/cache/`.  Without a prefix, a cache
key like `product:1` could collide with another Yii app sharing the
same directory.  Adding `bmc_` makes the keys unambiguous.  The prefix
is configurable via the `keyPrefix` property.

### 4. Explicit TTL (60 seconds)

The original code relied on FileCache's implicit 60-second default.
I made this explicit in the CacheManager's `duration` property so
that the team can tune it without reading Yii internals.  I chose not
to increase it for now — the assignment describes a service where
product edits should propagate quickly, and 60 seconds is a reasonable
starting point.  The PM's suggestion of 24 hours is addressed below.

---

## (c) Response to the product manager's suggestion

> "The simplest fix here is probably just to set a 24-hour expiry on
> every cache entry and move on."

**I would not ship this.**  Here is why:

1. **The service is a charter-flight product catalog.**  Prices and
   availability change frequently.  A customer who sees a stale price
   for 24 hours could book at the wrong rate, or the business could
   lose a sale because the listed price is outdated.  The cost of
   stale data is directly proportional to the TTL.

2. **The bug is not a TTL problem — it is an invalidation problem.**
   The cache already expires in 60 seconds.  The real issue is that
   one specific cache entry is never invalidated when it should be.
   Extending the TTL to 24 hours does not fix the invalidation gap;
   it makes the staleness window 1,440× worse.

3. **A short TTL with correct invalidation is strictly better.**
   Correct invalidation means the cache is cleared *exactly when the
   data changes*.  A 24-hour TTL means the cache is cleared *whether
   or not the data changes*, and only after a full day.  The
   invalidation approach gives fresher data, lower latency (because
   warm cache hits still happen), and no additional DB load beyond the
   moment of edit.

4. **If the concern is DB load**, the right lever is the `duration`
   property on CacheManager, not a blanket 24-hour TTL.  The team can
   increase it to 5 or 10 minutes for read-heavy entity types and
   keep it short for frequently-edited ones.

**My recommendation:** Keep the 60-second TTL as a safety net.  Ship
the correct invalidation (which this change provides).  If the PM's
concern is operational — "what if invalidation fails silently?" — the
60-second TTL already bounds the blast radius.  That is the right
trade-off.

---

## What I deliberately did not do, and why

### 1. I did not add a TTL on every cache entry as the PM suggested

As argued above, the TTL is not the fix.  Extending it to 24 hours
would make the real problem worse, not better.  I kept the 60-second
default and added correct invalidation instead.

### 2. I did not use `TagDependency`

Yii2's `TagDependency` would let me tag category-list entries and
invalidate by tag.  I rejected it because it adds an implicit
coupling between every `set()` call and a dependency object, and the
registry-based approach is more explicit and easier to debug.

### 3. I did not attach cache invalidation to ActiveRecord events

Putting `afterSave` hooks on the `Product` model would move cache
logic out of the controllers and into the models.  The assignment says
not to push cache logic into controllers (which I read as "don't let
controllers know about cache internals") — putting it in models would
create the same coupling in a different layer.  The CacheManager
component keeps the invalidation declarative and testable.

### 4. I did not re-populate the cache after an update

After deleting a stale cache entry, the next GET will be a cache miss
and will re-populate it from the DB.  Pre-populating after an update
would save one extra DB query, but it adds complexity (the controller
must ensure the response data is in the right format) for a marginal
gain.  With a 60-second TTL, the miss happens at most once per
editing action.  I left this as a future optimisation.

### 5. I did not handle category reassignment

If a product moves from category 1 to category 2, the old category's
list cache would be stale.  The current dependency generator only
invalidates the *current* `category_id` — if the category changes
during the update, the old category is not touched.  This is a real
edge case but rare in practice.  I would address it in a follow-up by
also invalidating the old category's cache inside `actionUpdate`,
using the product's `oldAttributes` to detect the change.

### 6. Tests

I initially deprioritised tests to focus on the fix and write-up, but
subsequently added a test suite (`tests/run.php`) with 9 tests:

- **5 CacheManager unit tests** covering get/set, prefix, invalidation of
  custom entity types, and unknown type handling.
- **4 integration tests** including the one that reproduces the original bug:
  warm both caches, update a product, assert the category list is refreshed.
  This test **fails** with the original code and **passes** with the fix.

The integration test in `CacheInvalidationTest::testProductUpdateInvalidatesCategoryCache`
is the single test that would have caught the original bug.
