# Account security and operations

This runbook covers the three independent credential types used by Shortly. Never interchange or forward them through query strings.

| Credential | Storage and transport | Authority | Rotation and recovery |
| --- | --- | --- | --- |
| Browser session | An opaque, `HttpOnly`, `SameSite=Lax` session cookie; use `Secure` in production | Interactive access to one account; verified-only actions also require email verification | Log out to invalidate one session. A password reset rotates the remember token and deletes every database-backed session for the account. |
| Anonymous management token | Shown once in `X-Management-Token`, hashed at rest, and retained only in the creating browser's local storage | Inspect, update, disable, enable, delete, and view analytics for one anonymous link | It cannot be recovered or rotated. Claim the link into a verified account before clearing storage; delete and recreate the link if compromise requires replacement. |
| Scoped API key | Shown once as `ak_<public-id>.<secret>` and sent only as `Authorization: Bearer`; only the secret hash is stored | Operations selected by `urls:read`, `urls:write`, and `analytics:read`, limited to one owner | Create a replacement, update the client, verify it, then revoke the old key. Revocation and expiry apply on the next request. |

## Account and authorization flows

Registration creates an authenticated but unverified browser session and sends a signed, 60-minute verification link. Verification unlocks anonymous-link claiming and API-key lifecycle operations. Login regenerates the session ID; logout invalidates the session and CSRF token. Password-reset requests use the same public response for known and unknown addresses. Completing recovery consumes the reset token, rotates the remember token, and invalidates all database-backed sessions.

Anonymous links remain usable without an account. Their management token authorizes only that link. A verified account may claim one using its token, after which account ownership takes precedence. Owned links can be created and managed through the browser session or an appropriately scoped API key; another account receives the same not-found response as it would for a nonexistent resource.

| Operation | Anonymous token | Browser session | `urls:read` | `urls:write` | `analytics:read` |
| --- | :---: | :---: | :---: | :---: | :---: |
| Create anonymous link | Not required | Yes, if using the public form | No | No | No |
| Create owned link | No | Yes | No | Yes | No |
| List/inspect owned links | No | Yes | Yes | No | No |
| Update, enable, disable, or delete | One anonymous link | Owned links | No | Owned links | No |
| Claim anonymous link | Required | Verified account required | No | Verified account/key owner required | No |
| Read aggregate analytics | One anonymous link | Owned links | No | No | Owned links |
| Create, list, or revoke API keys | No | Verified account plus current password for create/revoke | No | No | No |

## API-key usage

Create a key from the verified account page, select only required scopes, and copy it immediately. The complete key is never shown again.

```bash
export SHORTLY_API_KEY='ak_public-id.secret'

curl --fail-with-body http://localhost:8000/api/v1/urls \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer ${SHORTLY_API_KEY}"

curl --fail-with-body -X POST http://localhost:8000/api/v1/urls \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${SHORTLY_API_KEY}" \
  -d '{"long_url":"https://example.com/owned"}'

curl --fail-with-body http://localhost:8000/api/v1/urls/example/analytics?range=30d \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer ${SHORTLY_API_KEY}"
```

Do not put a key in shell history on shared hosts; use a protected secret file or the deployment platform's secret store. Never send it in a URL, request body, cookie, log field, exception context, metric label, source file, or support ticket. The public ID is metadata, but it is also excluded from telemetry to avoid creating an owner label.

For planned rotation, issue a least-privilege replacement, exercise every required operation, deploy it, and revoke the old key from the account page. For suspected compromise, revoke first, inspect aggregate authentication outcomes and access logs that do not contain credentials, then create a replacement. Expiring keys should be rotated before `expires_at`; there is no grace period.

## Limits and safe failure contracts

Defaults are 60 API requests per minute per key and 120 per account. Invalid credentials receive 10 attempts per minute per IP. Anonymous creation receives 10 requests per minute per client and anonymous management receives 30. Account key creation, revocation, and listing receive 5, 10, and 30 requests per minute respectively.

Malformed, unknown, incorrect, expired, and revoked keys return the same `401 {"message":"Unauthenticated."}` shape. Missing scope returns `403`; foreign and nonexistent resources return the same `404` shape. These failures must not mutate a URL or interrupt its public redirect.

## Monitoring, alerts, and incident response

`<prefix>.api_key_authentication_total` has one bounded `outcome` label: `valid`, `missing`, `malformed`, `unknown`, `invalid`, `expired`, or `revoked`. It contains no account, key, owner, IP, URL, or credential label. Alert on a sustained increase in invalid outcomes relative to the valid baseline, any sharp per-IP 429 increase at the edge, and unexpected revoked/expired use after a rotation window. A practical starting point is 20 invalid outcomes in five minutes or a fivefold increase over the preceding hour; tune it to normal traffic.

Structured authentication events are deliberately small:

- `api_key_authentication`: `outcome` only.
- `api_key_last_used_update_failed`: `exception_class` only.

On a credential incident: revoke affected keys; reset the password if a browser session may be compromised; verify database session invalidation; rotate application, mail, database, cache, and monitoring credentials only when their exposure is plausible; preserve credential-free logs and aggregate metrics; check owner-scoped resources for unauthorized changes; and restore deleted data from a verified backup if needed. Document the time window, affected accounts, revoked public IDs in the restricted incident record, and remediation without copying secrets.

## Backups and recovery

Back up the relational database using encrypted, access-controlled snapshots and test restoration regularly. It contains password hashes, API-key secret hashes, session rows, reset-token hashes, link ownership, destinations, and aggregate analytics. A database restore therefore restores credential verifiers and may re-enable state that was revoked after the snapshot. After restoring, invalidate all sessions, revoke or rotate API keys created before the recovery point as the incident requires, expire reset tokens, and reconcile link ownership before serving traffic. Cache restoration is unnecessary; redirect cache entries are disposable and rebuild from the database.

Email verification links and password-reset links expire after 60 minutes. Users who lose an anonymous management token cannot recover it; support must not bypass ownership checks. Users who lose account access use the password-reset flow. Operators should confirm mail delivery from provider metadata without logging message bodies or signed links.

## Environment variables

The account and credential settings introduced by the accounts feature are listed here with their defaults. Laravel's standard database, cache, mail, queue, logging, and application settings remain documented in `.env.example`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `SESSION_DRIVER` | `database` in `.env.example` | Shared, server-side browser sessions; required for all-session invalidation on password reset. |
| `SESSION_LIFETIME` | `120` | Idle session lifetime in minutes. |
| `SESSION_ENCRYPT` | `false` | Encrypt server-side session payloads. |
| `SESSION_PATH` | `/` | Cookie path. |
| `SESSION_DOMAIN` | `null` | Cookie domain. |
| `SESSION_SECURE_COOKIE` | `false` | Require HTTPS for session cookies; set `true` in production. |
| `SESSION_HTTP_ONLY` | `true` | Prevent JavaScript from reading the session cookie. |
| `SESSION_SAME_SITE` | `lax` | Cross-site cookie policy. |
| `API_KEY_MAX_ACTIVE_PER_USER` | `10` | Maximum simultaneously active keys per account. |
| `API_KEY_NAME_MAX_LENGTH` | `80` | Maximum display-name length. |
| `API_KEY_MAX_EXPIRATION_DAYS` | `365` | Furthest permitted key expiration. |
| `API_KEY_PER_PAGE` | `10` | Default key-list page size. |
| `API_KEY_MAX_PER_PAGE` | `25` | Maximum key-list page size. |
| `API_KEY_CREATE_PER_MINUTE` | `5` | Per-account creation limit. |
| `API_KEY_REVOKE_PER_MINUTE` | `10` | Per-account revocation limit. |
| `API_KEY_LIST_PER_MINUTE` | `30` | Per-account listing limit. |
| `API_KEY_REQUESTS_PER_MINUTE` | `60` | Per-key API request limit. |
| `API_KEY_ACCOUNT_REQUESTS_PER_MINUTE` | `120` | Aggregate per-account API request limit. |
| `API_KEY_INVALID_REQUESTS_PER_MINUTE` | `10` | Invalid-key attempts per source IP. |
| `API_ANONYMOUS_CREATE_PER_MINUTE` | `10` | Anonymous creation requests per client. |
| `API_ANONYMOUS_MANAGE_PER_MINUTE` | `30` | Anonymous management requests per client. |
| `API_KEY_LAST_USED_UPDATE_SECONDS` | `300` | Minimum interval between usage timestamp writes. |

Verification expiry (`60` minutes), reset expiry (`60` minutes), and reset issuance throttle (`60` seconds) are code configuration in `config/auth.php`, not environment variables.

## Offline verification

Install locked dependencies and Chromium once while online. The verification path itself uses no external identity, email, analytics, destination, cache, or database service:

```bash
git ls-files --error-unmatch composer.lock package-lock.json
composer validate --strict
composer test
npm test
npm run test:coverage
npm run test:e2e
vendor/bin/pint --test
npm run lint
npm run format:check
npm run build
```

`npm run test:e2e` recreates `database/e2e.sqlite`, migrates it, starts the app with array cache, database sessions, synchronous queues, and in-memory mail, and blocks every non-local browser request. Its test-only CLI helper creates signed verification and reset links against that SQLite database; it is not registered outside `APP_ENV=testing`.
