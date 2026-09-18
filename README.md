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
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

For a dependency-free local cache, set `CACHE_STORE=array` in `.env`. Then initialize and run the application:

```bash
php artisan migrate
npm run build
php artisan serve
```

The API is available at `http://localhost:8000`.

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
npm test
npm run test:e2e
npm audit --audit-level=high
npm run build
```

Backend tests use an in-memory SQLite database and cache, while frontend unit tests use Vitest and jsdom. Playwright runs the full browser journey against an isolated `database/e2e.sqlite` database. Install its browser once with `npx playwright install chromium`. Together the suites cover creation, validation, idempotency, cache behavior, redirects, Base62 conversion, form submission, errors, clipboard behavior, history, themes, and the complete shortening journey. `composer test:coverage` enforces the same 70% minimum used in CI and requires PCOV or Xdebug.

GitHub Actions runs tests with a 70% minimum coverage threshold, style checks, dependency audits, and the frontend build on every pull request and push to `main`. Dependabot checks Composer, npm, and GitHub Actions dependencies weekly.

## Operational notes

- Configure a persistent database and `CACHE_STORE=redis` in production.
- Keep `APP_DEBUG=false` and provide a unique `APP_KEY`.
- The creation endpoint is limited to 10 requests per minute per client.
- Cache entries expire after 24 hours and are rebuilt from the database on demand.

## License

This project is open-sourced under the [MIT License](https://opensource.org/licenses/MIT).

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup, quality checks, and commit guidance. Release-facing changes are tracked in [CHANGELOG.md](CHANGELOG.md).
