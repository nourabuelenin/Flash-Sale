# Flash Sale System

A high-concurrency flash sale system built with Laravel, designed to handle inventory reservations, payment processing, and webhook idempotency.

## Features

- **Inventory Hold System**: Reserve stock for customers during checkout
- **Pessimistic Locking**: Prevent overselling with database row locking
- **Idempotent Webhooks**: Handle duplicate payment notifications safely
- **Automatic Hold Release**: Background job to expire and release abandoned holds
- **Immediate Failure Recovery**: Release stock instantly when payments fail

## System Requirements

- PHP 8.1+
- MySQL/PostgreSQL
- Composer
- Node.js & NPM (for frontend assets)

## Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd flash-sale
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure database**
   Edit `.env` and set your database credentials:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=flash_sale
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

5. **Run migrations**
   ```bash
   php artisan migrate
   ```

6. **Seed test data** (optional)
   ```bash
   php artisan db:seed
   ```

## Running the Application

### 1. Start the Web Server

```bash
php artisan serve
```

The application will be available at `http://localhost:8000`

### 2. Start the Queue Worker (Important!)

The queue worker processes background jobs. **You must run this for the system to work properly.**

```bash
php artisan queue:work --tries=3
```

**Options:**
- `--tries=3`: Retry failed jobs up to 3 times
- `--timeout=60`: Maximum execution time per job (seconds)
- `--sleep=3`: Seconds to wait when no jobs are available

**For production**, use a process manager like Supervisor to keep the queue worker running:

```ini
[program:flash-sale-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/flash-sale/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasflocalhost=unexpected
stopwaitsecs=3600
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/path/to/flash-sale/storage/logs/worker.log
```

### 3. Start the Scheduler (for hold expiry)

The scheduler runs periodic tasks like releasing expired holds.

**Development:**
```bash
php artisan schedule:work
```

**Production** - Add to your crontab:
```bash
* * * * * cd /path/to/flash-sale && php artisan schedule:run >> /dev/null 2>&1
```

Or run the command manually:
```bash
php artisan holds:release
```

## Architecture Overview

### Inventory Hold System

1. **Create Hold**: When a user initiates checkout
   - Uses pessimistic locking (`lockForUpdate()`)
   - Decrements stock atomically
   - Creates a 2-minute hold record

2. **Payment Processing**: 
   - Webhook receives payment result
   - Success → Mark order as completed
   - Failure → **Immediately release stock**

3. **Hold Expiry**:
   - Scheduled job runs every minute
   - Releases expired holds
   - Returns stock to inventory

### Idempotency

Webhooks use an idempotency key to prevent duplicate processing:
- First request → Process and cache response
- Duplicate request → Return cached response
- Uses database transaction with locking to prevent race conditions

## API Endpoints

Full API documentation with examples and rate limits.

### 1. View Product
Get product details including available stock.

**Endpoint**: `GET /api/products/{id}`  
**Rate Limit**: 100 requests/minute

**Example Request**:
```bash
curl http://localhost:8000/api/products/1
```

**Response**:
```json
{
  "id": 1,
  "name": "Limited Edition Product",
  "price": "99.99",
  "available_stock": 50
}
```

---

### 2. Create Hold
Reserve product inventory for 2 minutes.

**Endpoint**: `POST /api/holds`  
**Rate Limit**: 20 requests/minute

**Example Request**:
```bash
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'
```

**Request Body**:
```json
{
  "product_id": 1,
  "qty": 2
}
```

**Response**:
```json
{
  "hold_id": 123,
  "token": "a1b2c3d4e5f6...",
  "expires_at": "2025-12-02T10:32:00.000000Z"
}
```

**Status Codes**:
- `201`: Hold created successfully
- `400`: Invalid input
- `404`: Product not found
- `409`: Insufficient stock
- `429`: Too many requests

---

### 3. Create Order
Convert a hold into a pending order.

**Endpoint**: `POST /api/orders`  
**Rate Limit**: 20 requests/minute

**Example Request**:
```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 123, "token": "your-hold-token"}'
```

**Request Body**:
```json
{
  "hold_id": 123,
  "token": "a1b2c3d4e5f6..."
}
```

**Response**:
```json
{
  "order_id": 456,
  "status": "pending",
  "amount": "199.98"
}
```

**Status Codes**:
- `201`: Order created successfully
- `400`: Hold expired or already used
- `403`: Invalid hold or token
- `429`: Too many requests

---

### 4. Payment Webhook
Handle payment provider callbacks (idempotent).

**Endpoint**: `POST /api/payments/webhook`  
**Rate Limit**: 1000 requests/minute

**Example Request (Success)**:
```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Idempotency-Key: unique-payment-id-123" \
  -H "Content-Type: application/json" \
  -d '{"order_id": 456, "status": "success", "txn_id": "TXN-789"}'
```

**Example Request (Failure)**:
```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Idempotency-Key: unique-payment-id-456" \
  -H "Content-Type: application/json" \
  -d '{"order_id": 456, "status": "failed"}'
```

**Request Body (Success)**:
```json
{
  "order_id": 456,
  "status": "success",
  "txn_id": "TXN-789"
}
```

**Request Body (Failure)**:
```json
{
  "order_id": 456,
  "status": "failed"
}
```

**Response**:
```json
{
  "status": "processed",
  "order_status": "completed"
}
```

**Important**: Duplicate requests with the same `Idempotency-Key` return the cached response without reprocessing.

---

### Complete Purchase Flow Example

```bash
# 1. View product
curl http://localhost:8000/api/products/1

# 2. Create hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'

# 3. Create order
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 123, "token": "your-hold-token"}'

# 4. Payment webhook (from payment provider)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Idempotency-Key: unique-payment-id" \
  -H "Content-Type: application/json" \
  -d '{"order_id": 456, "status": "success", "txn_id": "TXN-789"}'
```

## Testing

### Run All Tests
```bash
php artisan test
```

**Test Results**: ✅ 32 tests passing, 87 assertions

### Test Suites:
- **ConcurrentHoldTest** (5 tests) - Race conditions and concurrent access
- **WebhookIdempotencyTest** (6 tests) - Duplicate webhook handling
- **HoldExpiryTest** (9 tests) - Hold expiration and cleanup
- **StockConsistencyTest** (10 tests) - Data integrity checks

### Run Specific Test Suite
```bash
php artisan test --filter ConcurrentHoldTest
php artisan test --filter WebhookIdempotencyTest
php artisan test --filter HoldExpiryTest
php artisan test --filter StockConsistencyTest
```

### Additional Test Options
```bash
# With detailed output
php artisan test --testdox

# Stop on first failure
php artisan test --stop-on-failure

# Run in parallel (faster)
php artisan test --parallel
```

### Check Queue Jobs
```bash
# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Monitor Logs
```bash
tail -f storage/logs/laravel.log
```

## Configuration

### Queue Settings

Edit `config/queue.php` or set in `.env`:

```env
QUEUE_CONNECTION=database  # Uses database driver (default)
# or
QUEUE_CONNECTION=redis     # Better for high traffic
```

### Hold Expiry Time

Default: 2 minutes (configured in `InventoryService.php`)

```php
'tmExpire' => now()->addMinutes(2)
```

## Troubleshooting

### Stock not releasing?
- Check if queue worker is running: `ps aux | grep "queue:work"`
- Check failed jobs: `php artisan queue:failed`
- Manually release: `php artisan holds:release`

### Duplicate webhook processing?
- Ensure `strIdempotencyKey` column has UNIQUE constraint
- Check logs for race condition warnings

### Performance issues?
- Consider switching to Redis queue: `QUEUE_CONNECTION=redis`
- Increase queue worker processes (Supervisor `numprocs`)
- Add database indexes on frequently queried columns

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
