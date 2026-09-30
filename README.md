# Laravel URL Shortener

A URL-shortening API built with Laravel 12. It generates deterministic Base62 short codes, stores URLs in a relational database, and uses a Redis-compatible Laravel cache store to speed up redirects.

## Architecture

- **Database:** source of truth for URLs and idempotency records.
- **Cache:** optional acceleration layer for redirects. Cache errors are logged and requests fall back to the database.
- **Base62:** converts each database ID into a compact, unique generated short code and retries bounded fallback candidates if a concurrent claim collides.
- **Custom aliases:** callers may request a memorable code that is validated, normalized, and claimed atomically.
- **Idempotency:** repeated requests with the same `Idempotency-Key` and payload replay the original response. Reusing a key with a different URL, expiration, or alias returns `409 Conflict`.

## Requirements

- PHP 8.2 or newer with the cURL and Mbstring extensions
- Composer 2
- Node.js 22 and npm
- SQLite for the simplest local setup, or MySQL for production-like usage
- Redis when using `CACHE_STORE=redis`

## Local setup

```bash
git clone https://github.com/AkinAgbejoye/url_shortener.git
cd url_shortener
composer install --no-interaction --prefer-dist
npm ci
cp .env.example .env
php artisan key:generate --no-interaction
touch database/database.sqlite
php artisan migrate --force --no-interaction
npm run build
php artisan test
npm test
php artisan serve
```

The API is available at `http://localhost:8000`. The example environment uses the dependency-free `array` cache; set `CACHE_STORE=redis` for a persistent, production-like cache.

## Docker setup

Create the application environment before building because the application image reads it at runtime:

```bash
cp .env.example .env
php artisan key:generate
docker compose up --build -d
docker compose exec app1 php artisan migrate --force
```

The Nginx load balancer listens on `http://localhost:8080` and distributes requests across three application containers. MySQL is exposed on port `3307` and Redis on `6379` for local inspection.

## API

### Create a short URL

`POST /api/v1/urls`

```bash
curl -X POST http://localhost:8000/api/v1/urls \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: homepage-001' \
  -d '{"long_url":"https://example.com/a/long/path","custom_alias":"launch-page","expires_at":"2027-01-01T00:00:00Z"}'
```

Successful creation returns `201 Created`:

The response includes an `X-Management-Token` header. Save this token immediately: it is returned only for a newly created URL and cannot be recovered later.

```json
{
  "id": 1,
  "short_code": "launch-page",
  "short_url": "http://localhost:8000/launch-page",
  "long_url": "https://example.com/a/long/path",
  "expires_at": "2027-01-01T00:00:00+00:00",
  "status": "active"
}
```

`expires_at` is optional and must be a future ISO-8601 timestamp with an explicit timezone. Values are normalized to UTC. The default maximum lifetime is 365 days and can be changed with `URL_MAX_LIFETIME_DAYS`.

`custom_alias` is optional. Empty or omitted aliases keep generated-code behavior. Provided aliases are trimmed, lowercased, and must be 3-48 characters of lowercase letters, numbers, and single hyphens between groups, such as `spring-sale-2027`. The reserved route names `admin`, `api`, `assets`, `build`, `dashboard`, `health`, `login`, `logout`, `register`, `status`, `storage`, and `up` cannot be claimed. Defaults can be changed with `URL_ALIAS_MIN_LENGTH`, `URL_ALIAS_MAX_LENGTH`, and `config/url_shortener.php`.

Duplicate custom aliases return `409 Conflict` with a field-specific error:

```json
{
  "message": "The custom alias has already been taken.",
  "errors": {
    "custom_alias": ["The custom alias has already been taken."]
  }
}
```

Repeating the request with the same idempotency key, URL, expiration, and canonical alias returns the stored response with `200 OK`. Using that key for another URL, expiration, or alias returns `409 Conflict`. The header is optional and has a maximum length of 255 characters. Generated links remain available under concurrent short-code collisions because the allocator retries bounded fallback candidates before failing the request.

### Manage a short URL

Management requests require the original `X-Management-Token` header:

- `GET /api/v1/urls/{shortCode}` inspects lifecycle state.
- `PATCH /api/v1/urls/{shortCode}` updates or clears `expires_at`.
- `POST /api/v1/urls/{shortCode}/disable` disables redirects.
- `POST /api/v1/urls/{shortCode}/enable` re-enables redirects unless the URL has expired.
- `DELETE /api/v1/urls/{shortCode}` permanently removes anonymous management access and soft-deletes the URL.

```bash
curl -X POST http://localhost:8000/api/v1/urls/1/disable \
  -H 'Accept: application/json' \
  -H 'X-Management-Token: your-64-character-token'
```

Only a SHA-256 hash of the token is stored. Missing, incorrect, unknown, and deleted credentials return the same `404 Not Found` response. Tokens are never returned by idempotent replays, cannot be recovered, and should be kept out of URLs, logs, analytics, and source control.

The browser UI can request custom aliases, set expiration in local time, and store recent-link management tokens in local storage so it can update expiration, disable, enable, or delete those links later. Tokens are never rendered into page markup. Clearing recent history or browser storage permanently removes this local management access.

### Read URL analytics

`GET /api/v1/urls/{shortCode}/analytics?range=30d` returns aggregate redirect counts to callers that provide the original `X-Management-Token` header. The range must be a positive number of days followed by `d`, cannot exceed `URL_ANALYTICS_MAX_QUERY_DAYS`, and defaults to `30d`. These requests share the management limit of 30 requests per minute per client. Missing, invalid, unknown, and deleted credentials use the same `404 Not Found` response and do not reveal whether a short code exists.

```json
{
  "range": "3d",
  "timezone": "UTC",
  "start_date": "2026-09-28",
  "end_date": "2026-09-30",
  "total_redirects": 4,
  "series": [
    { "date": "2026-09-28", "redirect_count": 1 },
    { "date": "2026-09-29", "redirect_count": 0 },
    { "date": "2026-09-30", "redirect_count": 3 }
  ]
}
```

`start_date` and `end_date` are inclusive UTC calendar dates. `series` is chronological, contains one zero-filled entry per requested day, and `total_redirects` is the sum of that series. The response never includes a URL ID, short code, alias, destination, management token, raw event, IP address, user agent, referrer, query string, cookie, or visitor identifier.

Only successful `GET /{shortCode}` responses that resolve to an active destination are counted. Each request increments one aggregate bucket for the UTC day when the redirect is handled; repeated requests, bots, and retries each count because no visitor identifier is collected. Missing, expired, disabled, and deleted links and all API or management requests do not count. Analytics persistence runs before the redirect response but is failure-isolated, so a database or metrics failure cannot replace the redirect with an analytics error.

### Follow a short URL

`GET /{shortCode}` redirects to the original URL. Unknown, expired, disabled, and deleted codes return `404 Not Found`.

### Health check

`GET /api/health` returns `{"status":"ok"}`. Laravel's framework health endpoint is also available at `GET /up`.

## Quality checks

```bash
composer test
composer test:coverage
vendor/bin/pint --test
composer audit
npm run lint
npm run format:check
npm test
npm run test:e2e
npm audit --audit-level=high
npm run build
```

Backend tests use an in-memory SQLite database and cache, while frontend unit tests use Vitest and jsdom. Playwright runs the full browser journey against an isolated `database/e2e.sqlite` database. Install its browser once with `npx playwright install chromium`. Together the suites cover creation, custom aliases, validation, idempotency, transaction rollback, generated collision fallback, cache concurrency and failures, redirects, Base62 conversion, form submission, field-specific errors, clipboard behavior, history, themes, lifecycle management, and the complete shortening journey. `composer test:coverage` enforces the same 70% minimum used in CI and requires PCOV or Xdebug. Successful CI runs retain a machine-readable Clover report as the `backend-coverage-clover` artifact for 14 days.

### Offline verification

The committed `composer.lock` and `package-lock.json` pin the dependency graph. After `vendor/`, `node_modules/`, and the Playwright Chromium binary have been installed once, disconnecting the network does not change the verification path: backend tests use SQLite, the array cache, frozen clocks, and fake metric exporters; browser tests use only the local application and block non-local HTTP requests.

```bash
git ls-files --error-unmatch composer.lock package-lock.json
composer validate --strict
touch database/e2e.sqlite
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=database/e2e.sqlite CACHE_STORE=array php artisan migrate:fresh --force --no-interaction
composer test
npm test
npm run test:e2e
vendor/bin/pint --test
npm run lint
npm run format:check
npm run build
```

The block intentionally contains no install command. Run `composer install --no-interaction --prefer-dist`, `npm ci`, and `npx playwright install chromium` before going offline if those artifacts are not already present. Dependency audits require advisory data and therefore remain an online CI gate.

GitHub Actions runs tests with a 70% minimum coverage threshold, Pint, ESLint, Prettier, dependency audits, and the frontend build on every pull request and push to `main`. Use `npm run format` to apply the JavaScript formatting rules locally. Dependabot checks Composer, npm, and GitHub Actions dependencies weekly, groups routine minor and patch updates by ecosystem, and opens security updates for vulnerable dependencies.

## Operational notes

- Configure a persistent database and `CACHE_STORE=redis` in production.
- Keep `APP_DEBUG=false` and provide a unique `APP_KEY`.
- Metrics are disabled by default. Set `METRICS_DRIVER=statsd` and configure `METRICS_STATSD_HOST`, `METRICS_STATSD_PORT`, and `METRICS_PREFIX` to send counters and timings to a StatsD-compatible agent.
- The metrics are `<prefix>.requests_total`, `<prefix>.request_duration_ms`, `<prefix>.cache_operations_total`, `<prefix>.alias_allocations_total`, and `<prefix>.lifecycle_cleanup_total`. Their bounded labels describe only operation, outcome, alias type, and cleanup record type; aliases, URLs, short codes, request IDs, management tokens, idempotency keys, IP addresses, and user agents are never exported.
- Useful starting alerts are any sustained cache operation failure, a request `error` rate above 1% for five minutes, p95 request duration above 250 ms, custom alias conflict spikes above the normal campaign baseline, or any generated alias exhaustion event. Tune these thresholds from observed production traffic.
- Unhandled exceptions are written as JSON to `storage/logs/exceptions-YYYY-MM-DD.log` through the dedicated `LOG_EXCEPTION_CHANNEL`. Set that variable to another configured channel such as `stderr` or `papertrail` for external collection, or to `null` to disable reporting.
- External exception delivery is disabled when `SENTRY_LARAVEL_DSN` is empty. To enable Sentry, set that DSN plus `SENTRY_ENVIRONMENT` and `SENTRY_RELEASE`; use `SENTRY_SAMPLE_RATE` from `0.0` to `1.0` to control the proportion of error events sent.
- Sentry is used only as an exception transport. Automatic integrations, performance tracing, logs, metrics, and breadcrumbs are disabled. Reports retain the exception type and stack with a generic message, plus the request ID, URL path, HTTP method, and bounded user-agent. A final sanitizer removes request bodies, query strings, full URLs, credentials, headers, idempotency keys, user data, breadcrumbs, extras, and stack variables.
- Local and external reporting failures are isolated and never alter the original application response.
- The creation endpoint is limited to 10 requests per minute per client.
- Treat custom aliases as a public namespace. Keep the reserved list aligned with current and planned routes, monitor conflict-rate spikes for abuse or enumeration, and avoid placing sensitive campaign names in aliases before they are public.
- Alias allocation logs use structured event names such as `url_alias_allocation_conflict`, `url_alias_allocation_retry`, and `url_alias_allocation_exhausted` with bounded context only. They never include the requested alias, destination URL, management token, or idempotency key.
- Versioned cache entries expire after 24 hours or at the URL expiration time, whichever comes first, and are rebuilt from the database on demand. Legacy, malformed, unsafe, and expired cache values are discarded.

### Analytics operations and privacy

Analytics stores only a URL foreign key, UTC bucket date, aggregate redirect count, and database timestamps in `url_analytics_daily`. There is no raw click table. Application logs and metrics must not contain URL IDs, short codes, aliases, destinations, management tokens, request IDs, idempotency keys, IP addresses, user agents, referrers, query strings, cookies, or visitor identifiers.

Every analytics environment setting and its default is listed below:

| Variable | Default | Purpose |
| --- | ---: | --- |
| `URL_ANALYTICS_MAX_QUERY_DAYS` | `90` | Maximum API range and minimum allowed retention period. |
| `URL_ANALYTICS_RETENTION_DAYS` | `365` | Whole UTC days retained before today. |
| `URL_ANALYTICS_CLEANUP_BATCH_SIZE` | `500` | Buckets examined per deletion batch; valid command values are 1-1,000. |
| `URL_ANALYTICS_CLEANUP_TIME` | `03:15` | Daily scheduler time in the application timezone, UTC by default. |

Analytics uses the shared metrics settings `METRICS_DRIVER=null`, `METRICS_PREFIX=url_shortener`, `METRICS_STATSD_HOST=127.0.0.1`, `METRICS_STATSD_PORT=8125`, and `METRICS_STATSD_TIMEOUT=0.2`. With StatsD enabled, `<prefix>.analytics_redirects_total` has only `outcome=recorded|failed`; `<prefix>.analytics_cleanup_total` has only `scope=batch|run` and the applicable `outcome=deleted|skipped|failed|succeeded`. Transport failures are isolated by `metrics_export_failed`, whose context is limited to `metric` and `exception_class`.

Structured analytics events and their complete contexts are:

- `analytics_recorded`: `outcome`, `bucket_date`.
- `analytics_record_failed`: `outcome`, `bucket_date`, `exception_class`.
- `url_analytics_cleanup_batch_failed`: `batch`, `examined`, `deleted`, `skipped`, `failed`, `exception_class`.
- `url_analytics_cleanup_completed`: `dry_run`, `batch_size`, `retention_days`, `cutoff_date`, `examined`, `deleted`, `skipped`, `failed`.

Alert on any sustained `analytics_redirects_total{outcome=failed}` increase, any cleanup batch or run failure, and the absence of a successful cleanup run for more than 26 hours. A useful starting threshold is five recorder failures in five minutes; tune it against redirect volume. The redirect success rate remains the primary availability signal because analytics failures intentionally do not fail redirects.

The daily cleanup deletes only buckets strictly older than the UTC cutoff, in bounded batches. Preview and then run the same cutoff explicitly when investigating or changing retention:

```bash
php artisan urls:prune-analytics --dry-run --batch-size=500 --retention-days=365
php artisan urls:prune-analytics --batch-size=500 --retention-days=365
```

Take and verify a database backup before reducing retention or clearing a backlog. Analytics rows cascade when a URL is permanently deleted, and pruned buckets cannot be reconstructed because raw events are deliberately not stored. Recovery therefore requires a backup from before the deletion: stop the scheduler, restore the backup to staging, verify the required URL and bucket rows, copy only those rows into production while preserving the `(url_id, date)` uniqueness constraint, then re-enable the schedule. Restoring analytics is optional for redirect availability and should never require restoring visitor-level data.

### Lifecycle cleanup

The scheduler permanently deletes soft-deleted URLs and idempotency records once they reach the configured retention cutoff. It runs daily at `URL_CLEANUP_TIME` (default `02:30` UTC), loads at most `URL_CLEANUP_BATCH_SIZE` records at a time (default `100`), and retains records for `URL_CLEANUP_RETENTION_DAYS` days (default `30`). Overlap and single-server locks prevent duplicate scheduled runs; production nodes must share a persistent cache such as Redis for those locks.

Add Laravel's scheduler to the production cron table on one or more application nodes:

```cron
* * * * * cd /path/to/url-shortener && php artisan schedule:run >> /dev/null 2>&1
```

Preview the exact eligible set before changing retention or running cleanup manually:

```bash
php artisan urls:prune-lifecycle --dry-run --batch-size=100 --retention-days=30
php artisan urls:prune-lifecycle --batch-size=100 --retention-days=30
```

Each run logs `url_lifecycle_cleanup_completed` with examined, deleted, skipped, and failed counters. Individual failures log `url_lifecycle_cleanup_record_failed`, do not stop later records, and make the command exit unsuccessfully for alerting. Re-running is safe.

Take and verify a database backup before reducing retention or manually deleting a large backlog. Soft-deleted URLs remain recoverable directly from the database only until the cutoff; after cleanup, both those URLs and expired idempotent replay responses require backup restoration. To recover, stop scheduled cleanup, restore the affected rows from a backup into a staging database, verify them, and then copy only the required records into production before re-enabling the schedule.

## License

This project is open-sourced under the [MIT License](https://opensource.org/licenses/MIT).

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, quality checks, and commit guidance. Release-facing changes are tracked in [CHANGELOG.md](CHANGELOG.md).
