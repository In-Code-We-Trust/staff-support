# v0.3 handover

## Scope

Presentation build only, extending the v0.2 support workflow. No live database, API, email or payment connection was used. The existing intranet checkout and schema version were not modified. The previous working copy uses schema version **167**. Check again at integration time; reserve the next unused version rather than assuming 168 is still free.

## Changes

- `support-demo/public/index.html`, `demo.js`, `presentation.css`: focused support shell; header search; fictional customer/order overview; email preview; walkthrough; responsive horizontal navigation.
- `public/modules/staff_support.js`: Website problem form, URL + screenshot optional, problem required; clipboard/drop evidence; Live on site action; confirmation/reopen; hidden answer/incident UI when disabled.
- `lib/staff_support.php`: additive `website_url`, `live_at`, `commit_url` fields in `support_tickets`; server validation; routing under the `website` module key; live audit event; feature gates.
- `lib/staff_support_jobs.php`: suppress known-issue notifications/reminders while that feature is off. Existing ticket receipt, assignment, acknowledgement, reply, update, resolution and reminder outbox retained.
- `lib/demo_search.php`: permission-scoped fictional lookup with SO/invoice spacing and case normalization, SA phone normalization, email and customer matching. Local demo data only.
- `public/api.php`: `demo_search`, `demo_customer`; automatic local email capture after support writes. Existing `demo_mail` now drains pending captured mail too.
- `bin/setup.php`: fictional customer/order fixtures, default website routing to the created Samantha demo account, flags off. No hardcoded production user IDs.
- `start-demo.ps1`: WAMP convenience launcher.
- `tests/presentation.php`, `tests/workflow.php`, `qa/presentation.cjs`: workflow, lookup, privacy, email, uploads and actual JS/API checks.

## Settings and state

- `staff_support_answers_enabled`: settings-table key, default false.
- `staff_support_incidents_enabled`: settings-table key, default false.
- Existing `staff_support_settings`: routing, owners, branch scope and working hours. `routing.website` selects the website handler. Sam can edit routing in Settings.
- Existing `staff_support_enabled` and mail configuration retained. Demo mail mode is forced to `capture` in `lib/demo.php`.
- No payment/temporary-release actions, statuses or automation.
- Existing statuses retained. Marking a website ticket live sets `resolved`, records `live_at`, and asks the requester to confirm (`closed`) or reopen (`new`). Reopening clears the current live badge; the audit event remains.
- New schema fields are additive and migration reruns are supported. No new search indexes are needed for the tiny fictional dataset. Production indexes require the requested real-table discovery and measured staging queries.

## Shared source

The PHP support files and JS/CSS in `module-source/` are byte-identical to those in the demo. Its `presentation.css` is an optional shell reference, not a replacement for intranet CSS. `demo_search.php`, demo tables and demo navigation must not be used as production adapters. v0.2's old deployment patch does not include these changes.

## Later staging work

Complete and present the requested source inventory (exact tables/columns, permission keys, row rules and deep links) before building the production global search. Its full groups, Show all/pagination, shared allocations hold badge, possible-match rules and real-data timing target remain outstanding. This demo previews SO/customer lookup only; it is not the completed global search integration.

Integrate the shared module with the existing login, Leads tab and record-level help hooks. Apply support schema through getDB() with the next available schema version. Confirm PHP 8.1+ with PDO MySQL/Fileinfo in staging (demo uses PDO SQLite). Configure real source adapters, owners, branch scope, protected file storage, base URL and scheduled worker.

Real SO retrieval, full MySQL migration/concurrency, inbox delivery, live sender configuration, mobile browser rendering and Windows/WAMP validation require the subsequent staging/local review. Continue with captured mail until the controlled inbox test is authorised. No live deployment is included in this package.
