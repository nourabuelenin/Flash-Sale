# Flash Sale System - Test Suite

This directory contains comprehensive automated tests for the flash sale system.

## Test Coverage

### 1. ConcurrentHoldTest.php
Tests for concurrent access and race conditions:
- ✅ Concurrent holds prevent overselling
- ✅ Last item race condition handling
- ✅ Hold respects exact quantities
- ✅ Pessimistic locking works correctly
- ✅ Failed holds don't decrement stock (rollback)

### 2. WebhookIdempotencyTest.php
Tests for webhook idempotency and duplicate handling:
- ✅ Duplicate webhooks return cached response
- ✅ Missing idempotency key returns error
- ✅ Concurrent webhooks only process once
- ✅ Non-existent order returns 404
- ✅ Successful payment updates order
- ✅ Failed payment releases stock immediately

### 3. HoldExpiryTest.php
Tests for hold expiration and release:
- ✅ Expired holds are released
- ✅ Non-expired holds are not released
- ✅ Already released holds are skipped
- ✅ Converted holds are not released
- ✅ Multiple expired holds batch release
- ✅ Manual hold release works
- ✅ Releasing non-existent hold throws exception
- ✅ Releasing already released hold throws exception
- ✅ Artisan command executes correctly

### 4. StockConsistencyTest.php
Tests for stock integrity and consistency:
- ✅ Stock consistency after full cycle
- ✅ Cache invalidation on stock change
- ✅ Stock consistency after rollback
- ✅ Stock never goes negative
- ✅ Hold count matches stock reduction
- ✅ Partial hold releases
- ✅ Stock returned after payment failure
- ✅ Available stock calculation
- ✅ Stock integrity across multiple products
- ✅ Completed orders don't return stock

## Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Test Suite
```bash
# Concurrent hold tests
php artisan test --filter ConcurrentHoldTest

# Webhook idempotency tests
php artisan test --filter WebhookIdempotencyTest

# Hold expiry tests
php artisan test --filter HoldExpiryTest

# Stock consistency tests
php artisan test --filter StockConsistencyTest
```

### Run Specific Test Method
```bash
php artisan test --filter test_concurrent_holds_prevent_overselling
```

### Run with Coverage (requires Xdebug)
```bash
php artisan test --coverage
```

### Run in Parallel (faster)
```bash
php artisan test --parallel
```

## Test Database

Tests use the `RefreshDatabase` trait which:
- Creates a fresh database for each test
- Rolls back after each test
- Ensures test isolation

Configuration in `phpunit.xml`:
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

## Continuous Integration

Add to your CI/CD pipeline:

```yaml
# GitHub Actions example
- name: Run Tests
  run: |
    php artisan test --parallel --coverage
```

## Writing New Tests

Follow the existing patterns:

1. Use `RefreshDatabase` trait
2. Create test data in each test method
3. Use descriptive test names: `test_what_scenario_expected_result`
4. Assert both positive and negative cases
5. Test edge cases and error conditions

## Test Assertions

Common assertions used:
- `assertEquals()` - Exact value match
- `assertTrue()` / `assertFalse()` - Boolean checks
- `assertNotNull()` / `assertNull()` - Null checks
- `assertDatabaseHas()` - Database record exists
- `expectException()` - Exception handling
- `assertStatus()` - HTTP status codes
- `assertJson()` - JSON response structure

## Debugging Failed Tests

```bash
# Run with verbose output
php artisan test --filter test_name -v

# Stop on first failure
php artisan test --stop-on-failure

# Show detailed errors
php artisan test --testdox
```

## Performance Benchmarks

Expected test execution times:
- ConcurrentHoldTest: ~2-3 seconds
- WebhookIdempotencyTest: ~3-4 seconds
- HoldExpiryTest: ~2-3 seconds
- StockConsistencyTest: ~3-4 seconds

Total suite: ~10-15 seconds

## Test Data Factories

Use factories for consistent test data:

```php
$product = Product::factory()->create([
    'intStock' => 100,
]);
```

## Troubleshooting

### Database locked errors
- Use `:memory:` SQLite database
- Or ensure proper transaction cleanup

### Cache not clearing
- Tests automatically clear cache between runs
- Check `setUp()` method in test classes

### Timing issues
- Use `now()->subMinutes()` for expired holds
- Use `now()->addMinutes()` for valid holds
