# Website intelligence API contract

The canonical contract is [`openapi/website-intelligence.v1.json`](openapi/website-intelligence.v1.json). It describes planned account-scoped analysis, report, deletion, cancellation, and readiness endpoints. It does not enable remote acquisition.

## Compatibility policy

- The major API path and OpenAPI major version are stable together. Backward-compatible fields, enum values, and optional operations may be added within `/v1` only after clients are documented to tolerate them.
- Removing or renaming a field, narrowing accepted input, changing ownership or security semantics, or changing an existing status meaning requires a new major API path.
- New response fields are opt-in until every first-party client ignores unknown fields. Closed response schemas in this initial contract deliberately make drift visible during implementation.
- An enum addition is treated as potentially breaking. Clients must not receive a new value until the contract, client fallback behavior, and tests land together.
- Deprecations are announced in the changelog and documentation, then exposed through `Deprecation` and `Sunset` response headers for at least 90 days before removal. Security fixes may shorten that period when continued behavior is unsafe.

## Contract gate

Run `composer contract`. The offline PHPUnit gate parses the locked JSON document and compares every in-scope operation with `config/website_intelligence_contract.php`. It rejects undocumented methods, paths, statuses, scope requirements, missing bearer security, incomplete rate-limit headers, unbounded error schemas, missing evidence categories, unsafe examples, and unresolved local references.

The registry is the implementation handoff: later Laravel routes must use the same operation identifiers and are added to runtime response conformance tests in the issue that activates each route. Planned crawl, moderation, bulk, and authorized-import paths remain extension metadata until their dependent issues implement them.

## Security and privacy

Credentials appear only in the `Authorization` header and examples use deterministic reserved domains and synthetic identifiers. Cross-account and missing resources share the documented 404 envelope. Invalid credentials share one 401 envelope. Errors bound messages, field counts, and field messages, and never expose destination query strings, credentials, provider output, or internal exception detail.
