# Website intelligence governance and threat model

## Decision record

| Field | Resolution |
| --- | --- |
| Status | Approved for design and implementation subject to the release gates below |
| Effective date | 2026-10-26 |
| Product owner | Repository maintainer |
| Security and privacy owner | Repository maintainer until a named reviewer is recorded for production |
| Abuse-response owner | Repository maintainer until an on-call rotation is recorded for production |
| Legal owner | Qualified counsel for each launch jurisdiction; review is required before production use |
| Review cadence | Before each phase, after a material incident or policy change, and at least annually while the capability is available |

This record resolves the foundational decisions for the website-intelligence roadmap. It is an engineering and product policy, not legal advice. Approval here permits implementation work. It does not permit a public production launch, verified-domain crawling, site import, or import publishing until the applicable gates in this document have recorded evidence and an accountable approver.

## Release decision

The decision is a conditional **go** for building a public-page, single-page analysis MVP. The MVP may fetch one user-submitted public HTTP or HTTPS page for an authenticated, email-verified account, create bounded observations, and produce a private report. Acquisition must run outside the redirect-serving path and satisfy the security controls and tests in this record.

The decision is **no-go** for the following until their separate phase gates pass:

- production availability in any jurisdiction before jurisdiction-specific legal and privacy review;
- authenticated or paywalled acquisition;
- multi-page or recurring crawling before domain control is verified;
- copying or importing page content and assets before reproduction rights are verified;
- publishing an import; and
- analyzing or importing a categorically blocked target.

Development and tests must use controlled fixtures or operator-owned domains until a launch jurisdiction has been approved. A gate that is not explicitly approved remains closed.

## Product boundary and accountable decisions

| Decision | Resolution | Owner | Revisit trigger |
| --- | --- | --- | --- |
| Initial scope | One public page per job; no link traversal, browser credentials, login, paywall bypass, or user-directed headers | Product owner | Phase 4 proposal |
| MVP report | Page identity, content structure, business-model signals, conversion paths, accessibility, discoverability, performance/trust observations, and evidence-backed opportunities | Product owner | Report contract change |
| Analysis method | Hybrid: deterministic acquisition and extraction, rules first, and optional model-assisted interpretation over sanitized observations; a rules-only path remains available | Product and security owners | Provider or data-flow change |
| Launch jurisdictions | None approved initially. Development may continue with controlled fixtures. Each production country requires a recorded counsel decision covering copyright, database rights, privacy, contract, consumer protection, and computer-access law | Legal owner | Every country launch or material legal change |
| Site categories | General public informational and commercial pages may be eligible after automated and operator controls pass. Blocked categories are listed below | Abuse-response owner | Abuse trend or policy change |
| Non-public pages | Deferred. No target-site credentials or private content may be submitted or fetched | Security owner | Separately reviewed credential-vault proposal |
| Verified crawling | Deferred to Phase 4; requires current domain verification, recorded scope, robots-policy evaluation, and owner-selected include/exclude paths | Product and security owners | Phase 4 review |
| Authorized import | Deferred to Phase 5; requires current domain verification or documented authority, a reproduction-rights attestation, provenance, asset-level rights declarations, and a safe preview | Product and legal owners | Phase 5 review |
| Import publishing | Out of scope. Only an editable export may be considered; hosting requires a new legal, phishing, abuse, and security review | Product owner | Separate hosting proposal |
| Raw response bodies | Never persisted. A worker may hold a bounded response in volatile memory only until sanitized extraction completes, then must discard it | Security and privacy owner | Parser cannot meet a documented requirement without persistence |
| Retention | Job metadata, sanitized observations, and reports expire 90 days after completion; failed/blocked job metadata expires after 30 days; security audit records are retained 180 days without page content; temporary worker data is removed at job end | Privacy owner | Legal review or demonstrated user need |
| User deletion | User-requested deletion removes active copies within 24 hours and makes them unavailable immediately; encrypted backups expire through rotation within 30 days and are not restored except for disaster recovery | Privacy and operations owners | Storage architecture change |
| Resource budgets | The initial limits below are hard ceilings, not targets | Security and operations owners | Load evidence and security review |
| AI provider boundary | Only bounded, sanitized observations and necessary user context may leave the application. No raw bodies, secrets, credentials, unrelated account data, or network/tool access. Provider retention and training must be disabled contractually and technically before use | Privacy and security owners | Provider, model, region, or contract change |
| Crawler identity | A stable descriptive user agent must name the service and link to a public information, opt-out, and security-contact page. Do not impersonate a browser or rotate identity to evade policy | Abuse-response owner | Before any external fetch |
| Go/no-go | Engineering: go. Public single-page production: gated. Crawling/import/publishing: no-go until their stated gates pass | Repository maintainer | Every phase exit |

### Initial resource ceilings

These limits apply per single-page job and must be enforced independently by the acquisition worker and its network boundary:

| Resource | Ceiling |
| --- | --- |
| Submitted URLs | 1 |
| Redirects | 5, with DNS and destination validation at every hop |
| Ports | 80 and 443 only |
| Compressed response bytes | 5 MiB |
| Decompressed response bytes | 10 MiB |
| Response headers | 64 KiB total |
| DNS results | 16 addresses per lookup |
| Connection plus TLS time | 5 seconds per hop |
| Total acquisition runtime | 30 seconds |
| Parsed DOM nodes | 50,000 |
| Sanitized extracted text | 1 MiB |
| Jobs per account | 10 per hour and 50 per day |
| Jobs per target origin | 2 concurrent and 30 per hour globally |
| Global concurrency | Explicit deployment setting; default 10 and never inferred from queue depth |

Exceeding a ceiling stops work with a bounded failure category. It must not trigger a larger retry, a browser fallback, or work on the redirect-serving path. Retries are limited to two for transient failures, use backoff with jitter, and do not retry policy denials or size-limit failures.

## Acceptable-use and rights policy

### Allowed use

Users may analyze an eligible page that is publicly reachable without authentication when their purpose is to understand their own site, evaluate a site for legitimate research or business planning, or receive source-grounded recommendations. Access does not grant reproduction rights. Reports are private to the requesting account and must label observations, inferences, assumptions, timestamps, and confidence.

Verified-domain crawling is allowed only after the account proves current control, records the approved paths and purpose, and accepts the applicable crawling terms. Authorized import additionally requires the user to attest that they own or have permission to reproduce the selected text, design, and assets. Verification of domain control alone is not proof of copyright or trademark ownership.

### Prohibited use

Users must not use the capability to:

- bypass authentication, paywalls, CAPTCHAs, robots enforcement, rate limits, geographic controls, or other technical restrictions;
- discover internal services, scan networks, retrieve cloud metadata, or test URLs they are not authorized to assess;
- collect credentials, personal data, private documents, or sensitive identifiers;
- reproduce content, branding, layouts, or assets without the applicable rights;
- create phishing, impersonation, credential-harvesting, malware, deceptive, or illegal material;
- profile people, discriminate, surveil, or make high-impact decisions about an individual;
- create load that disrupts a target or evade a block through redirects, alternate encodings, DNS changes, or multiple accounts; or
- treat generated revenue, traffic, ownership, compliance, or profitability claims as verified facts.

### Categorically blocked targets

Acquisition blocks loopback, private, link-local, carrier-grade NAT, multicast, reserved, documentation, benchmarking, and cloud-metadata address space for IPv4 and IPv6. It also blocks non-HTTP schemes, embedded credentials, nonstandard ports, ambiguous hosts, and any redirect or DNS result that resolves to a blocked destination.

Site import is categorically blocked for login and account-recovery pages, financial institutions, payment and crypto-wallet services, identity providers, government identity services, healthcare portals, and known phishing or malware brands. Public-page analysis of these categories is denied by default and requires an operator-approved research exception that cannot enable import. Sexual exploitation material, non-consensual intimate content, malware delivery, and content whose possession is unlawful are blocked from every mode without exception.

The blocklist is maintained in restricted operational storage so it cannot become a targeting catalogue. Additions require the abuse-response owner; removals require both abuse-response and security approval with a recorded reason.

### User attestations

Before public-page analysis, the user must attest that the request is for a lawful purpose, will not bypass access controls, and complies with the acceptable-use policy. Before verified crawling, the user must additionally attest that they control the domain and approve the recorded scope. Before import, the user must separately attest that they have reproduction rights for every selected resource and identify exclusions or third-party licences. Each attestation records the policy version, account, scope, UTC time, and verification evidence without copying secrets.

## Crawler identity, complaints, and enforcement

Before external acquisition is enabled, the crawler information page must publish its user agent, purpose, source IP information when stable, contact channel, opt-out instructions, and expected request behavior. The service honors an authenticated domain-owner block request promptly even when `robots.txt` would otherwise allow access. Robots rules are mandatory for recurring and multi-page crawling; single-page analysis also honors an explicit user-agent or wildcard disallow unless counsel and the security owner approve a narrowly documented exception for an operator-owned property.

Reports, crawler information, and support responses must not imply that robots permission grants reproduction rights.

### Intake and service targets

| Request | Initial response | Protective action | Final target |
| --- | --- | --- | --- |
| Security report or active phishing/malware | 4 hours | Pause affected jobs, origin, account, or import immediately when credible | 24 hours for containment decision |
| Domain-owner opt-out | 1 business day | Block new acquisition after control is reasonably verified | 3 business days |
| Copyright/privacy complaint or takedown | 1 business day | Restrict disputed report/import while evidence is preserved | 5 business days or counsel-directed timeline |
| User deletion request | Immediate acknowledgement | Make active data unavailable immediately | 24 hours for active copies; 30 days for backup expiry |
| Appeal | 2 business days | Keep protective restrictions in place | 10 business days |

Intake records the reporter, affected URL or report identifier, category, evidence, actions, UTC timestamps, decision maker, and outcome. Public logs and metrics must not include raw URLs, page content, credentials, or reporter personal data.

### Graduated enforcement

Operators may warn, throttle, cancel a job, block a domain, suspend an account, revoke API keys, preserve a minimal incident record, or permanently terminate access. A credible imminent security, privacy, or rights risk permits immediate restriction before notice. Repeated policy denials, block evasion, credential-shaped submissions, unauthorized import attempts, or use of multiple accounts escalates to suspension. Reinstatement requires the abuse-response owner to record why the risk is controlled.

Appeals are reviewed by someone other than the original decision maker when staffing permits. Legal demands and credible threats of harm are escalated to qualified counsel and the security owner. Evidence is access-controlled and retained only as long as the complaint, legal hold, or 180-day security retention requires. A legal hold overrides deletion only when counsel records its scope and expiry.

## Data inventory and lifecycle

| Data | Purpose | Classification | Storage and access | Retention/deletion |
| --- | --- | --- | --- | --- |
| Account and attestation identifiers | Authorization and accountability | Confidential | Application database; owner and authorized operators | Account lifecycle; attestation proof retained with related job or incident |
| Submitted and final URLs | Acquisition and evidence provenance | Confidential; URLs may contain personal data | Encrypted application storage; owner-scoped access; query secrets redacted from telemetry | 90 days; 30 days for failed/blocked jobs |
| DNS answers and policy decisions | SSRF enforcement and audit | Restricted security data | Worker during execution; bounded decision category in audit storage | Raw answers discarded at job end; category retained 180 days |
| Raw response bytes | Transient parsing only | Untrusted and potentially sensitive | Volatile isolated-worker memory only | Destroyed at job end; never backed up |
| Sanitized observations | Evidence-backed reports | Confidential | Application database; owner-scoped and operator access audited | 90 days or user deletion |
| Reports and user corrections | Product output and quality | Confidential | Application database; owner-scoped | 90 days or user deletion |
| Domain verification | Prove current control | Restricted | Challenge hash, method, result, and expiry; never store DNS/provider credentials | Active scope plus 30 days; failed challenges 7 days |
| Import manifests and previews | Authorized editable export | Restricted | Isolated object storage; malware scanned; owner-scoped | 30 days unless user deletes sooner |
| Model request/response | Generate bounded interpretations | Confidential | Not stored by provider; application stores validated report, not provider traces | Job duration at provider; report schedule locally |
| Abuse and security audit | Investigation and enforcement | Restricted | Separate access-controlled log with bounded fields | 180 days unless a scoped legal hold applies |
| Aggregate metrics | Reliability and capacity | Internal | Bounded labels with no host, URL, IP, or account identifiers | 13 months |

Backups must be encrypted, access-controlled, tested for restoration, and expire within 30 days. A restore must reapply tombstones before data is made available. Deletion jobs and backup rotation need observable success/failure signals and an operator runbook before production launch.

## Trust boundaries and threat actors

### Protected assets

- application and worker credentials, account data, sessions, API keys, and infrastructure metadata;
- internal networks, cloud control planes, databases, queues, storage, and the redirect service;
- target-site availability, content, rights, and owner preferences;
- submitted URLs, observations, reports, verification and attestation evidence, and complaint records;
- report integrity, source provenance, policy configuration, blocklists, and operator controls; and
- service capacity, cost budgets, audit evidence, and public reputation.

### Trust boundaries

1. The user/browser to the Laravel application.
2. The application to the queue and isolated acquisition worker.
3. The worker to DNS, the outbound enforcement proxy/firewall, and the public target.
4. Untrusted response bytes to parsers, sanitizers, and observation storage.
5. Sanitized observations to rules or an external model provider.
6. Stored findings to the report renderer and export boundary.
7. Operators to blocklists, complaints, deletion, and emergency-stop controls.

Threat actors include malicious users, compromised accounts, hostile target sites and DNS operators, attackers controlling redirects, malicious content authors, compromised dependencies or model providers, abusive operators, and accidental misuse by legitimate users. Remote content is always attacker-controlled, including instructions that appear authoritative.

## Threat model

| Threat | Assets/boundary | Required controls | Verification evidence | Residual risk and response |
| --- | --- | --- | --- | --- |
| SSRF and cloud-metadata access | Worker egress, internal network | Parse a canonical URL; allow HTTP/S only; deny credentials and ambiguous hosts; resolve all A/AAAA results; block non-public ranges; enforce the same policy in an outbound proxy/firewall | Unit corpus for IPv4/IPv6 encodings and integration tests against blocked canaries; deployment egress test | Public services can proxy internal data; block suspicious redirectors and pause on a confirmed bypass |
| DNS rebinding and TOCTOU | DNS-to-connect boundary | Pin the validated address for connection or have the proxy resolve and enforce; revalidate every connection and redirect; bound TTL-independent retries | Controlled rebinding test and connection telemetry category | Resolver/proxy defects remain; isolate worker from every internal route |
| Redirect abuse | Every fetch hop | Maximum five; resolve and authorize each hop; forbid protocol/port downgrade and credential forwarding | Redirect-chain tests across public, private, loop, malformed, and cross-origin cases | A public endpoint can change after validation; egress boundary remains authoritative |
| Decompression/archive bomb | Response parser and capacity | Cap compressed/decompressed bytes, ratios, entries, nesting, memory, CPU, and time; streaming abort | Fixtures crossing every limit and worker resource telemetry | Parser-specific amplification; kill disposable worker and quarantine type |
| Malicious documents/content confusion | Parser and storage | Strict allowlist of types; sniff and compare declared type; disable entities/macros; sandbox parsers; malware scan imports | Polyglot, XXE, path traversal, malformed document, and scanner-failure tests | Zero-days remain; reject unsupported types and make scanner failure closed |
| Stored/reflected XSS | Observation storage to report UI | Store structured text, sanitize allowlisted markup, context-aware output escaping, CSP, no application-origin remote rendering | Browser tests with markup/event/URL payload corpus and CSP assertions | Sanitizer/browser changes; version sanitizer and invalidate unsafe artifacts |
| Prompt injection | Observations to model | Remote text is delimited data; model has no tools, secrets, network, or authority; schema, citation, and length validation; rules enforce policy after generation | Adversarial instruction and exfiltration evals with unsupported-output rejection | Model may produce persuasive errors; label inference and permit user correction |
| Data exfiltration and credential leakage | User input, worker, provider | Never forward inbound headers/cookies; reject URL credentials and credential-shaped fields; redact sensitive query data; minimize provider payload | Canary-secret tests, outbound request capture, log scans, and provider configuration evidence | Target may encode submitted URL data; show normalized destination and require confirmation |
| Phishing and unauthorized reproduction | Import/export and rights boundary | Block high-risk categories; verify domain plus reproduction attestation; strip forms/scripts/trackers; provenance manifest; no publishing | Brand/login fixture tests, attestation audit, sanitizer evidence, manual review sample | Domain compromise or false attestations; takedown, suspend, preserve bounded evidence |
| Denial of service and cost abuse | Queue, worker, target, provider | Account/origin/global quotas; hard resource limits; bounded retries; cancellation; queue isolation; circuit breakers; no redirect-path coupling | Load/limit tests, queue-age and saturation alerts, target-rate evidence | Distributed accounts can aggregate load; emergency global pause and adaptive blocks |
| Cross-account disclosure | Application/report API | Verified authentication, owner-scoped queries, scoped API keys, constant-shape failures, authorization tests | Feature tests for every read/export/delete path and stale credentials | Application regressions; revoke access and conduct scoped incident review |
| Supply-chain or parser compromise | Worker and application | Lock dependencies, audit, minimal images, non-root disposable workers, read-only filesystem, no application secrets, signed builds where available | Dependency audit, image scan, SBOM/build provenance, escape test | Unknown compromise; rotate worker credentials and rebuild from known source |
| Operator misuse | Governance controls | Least privilege, audited access, two-person approval for blocklist removal/legal holds/production gates, time-bounded access | Quarterly access review and immutable audit samples | Small-team role overlap; disclose exceptions and require retrospective review |
| Deletion failure | Every persistence boundary | Data map, idempotent deletion, tombstones, backup expiry, provider deletion contract, alerts | End-to-end deletion test and periodic backup-expiry exercise | Copies under lawful hold or target caches; disclose scope and expiry |

## Required verification and phase gates

### Before external single-page acquisition

- Product owner approves the public acceptable-use and privacy notices.
- Legal owner records at least one approved launch jurisdiction and any required consent, disclosure, or category restrictions.
- Security owner records passing SSRF, rebinding, redirect, parser, credential-leakage, XSS, prompt-injection, quota, and cancellation tests.
- Operations owner proves network egress denies internal and metadata destinations independently of application code.
- Abuse owner publishes crawler identity, complaint/opt-out intake, blocklist procedure, and emergency pause runbook.
- Privacy owner proves deletion, retention, backup expiry, and any model-provider no-retention configuration.

### Before verified-domain crawling

- Phase 4 records domain-verification expiry and revocation, robots behavior, path scope, per-origin budgets, crawler-trap controls, pause/cancel behavior, and target-owner opt-out tests.
- Legal owner approves the launch jurisdictions for multi-page crawling and the process for site terms.

### Before authorized import

- Phase 5 records reproduction-rights wording, provenance and licence fields, high-risk-category controls, malware scanning, sanitizer tests, safe export behavior, and takedown/deletion exercises.
- Legal, security, privacy, and abuse owners all approve the exact import scope. Domain verification alone is insufficient.

### Before model-assisted reports

- Privacy and security owners approve the provider, processing region, data-processing terms, retention/training settings, incident process, and deletion behavior.
- Evaluation proves schema enforcement, evidence citation, secret-exfiltration resistance, bounded output, fact/inference separation, and a rules-only fallback.

### Security and privacy review conclusion

No downstream design decision is unspecified: every deferred capability has a named owner, a closed-by-default state, and explicit evidence required to open its gate. Phase 1 may implement the bounded worker and tests. Phase 2 may design storage against this data inventory and retention policy. Phase 3 may implement rules first and place provider use behind its gate. Phases 4 and 5 remain closed until their additional approvals. Phase 6 inherits the verified-domain, provider, retention, and per-origin gates.

The repository maintainer must record approval evidence in the relevant pull request or an issue linked from this document. A later material change updates this record and the roadmap in the same pull request.
