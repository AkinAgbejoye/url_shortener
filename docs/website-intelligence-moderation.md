# Website intelligence moderation operations

Public and authenticated reporters use `/report-abuse`. Intake is throttled, bounded, and returns the same acknowledgement without looking up or revealing an account, analysis, report, domain, or enforcement state. Reporters must not submit credentials, raw page bodies, or sensitive query strings. Contact details are encrypted and retained only when explicit contact consent is supplied.

Only verified users listed in `INTELLIGENCE_OPERATOR_EMAILS` can open `/account/operations/moderation`. Operator actions can assign or resolve reports, accept appeals, pause or cancel work, block or release a domain hash, suspend or resume an analysis account, export a bounded incident record, and trigger report-data deletion. Blocking and suspension stop active work with cancellation requests and prevent new work; redirects remain on their independent request path.

Every action creates an append-only audit event containing the operator, bounded action and category, a keyed subject hash, and a timestamp. Audit metadata excludes credentials, page content, query strings, reporter contact details, and owner data. Incident exports should use this bounded event representation only.

## Response and recovery

- Review phishing, malware, and credential-harvesting reports first. Escalate credible imminent harm immediately.
- Assign a report before investigation. Preserve only the bounded evidence permitted by retention policy.
- Use domain blocks for target-wide harm and account suspension for repeated owner abuse. Confirm that queued and running intelligence work moved to cancellation-requested.
- Record appeals on the original report. Release a block only after review and record the recovery action.
- Delete reports after `INTELLIGENCE_MODERATION_RETENTION_DAYS` unless a documented legal preservation rule applies.
- Monitor aggregate counts by fixed category/action only. Never use URLs, account IDs, email addresses, or free text as metric labels.
- Track `received`, `reviewing`, `resolved`, `appeals`, `active_blocks`, and `notification_failures` in the bounded operations snapshot. Notification bodies must contain only the report public ID and generic status; delivery failures must not expose recipient addresses in telemetry.
