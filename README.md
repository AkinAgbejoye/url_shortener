# Laravel URL Shortener

A URL-shortening API built with Laravel 12. It generates deterministic Base62 short codes, stores URLs in a relational database, and uses a Redis-compatible Laravel cache store to speed up redirects.

## Architecture

- **Database:** source of truth for URLs and idempotency records.
- **Cache:** optional acceleration layer for redirects. Cache errors are logged and requests fall back to the database.
- **Base62:** converts each database ID into a compact, unique short code.
- **Idempotency:** repeated requests with the same `Idempotency-Key` and payload replay the original response. Reusing a key with a different URL returns `409 Conflict`.

## Requirements

- PHP 8.2 or newer
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
  -d '{"long_url":"https://example.com/a/long/path"}'
```

Successful creation returns `201 Created`:

```json
{
  "id": 1,
  "short_code": "1",
  "short_url": "http://localhost:8000/1",
  "long_url": "https://example.com/a/long/path"
}
```

Repeating the request with the same idempotency key and URL returns the stored response with `200 OK`. Using that key for another URL returns `409 Conflict`. The header is optional and has a maximum length of 255 characters.

### Follow a short URL

`GET /{shortCode}` redirects to the original URL. Unknown codes return `404 Not Found`.

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

Backend tests use an in-memory SQLite database and cache, while frontend unit tests use Vitest and jsdom. Playwright runs the full browser journey against an isolated `database/e2e.sqlite` database. Install its browser once with `npx playwright install chromium`. Together the suites cover creation, validation, idempotency, transaction rollback, cache concurrency and failures, redirects, Base62 conversion, form submission, errors, clipboard behavior, history, themes, and the complete shortening journey. `composer test:coverage` enforces the same 70% minimum used in CI and requires PCOV or Xdebug. Successful CI runs retain a machine-readable Clover report as the `backend-coverage-clover` artifact for 14 days.

GitHub Actions runs tests with a 70% minimum coverage threshold, Pint, ESLint, Prettier, dependency audits, and the frontend build on every pull request and push to `main`. Use `npm run format` to apply the JavaScript formatting rules locally. Dependabot checks Composer, npm, and GitHub Actions dependencies weekly, groups routine minor and patch updates by ecosystem, and opens security updates for vulnerable dependencies.

## Operational notes

- Configure a persistent database and `CACHE_STORE=redis` in production.
- Keep `APP_DEBUG=false` and provide a unique `APP_KEY`.
- Metrics are disabled by default. Set `METRICS_DRIVER=statsd` and configure `METRICS_STATSD_HOST`, `METRICS_STATSD_PORT`, and `METRICS_PREFIX` to send counters and timings to a StatsD-compatible agent.
- The metrics are `<prefix>.requests_total`, `<prefix>.request_duration_ms`, and `<prefix>.cache_operations_total`. Their bounded labels describe only operation and outcome; URLs, short codes, request IDs, idempotency keys, IP addresses, and user agents are never exported.
- Useful starting alerts are any sustained cache operation failure, a request `error` rate above 1% for five minutes, or p95 request duration above 250 ms. Tune these thresholds from observed production traffic.
- Unhandled exceptions are written as JSON to `storage/logs/exceptions-YYYY-MM-DD.log` through the dedicated `LOG_EXCEPTION_CHANNEL`. Set that variable to another configured channel such as `stderr` or `papertrail` for external collection, or to `null` to disable reporting.
- Exception reports contain only the exception class, request ID, URL path, HTTP method, and a bounded user-agent. Request bodies, query strings, credentials, authorization headers, and idempotency keys are deliberately excluded. A reporting outage does not alter the original application response.
- The creation endpoint is limited to 10 requests per minute per client.
- Cache entries expire after 24 hours and are rebuilt from the database on demand.

## License

This project is open-sourced under the [MIT License](https://opensource.org/licenses/MIT).

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, quality checks, and commit guidance. Release-facing changes are tracked in [CHANGELOG.md](CHANGELOG.md).
