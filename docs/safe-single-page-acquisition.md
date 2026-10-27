# Safe single-page acquisition

Website acquisition runs only from the `intelligence` queue. Deploy that queue in a worker pool isolated from redirect-serving processes, with no access to application secrets beyond the queue, database, encryption key, and shared atomic-lock cache. Its network policy should deny private and metadata ranges and permit outbound TCP only to public ports 80 and 443. Application-level cache slots cap active fetches at `INTELLIGENCE_MAX_CONCURRENT_JOBS`; worker concurrency should use the same or a lower value.

Every request is authenticated, email-verified, owner-scoped, idempotent, and subject to account, origin, domain, and global active-job quotas. The worker resolves every initial and redirected host, rejects the whole DNS set if any answer is non-public, pins the connection to an approved address, and verifies the connected peer. Redirects are handled manually; cookies, authorization headers, sessions, and inbound request context are never forwarded.

Acquisition is bounded by queue age, retries, runtime, redirect count, DNS answers, response headers, compressed bytes, streaming decompressed bytes, content type, DOM nodes, extracted links, and extracted text. JavaScript, forms, embedded content, event handlers, and raw response bodies are discarded. Only a bounded text extraction and non-secret provenance hashes are retained.

Run workers with:

```sh
php artisan queue:work --queue=intelligence --tries=2 --timeout=120
```

Cancellation is cooperative between network hops and before persistence. The queue timeout remains the hard stop for a slow network operation. API responses expose only the stable failure categories defined by the OpenAPI contract.
