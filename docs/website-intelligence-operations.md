# Website intelligence operations

Website-intelligence work is isolated from the redirect path. These probes and the operator view do not perform remote fetches or model calls.

## Probe contracts

- `GET /api/intelligence/liveness` returns `200 {"status":"alive"}` without resolving downstream dependencies. Use it only to decide whether the application process should be restarted.
- `GET /api/intelligence/readiness` checks the database, configured queue, cache round trip, distributed lock, bounded storage round trip, and worker heartbeat. It returns `200` with `status: ready` or `503` with `status: not_ready`. The six check keys are constant and values are only `ready`, `not_ready`, or `disabled`.
- The existing `GET /api/health` response remains unchanged for compatibility.

Readiness results are cached briefly. Each dependency needs a driver-level timeout no greater than `INTELLIGENCE_READINESS_CHECK_TIMEOUT_MS`; a result exceeding the budget is reported not ready. Async workers publish a Unix timestamp at the configured heartbeat key. Sync queues report the worker check as disabled.

Never attach hostnames, connection strings, paths, destination URLs, account identifiers, exception messages, or provider responses to probe output.

## Operator dashboard

`/account/operations/intelligence` requires an authenticated, verified account whose normalized email appears in the comma-separated `INTELLIGENCE_OPERATOR_EMAILS` allowlist. Other authenticated accounts receive a constant 404. The JSON data route has the same authorization.

The dashboard contains only bounded aggregate counts for queue age and saturation, outcomes, block categories, sanitizer/schema failures, deletion health, and configured budgets. Producers write these values to the configured snapshot cache key with a `generated_at` timestamp. Missing, malformed, or old snapshots are visibly marked and normalized to zeros.

## Alerts and degraded mode

Alert when readiness is not ready for two consecutive cache windows, queue age exceeds its configured budget, all workers are busy while work is queued, schema/sanitizer failures rise, deletion is overdue, or aggregate telemetry becomes stale. When readiness fails, reject new analysis work while preserving job inspection, cancellation, deletion, public URL shortening, and redirects. Liveness failure is the only signal that should restart the application process.
