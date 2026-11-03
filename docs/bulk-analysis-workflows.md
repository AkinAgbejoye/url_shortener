# Bulk analysis workflows

Bulk analysis uses the same authorization, moderation, idempotency, quota, acquisition, provenance, and retention boundaries as single analysis requests. A preview never creates jobs. Creating a batch is allowed only when every row passes deterministic manifest validation; processing can still finish partially when a row later encounters a live quota or policy boundary.

## Manifest contract

Manifests must be valid UTF-8, at most 100,000 bytes, and contain at most 100 rows. Limits are configurable with `INTELLIGENCE_BULK_MAX_BYTES`, `INTELLIGENCE_BULK_MAX_ROWS`, and `INTELLIGENCE_BULK_MAX_ACTIVE_PER_ACCOUNT`.

CSV requires a header row with `url` and `authorization_attestation`. An optional `user_context` field uses `|` between items. JSON is a top-level array of objects with the same fields; `user_context` is an array. Each attestation is 20–500 characters, each URL is at most 2,048 bytes and must be an unambiguous HTTP(S) target, and context is limited to 10 strings of 500 characters. Empty, malformed, oversized, NUL-containing, invalidly encoded, or duplicate rows receive stable validation categories.

```csv
url,authorization_attestation,user_context
https://public.example/about,I am authorized to analyze this public page.,Public company page|English
```

```json
[{"url":"https://public.example/about","authorization_attestation":"I am authorized to analyze this public page.","user_context":["Public company page","English"]}]
```

Use `POST /api/v1/analysis-batches/preview` for a dry run and `POST /api/v1/analysis-batches` to queue validated work. Both require a verified account, an API key with `analyses:write`, and the normal request throttle. The browser workflow is available at `/account/analysis-batches`.

## Progress, retry, cancellation, and retention

The worker applies owner isolation and single-request rules independently to every row. Progress exposes only total, processed, created, failed, and bounded safe error categories. A retry keeps successful rows and requeues only failed or previously unprocessed rows with the same deterministic per-row idempotency keys. Cancellation prevents unprocessed rows from creating jobs. Deleting a batch removes its encrypted manifest and row metadata but does not delete analyses already created; those retain their normal lifecycle. Batch metadata expires with the intelligence retention window and is removed by `intelligence:prune-retention`.

Operations snapshots accept a bounded `bulk` section with queued, running, outcome, and row counters. Operators should alert on sustained queued age, rising failed-row rates, or repeated partial completion without logging manifest contents.

## Streaming exports

`POST /api/v1/analysis-batches/exports` requires `analyses:read`, accepts at most 100 analysis IDs, and streams CSV or JSON. Unknown and foreign IDs are silently omitted so the endpoint cannot disclose their existence. Output includes selected owner-scoped report metadata and presented evidence only; encrypted target URLs, credentials, internal hashes, executable source content, and unrelated account data are excluded. CSV cells beginning with `=`, `+`, `-`, or `@` are prefixed to prevent spreadsheet formula execution. Responses use `private, no-store` caching and attachment disposition.

The full endpoint schemas, states, and response codes are defined in `docs/openapi/website-intelligence.v1.json`.
