# Scheduled monitoring and evidence comparison

Monitoring is opt-in, account scoped, and independently disableable. A schedule is allowed only for a verified owner and a currently verified, unblocked domain. Defaults require at least 24 hours between runs, cap each account at five schedules, cap a schedule at 31 monthly runs, cap each dispatch tick at 20 runs, and cap backfill at three missed intervals. `INTELLIGENCE_MONITORING_ENABLED=false` stops dispatch globally without affecting redirects or one-off analyses.

The scheduler runs every 15 minutes with `withoutOverlapping` and `onOneServer`. Each due slot has a unique `(schedule, scheduled_for)` key, so duplicate scheduler execution cannot create duplicate work. A normal tick dispatches one due slot and advances the clock; `intelligence:dispatch-monitors --backfill` dispatches at most the bounded missed interval count. Verification expiry, moderation blocks, owner suspension, paused/deleted schedules, and exhausted monthly budgets pause future work. Active runs are cancelled when an owner pauses or deletes a schedule.

## Evidence and drift

Each run rechecks verification and crawl policy, performs a bounded robots-aware crawl, and stores only page hashes/counts plus category hashes for content, pricing, calls to action, and structured data. Raw page content, credentials, URLs, and unrelated-owner data are not stored in monitoring snapshots. Business-assumption and recommendation hashes are derived using pinned parser/model versions.

Comparisons retain a previous and current version citation and cite the evidence references belonging to both. A configurable threshold controls materiality by changed category count. Parser or model version changes are recorded under `version_drift` with `source_fact: false`; they are never presented as source-page changes. A first run establishes a baseline and does not notify.

## Notifications, operations, and incidents

Email notifications are opt-in and contain only the opaque schedule ID, version IDs, and changed category names. Notification failures are retained as a bounded run timestamp and surfaced in the operations snapshot. The dashboard snapshot supports aggregate monitoring counters for active/paused schedules, queue age, running/failed/cancelled outcomes, missed and stale runs, saturation, and notification failures; it must not contain domains, URLs, raw evidence, or credentials.

For incidents, pause the schedule or set the global monitoring flag false, cancel active runs, and preserve the run’s safe failure category. Retry is available only for a failed run while authorization is current. Deleted schedules retain a bounded deleted state through the retention grace period, after which their runs, versions, comparisons, and schedule metadata are pruned. Provider/parser/model rollouts should change the pinned version fields; comparisons will show drift separately and can be backfilled with the bounded command.
