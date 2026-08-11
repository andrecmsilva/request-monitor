# Changelog

## 0.6.0

- Removed continuous tracing as an operational mode.
- Added mandatory bounded capture sessions with absolute expiry timestamps.
- Added `wp request-monitor capture 30s` and `snapshot 30s`.
- Added hard duration limits of 5–300 seconds.
- Added automatic expiry independent of SSH, WP-Cron, or a running CLI process.
- Added `light`, `hooks`, and `deep` profiles.
- Made `light` the default and stopped loading the hook profiler in light snapshots.
- Reduced the idle MU path to one capture-expiry lookup and immediate return.
- Stopped loading MU runtime/profiler helpers while idle.
- Added capture session IDs to START/END records and fingerprint aggregation.
- Added `--session=last` fingerprint queries.
- Added `--no-wait` self-expiring captures.
- Added `wp request-monitor stop`; `disable` is a safety alias.
- Made old `enable` and persistent `deep` commands fail with bounded-capture guidance.
- Default captures clear the previous log so results describe the snapshot window.
- Refined path normalization so ordinary long WordPress slugs are no longer converted to `{token}`.
- Updated wp-admin to expose bounded snapshot controls only.

## 0.5.0

- Added request/query fingerprint grouping, trace scopes, and WP-CLI control.

## 0.4.0

- Added slow escalation and hook/callback timing.

## 0.3.0

- Made the MU tracing foundation mandatory.

## 0.2.0

- Added CPU/wall classification, resource deltas, SQL/HTTP attribution and active PID inspection.

## 0.1.0

- Initial request/PID tracer.
