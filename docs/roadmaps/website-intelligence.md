# Website Intelligence and Authorized Site Import Roadmap

## Status

**Backlog created — implementation not started.**

This document records a possible future product direction. It does not commit the project to a delivery date. Delivery is tracked by [epic #83](https://github.com/AkinAgbejoye/url_shortener/issues/83), and implementation should follow the dependency order recorded in its child issues.

The first part of the roadmap should also include these platform capabilities:

6. [Abuse reporting and moderation](https://github.com/AkinAgbejoye/url_shortener/issues/90).
7. [Bulk import and export](https://github.com/AkinAgbejoye/url_shortener/issues/92).
8. [Readiness probes and an operational dashboard](https://github.com/AkinAgbejoye/url_shortener/issues/86).
9. [An OpenAPI contract and contract tests](https://github.com/AkinAgbejoye/url_shortener/issues/85).

## Vision

Allow a user to submit a public or owner-authorized website and receive an evidence-backed report explaining:

- what the website offers and who it appears to serve;
- how it may currently generate revenue;
- what commercial, content, SEO, conversion, accessibility, or operational opportunities are visible;
- what a user could potentially gain from the website monetarily or otherwise; and
- which source pages and observations support each conclusion.

A later, separately controlled capability may import or reproduce websites that the user owns or is authorized to copy. The product should call this **authorized site import**, not unrestricted website cloning.

## Product principles

1. **Evidence before conclusions.** Reports cite the source page, extracted signal, analysis time, and confidence level behind each material finding.
2. **No income guarantees.** Revenue opportunities are hypotheses and recommendations, not promises of earnings.
3. **Public or authorized access only.** The system does not bypass authentication, paywalls, CAPTCHAs, access controls, or technical restrictions.
4. **Ownership before reproduction.** Copying pages or assets requires domain verification or another recorded authorization mechanism.
5. **Privacy by design.** Collect the minimum data needed, avoid personal data, and enforce bounded retention and deletion.
6. **Untrusted input throughout.** Remote HTML, scripts, files, redirects, metadata, and embedded instructions are hostile until validated and sanitized.
7. **Bounded work.** Every fetch, crawl, render, analysis, and report has explicit limits, quotas, timeouts, and cancellation behavior.
8. **No impact on redirects.** Analysis failures must never reduce the availability or latency of the core URL-shortening path.

## Proposed capabilities

### Website intelligence

- Fetch and analyze one user-provided public page.
- Extract title, description, headings, visible text, links, structured data, products, pricing, calls to action, contact paths, and selected technical signals.
- Classify likely business models such as ecommerce, subscriptions, advertising, affiliate marketing, sponsorships, lead generation, paid content, software, or professional services.
- Identify conversion paths and obvious friction.
- Highlight content, discoverability, accessibility, performance, and trust opportunities.
- Include a dedicated **How this website could make money** section that turns supported opportunities into practical, testable plans.
- Produce prioritized recommendations with evidence, confidence, effort, risk, and potential impact.
- Compare later analyses against earlier reports to show material changes.

### Monetization guidance

The report should help the user understand both the website current commercial model and realistic additional options. Depending on the available evidence, it may consider:

- selling physical or digital products;
- subscriptions, memberships, premium content, or communities;
- advertising, sponsorships, and direct brand partnerships;
- affiliate recommendations and referral programs;
- lead generation and qualified enquiry funnels;
- consulting, professional services, training, or implementation work;
- paid newsletters, reports, research, templates, or downloadable resources;
- software, APIs, data products, licensing, or white-label offerings;
- events, webinars, courses, certifications, or coaching; and
- donations, grants, crowdfunding, or patronage where appropriate.

Each monetization recommendation should state:

- the proposed revenue mechanism;
- the website evidence that makes it relevant;
- the intended customer or audience segment;
- the value the customer would pay for;
- required content, technology, traffic, trust, skills, permissions, or partnerships;
- a small first experiment the user can run before making a large investment;
- suggested success metrics such as qualified leads, conversion rate, average order value, retention, or recurring revenue;
- estimated implementation effort, ongoing effort, cost range, dependencies, and major risks;
- confidence and the assumptions that still need validation; and
- reasons the opportunity may not be suitable for the website.

The system must not invent traffic, revenue, demand, conversion, ownership, or profitability figures. Where the user has not connected verified analytics or supplied business data, financial projections should be presented as scenarios with visible assumptions rather than forecasts or guarantees.

### Verified-domain analysis

- Verify control through DNS, a well-known file, or a short-lived HTML meta tag.
- Analyze a bounded set of same-origin pages discovered through approved links or sitemaps.
- Let the owner include or exclude paths.
- Support authenticated crawling only through a future purpose-built credential vault and explicit owner consent; it is not part of the initial release.

### Authorized site import

- Import only sites whose ownership or reproduction permission has been verified.
- Capture approved HTML, styles, images, and other licensed assets within strict size and page limits.
- Remove scripts, trackers, analytics tags, authentication forms, payment forms, hidden inputs, and unsafe embeds.
- Sanitize and transform the result into editable project content rather than serving an untouched executable copy.
- Preserve source attribution, import time, verification evidence, and declared asset licences.
- Require a preview and explicit confirmation before publishing imported content.

## Explicit non-goals

- Scraping every website regardless of its rules or terms.
- Bypassing authentication, paywalls, CAPTCHAs, geographic controls, rate limits, or crawler restrictions.
- Copying brands, logos, copyrighted text, images, private data, user accounts, checkout flows, or credentials without permission.
- Mirroring phishing targets, login experiences, banking pages, identity providers, wallets, or payment pages.
- Executing arbitrary remote scripts inside the application network.
- Selling scraped personal data or building visitor profiles.
- Presenting inferred revenue, traffic, ownership, or profitability as verified fact.
- Allowing remote page content to instruct an AI system to reveal secrets or invoke privileged tools.

## Delivery roadmap

Tracking epic: [#83](https://github.com/AkinAgbejoye/url_shortener/issues/83).

### [Phase 0 — Product, policy, and threat model](https://github.com/AkinAgbejoye/url_shortener/issues/84)

- Define allowed and prohibited use cases.
- Decide supported countries and obtain appropriate legal review for copyright, database rights, privacy, contractual restrictions, and computer-access law.
- Define user authorization attestations, complaint handling, takedown, appeal, and repeat-abuse procedures.
- Define the crawler identity, contact page, retention policy, deletion guarantees, and acceptable-use policy.
- Threat-model SSRF, DNS rebinding, redirect abuse, decompression bombs, malicious documents, stored XSS, prompt injection, data exfiltration, phishing, and denial of service.
- Establish a domain blocklist and a process for high-risk categories.

**Exit condition:** approved product policy, abuse model, data inventory, threat model, and go/no-go decision.

### [Phase 1 — Safe single-page acquisition](https://github.com/AkinAgbejoye/url_shortener/issues/87)

- Accept one HTTP or HTTPS URL from an authenticated, verified account.
- Introduce asynchronous analysis jobs separate from redirect requests.
- Build an isolated fetch worker with network egress restrictions.
- Validate DNS and destination IP addresses before connection and again after every redirect.
- Block loopback, private, link-local, multicast, reserved, and cloud-metadata destinations for IPv4 and IPv6.
- Restrict ports, redirects, response bytes, decompressed bytes, content types, runtime, and concurrency.
- Send no user cookies, application credentials, authorization headers, or internal request context.
- Identify the crawler and apply per-domain throttles and retry backoff.
- Parse and store a sanitized, size-bounded extraction plus provenance—not a raw executable page.

**Exit condition:** security tests prove that hostile URLs and redirects cannot reach internal services or produce unbounded work.

### [Phase 2 — Structured extraction and reports](https://github.com/AkinAgbejoye/url_shortener/issues/88)

- Extract page identity, headings, readable content, internal/external links, structured data, pricing signals, calls to action, contact methods, and selected technology indicators.
- Store observations separately from interpretations.
- Generate a report with source citations and timestamps.
- Add report states for queued, fetching, analyzing, completed, partially completed, blocked, expired, and failed.
- Provide accessible HTML and exportable JSON; consider PDF only after the report contract stabilizes.
- Add account quotas, cancellation, retention, deletion, and repeat-analysis behavior.

**Exit condition:** a user can analyze one eligible page and audit where every reported fact came from.

### [Phase 3 — Business and opportunity analysis](https://github.com/AkinAgbejoye/url_shortener/issues/89)

- Infer likely audience, value proposition, business model, conversion funnel, and revenue channels.
- Detect possible opportunities in ecommerce, subscriptions, advertising, affiliate programs, sponsorships, lead generation, premium content, licensing, software, or services.
- Generate a structured monetization playbook for each supported opportunity, including the offer, audience, prerequisites, first experiment, measurement plan, effort, risks, evidence, assumptions, and confidence.
- Rank quick validation experiments separately from longer-term business investments.
- Add SEO, content, accessibility, performance, trust, and conversion observations.
- Score recommendations by evidence strength, confidence, estimated effort, dependency, risk, and potential impact.
- Clearly separate observed facts, model inferences, assumptions, and user-supplied context.
- Let users correct assumptions and regenerate recommendations.

**Exit condition:** reports are useful, reproducible, source-grounded, and do not claim guaranteed financial outcomes.

### [Phase 4 — Verified-domain bounded crawling](https://github.com/AkinAgbejoye/url_shortener/issues/91)

- Add DNS, well-known-file, and HTML-meta ownership verification.
- Honor the Robots Exclusion Protocol and record the policy used for each crawl.
- Support same-origin sitemap and link discovery with configurable include/exclude paths.
- Cap pages, depth, bytes, render time, and requests per domain.
- Deduplicate canonical pages and prevent crawler traps such as calendars, infinite parameters, and session URLs.
- Add crawl pause, cancellation, partial-result, recrawl, and deletion controls.
- Aggregate page-level evidence into a site-level report without collecting visitor data.

**Exit condition:** a verified owner can analyze a bounded site while operators can explain and stop every request.

### [Phase 5 — Authorized site import](https://github.com/AkinAgbejoye/url_shortener/issues/93)

- Require current domain verification and a separate reproduction-rights attestation.
- Create a read-only import preview from sanitized content and approved assets.
- Strip executable and sensitive behaviors by default.
- Scan assets for malware and reject unsupported or suspicious formats.
- Rewrite links and asset references safely without copying authentication or payment flows.
- Record provenance and declared licences for every imported resource.
- Add takedown, deletion, and re-verification workflows.
- Keep publishing disabled until a separate hosting and abuse review is complete.

**Exit condition:** verified owners can produce a safe editable import without creating a credential-harvesting or unauthorized mirroring service.

### [Phase 6 — Monitoring and comparison](https://github.com/AkinAgbejoye/url_shortener/issues/94)

- Allow opt-in scheduled re-analysis for verified domains.
- Show evidence-backed changes in content, pricing, calls to action, structured data, and recommendations.
- Add notification preferences and meaningful-change thresholds.
- Enforce per-account and per-domain schedules, budgets, retention, and cancellation.
- Measure aggregate reliability without logging page content, credentials, or sensitive identifiers.

**Exit condition:** recurring analysis is predictable, bounded, observable, and easy to disable.

## Proposed architecture

### Laravel application

The existing application remains responsible for:

- accounts, ownership, sessions, API keys, authorization, and quotas;
- analysis requests, job state, report access, retention, and deletion;
- domain verification records and authorization attestations;
- presenting sanitized findings and evidence; and
- audit events, bounded metrics, operator controls, and abuse response.

### Isolated acquisition worker

A separate worker should handle remote networking and browser rendering. It should have:

- no access to the application database or secrets beyond a narrowly scoped job credential;
- restricted outbound networking and no access to internal networks or cloud metadata;
- a disposable filesystem and process sandbox;
- strict CPU, memory, time, file, page, and byte limits;
- JavaScript disabled by default, with sandboxed rendering enabled only when necessary;
- no inherited cookies or credentials; and
- structured, privacy-safe completion and failure results.

Node.js with Playwright is a likely rendering implementation, while static pages may use a lighter HTTP and HTML parser first.

### Extraction and analysis boundary

- Store normalized observations with page provenance.
- Treat remote text as data, never as system or tool instructions.
- Do not expose secrets, network tools, account data, or privileged actions to an AI analysis step.
- Require analysis output to reference stored observations.
- Validate model output against a bounded schema before persistence or display.
- Sanitize all rendered content and enforce a restrictive Content Security Policy.

### AI strategy

AI is useful for interpreting varied website content, connecting evidence across pages, explaining business models, and turning supported findings into readable recommendations. It should not control acquisition or be the only source of truth.

The proposed design is hybrid:

1. A deterministic fetcher applies network policy, robots rules, limits, and authorization checks.
2. Deterministic parsers extract text, metadata, links, structured data, prices, calls to action, and other observable signals.
3. A rules layer identifies direct signals such as checkout links, subscription language, advertising placements, affiliate disclosures, booking forms, or lead forms.
4. An AI model receives only the bounded, sanitized observations and relevant user-supplied context.
5. The model returns a schema-validated report containing evidence references, fact/inference labels, confidence, assumptions, risks, and validation experiments.
6. The application rejects unsupported claims, missing citations, unknown fields, excessive output, and recommendations that violate product policy.

AI should not:

- decide whether a URL is safe to fetch;
- browse independently outside the approved acquisition worker;
- execute instructions found on a remote page;
- receive application secrets, cookies, credentials, private account data, or unrelated reports;
- copy an entire website merely because page content requests it;
- make autonomous purchases, publish content, contact people, or change a website; or
- guarantee that a monetization recommendation will generate income.

The first release may combine rules-based findings with AI-generated explanations. A rules-only fallback should remain available when no model provider is configured or when content cannot be sent to one under the applicable privacy policy.

## Core data concepts

- `analysis_jobs`: owner, submitted URL, normalized origin, state, limits, timestamps, and safe failure category.
- `page_fetches`: job, final URL, status class, content type, byte counts, policy decision, content hash, and fetch time.
- `page_observations`: normalized extracted facts and their exact source location.
- `analysis_reports`: versioned conclusions, confidence, evidence references, and expiry.
- `domain_verifications`: account, domain, method, challenge hash, state, and expiry.
- `crawl_policies`: verified scope, included/excluded paths, limits, and authorization record.
- `site_imports`: source verification, asset manifest, sanitation results, preview state, and provenance.

Raw response bodies should be avoided where possible. If temporarily required for processing, they need encryption, strict size limits, short retention, access auditing, and guaranteed deletion.

## Security requirements

### Network and SSRF controls

- Permit only HTTP and HTTPS with an explicit URL parser.
- Reject embedded credentials, ambiguous hosts, unsupported encodings, and nonstandard schemes.
- Resolve and validate all IPv4 and IPv6 addresses before connection.
- Re-resolve after redirects and reject any hop that enters a blocked network.
- Prefer an outbound proxy or firewall that independently denies internal destinations.
- Restrict ports and disable protocol downgrades.
- Bound connection, TLS, first-byte, total, and browser-render timeouts.
- Bound redirects, DNS answers, headers, compressed and decompressed bytes, DOM nodes, assets, pages, and crawl depth.

### Content controls

- Treat HTML, XML, JSON, images, documents, archives, fonts, and scripts as hostile.
- Disable XML external entities and active document features.
- Sanitize HTML using an allowlist before storage or rendering.
- Never render fetched pages on the application origin.
- Strip forms, scripts, event handlers, embeds, service workers, manifests, and tracking pixels from imports.
- Scan imported files and prevent path traversal, archive bombs, and content-type confusion.

### Credential and privacy controls

- Never forward browser or application credentials to a target website.
- Never accept target-site credentials until a separately reviewed credential-vault phase.
- Redact URL user information and sensitive query parameters from logs and telemetry.
- Avoid collecting personal data and provide deletion for all jobs, reports, observations, and imports.
- Use bounded metric labels and constant-shape authorization failures.
- Keep report access owner-scoped and include it in API-key permission design.

### AI-specific controls

- Treat instructions found in remote content as untrusted prompt-injection attempts.
- Separate system instructions, user context, extracted evidence, and generated output.
- Give the analysis model no direct network, filesystem, credential, or account-management tools.
- Require schema validation, evidence references, and output-length limits.
- Test for secret exfiltration, instruction override, malicious markup, and misleading evidence.

## Crawler and rights policy

- Respect `robots.txt` according to [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309.html), while recognizing that it is a crawler preference mechanism rather than access authorization.
- Check and record applicable site terms before recurring or multi-page crawling where practical.
- Use a descriptive user agent with a public information and contact page.
- Provide domain owners with an opt-out and rapid complaint channel.
- Do not bypass technical restrictions or reuse content beyond the permission and purpose recorded for the job.
- Require verified ownership or documented permission before site import.
- Obtain jurisdiction-specific legal review before production launch. Website text, images, branding, databases, and layouts may carry different rights and restrictions.

## Abuse prevention

- Verified accounts only for analysis; stronger verification for crawling and import.
- Per-account, API-key, domain, origin, and global quotas.
- Lower limits for new or untrusted accounts.
- Block high-risk targets such as login pages, financial institutions, identity providers, wallets, government identity services, and known phishing brands from import.
- Detect repeated blocked-network attempts, credential-shaped inputs, excessive redirects, and domain-verification abuse.
- Provide operator pause, domain block, account suspension, job cancellation, evidence export, and deletion controls.
- Maintain a clear acceptable-use policy and graduated enforcement process.

## Report design

Every material recommendation should contain:

- category;
- concise finding;
- observed evidence and source URL;
- observation time;
- fact, inference, or assumption classification;
- confidence level;
- potential benefit;
- estimated effort and prerequisites;
- risks and limitations; and
- a suggested validation experiment.

Reports should avoid definitive claims about revenue, traffic, legal compliance, ownership, or profitability unless the user supplies independently verified data.

## Operational signals

Useful bounded signals include:

- jobs requested, completed, partially completed, blocked, cancelled, expired, and failed;
- fetch outcomes by bounded category;
- blocked destination class without raw host or IP labels;
- response-size and duration histograms;
- pages and bytes processed per job;
- analysis schema failures and unsupported content types;
- domain-verification outcomes;
- import sanitation and malware-scan outcomes; and
- queue age and worker saturation.

Alerts should cover blocked-network probes, unusual per-domain volume, failure-rate increases, stuck queues, worker resource exhaustion, sanitizer failures, and inability to enforce deletion.

## Success measures

- Percentage of eligible single-page jobs completed with traceable evidence.
- Percentage of report claims backed by at least one accessible source observation.
- User-rated usefulness and correction rate for inferred business models.
- Time to first useful report and bounded cost per completed report.
- Zero confirmed internal-network fetches, credential leaks, unauthorized imports, or stored-XSS incidents.
- Domain-owner complaints and opt-outs resolved within the published service target.
- Report deletion and retention jobs complete reliably and are independently testable.

## Dependencies

- Accounts, ownership, verification, and API keys from [issue #65](https://github.com/AkinAgbejoye/url_shortener/issues/65).
- Background job execution and worker isolation suitable for untrusted network content.
- Production egress controls independent of application validation.
- Storage retention, encryption, malware scanning, and deletion guarantees.
- Legal and abuse-response policies approved before public crawling or import.
- A cost model and quotas for browser rendering and any AI analysis.

## Decisions required before creating implementation issues

1. Is the first release single-page analysis only, or must it crawl verified domains?
2. Which report categories are valuable enough for the MVP?
3. Will analysis be rules-based, model-assisted, or hybrid?
4. Which jurisdictions and site categories are supported at launch?
5. How will users prove authorization for non-public pages or site import?
6. What content, observations, and reports are retained, and for how long?
7. Will raw page bodies ever be stored?
8. What are the per-account, per-domain, and global resource budgets?
9. Which targets are categorically blocked from analysis or import?
10. Is publishing imported content part of this product, or only generating an editable export?

Phase 0 must resolve these decisions and satisfy its exit criteria before dependent implementation issues begin.

## References

- [RFC 9309: Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309.html)
- [OWASP Server-Side Request Forgery Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)
- [OWASP Cross-Site Scripting Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [U.S. Copyright Office: What Does Copyright Protect?](https://www.copyright.gov/help/faq/faq-protect.html)
