# Changelog

## 0.7.0

- Changed the primary capture output from a fingerprint timing table to root-cause analysis.
- Fixed Deep SQL tracing by enabling WordPress `SAVEQUERIES` correctly from the bounded MU request path.
- Added explicit warnings when `SAVEQUERIES` was already defined false and cannot be enabled dynamically.
- Added normalized SQL fingerprint aggregation with execution count, total/average/max query time and dominant caller.
- Added bounded PHP `debug_backtrace()` capture for slow SQL queries through `log_query_custom_data`.
- Added configurable slow-SQL stack threshold (default 10 ms).
- Added aggregate plugin/theme callback timing across requests and fingerprints.
- `hooks` now times eligible application callbacks from request start instead of waiting for the slow threshold.
- Added aggregate plugin/theme owner timing and WordPress hook timing.
- Added outbound HTTP grouping by endpoint, owner and caller, including representative caller stacks.
- Added WordPress lifecycle hotspot aggregation.
- Added slowest individual request output.
- Added slowest/most expensive AJAX and WooCommerce action output.
- Added measured PHP CPU, SQL and HTTP totals plus residual/unattributed wait estimate.
- Added estimated aggregate CPU-core demand for the bounded capture window.
- Added `wp request-monitor analyze --session=last`.
- Added `wp request-monitor inspect <fingerprint> --session=last`.
- `capture` now automatically runs analysis when a waited snapshot finishes.
- Invalid profiles now fail instead of silently falling back to `light`.
- Added explicit exclusion of Request Monitor's own admin management requests from active captures.
- Preserved bounded snapshot expiry and tightened heavy-profile caps: light ≤300s, hooks ≤60s, deep ≤30s.
- No host-level PHP stack adapter was added.

## 0.6.0

- Removed continuous tracing as an operational mode.
- Added mandatory bounded capture sessions with absolute expiry timestamps.
- Added `wp request-monitor capture 30s` and hard duration limits of 5–300 seconds.
- Added `light`, `hooks`, and `deep` profiles.
- Made `light` the default and stopped loading the hook profiler while idle/light.
- Added capture session IDs and `--session=last` analysis/filtering support.

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
