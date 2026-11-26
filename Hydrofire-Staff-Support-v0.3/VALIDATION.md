# Validation — 29 October 2026

## Automated checks

- 45 original PHP workflow checks: request privacy, branch access, acknowledgement, optimistic versions, idempotency, internal notes, waiting/resume, routing, closure/reopen, reminders, permission revocation and outbox handling.
- 50 added PHP presentation checks: website description requirement, optional/invalid URLs, configured routing, live/confirm/reopen and history, SO/invoice/name/email/SA-phone queries, missing records, owner/admin/manager/staff scope, hidden sources, disabled features, email recipients for each transition, capture-only delivery and duplicate prevention.
- 35 HTTP and actual JavaScript/DOM checks: login/CSRF, permitted and denied file downloads, MIME validation, private attachments, UI SO search, Ctrl+K and result navigation, customer overview, contextual request creation, optional website fields, clipboard/drop event handling, routing, live flow and outbox links.
- PHP/JS syntax checks. Shared module source byte comparison and archive exclusions.

The tests execute PHP 8.5.10 (WASM) and Node.js/jsdom against a local PHP HTTP server. They do not contact Hydrofire, Cin7, Xero or an email provider. jsdom runs the actual interface code but does not render layout or verify native browser dialogs, file pickers, OS clipboard contents or touch gestures. Chromium installation failed because the download was incomplete; no pixel/browser visual pass is claimed.

## Reproduce

From support-demo: `php tests/presentation.php`.

Optional interface tests (requires Node.js): in qa, `npm install`, then `node presentation.cjs PATH_TO_PHP ../support-demo`. Port 8137 must be free. This creates a temporary demo, tests it and removes only that temporary copy. No npm/build step is needed for the module itself.

## Manual presentation checklist on WAMP

- Desktop: login, readable queue/table, side panel and long conversation scrolling.
- Mobile at 390px: expand search, type SO-10482, select a result, scroll module headings horizontally and create a request without horizontal page overflow.
- Keyboard: `/` or Ctrl+K, arrows, Enter, Esc; tab through forms and restore focus when closing the panel.
- Search: SO-10482 / SO10482 / so 10482 / INV-20482 / Demo Patio / 082 123 4567 / +27821234567. Confirm unknown SOs show an empty result.
- Access: sales2 cannot see SO-10482 or sales' tickets. Its own SO-10501 is available.
- Website: blank description rejected; description alone succeeds; optional URL accepted; invalid URL rejected; screenshot picker, clipboard paste and drag/drop work. In replies, pasted evidence is selected in the Add evidence form; click Upload evidence to attach it.
- Email: create, acknowledge, reply, reassign, resolve and confirm. Check recipients and Open request links in Test email outbox. Refresh twice: no duplicate captured sends.
- Live: as handler, acknowledge then mark Live on site; as requester confirm or reopen. Reopened requests retain activity history.
- Reset demo passwords using setup --reset-passwords, then sign in again and confirm saved requests remain.

## Deferred verification

Windows PHP 8.3, browser layout/touch/clipboard, real SO source adapters, real-data permission rules, MySQL migration/concurrency, live-data search performance and actual inbox delivery remain for the local review and later staging. Demo records do not certify production SO search. No production files or services have been changed.
