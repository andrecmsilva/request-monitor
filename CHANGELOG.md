# Changelog

## 0.5.0

- Added stable request fingerprints for concrete method/path/action/query combinations.
- Added pattern fingerprints with numeric, UUID, long-hex and token path templating.
- Added query-value and query-shape fingerprints without persisting arbitrary raw query values.
- Added repeated fingerprint aggregation by count, slow requests, CPU-bound count, wall time, CPU time and memory.
- Added configurable MU-level trace scopes for request type, method, include/exclude paths and AJAX/WooCommerce actions.
- Added opt-in tracing for arbitrary WP-CLI workloads through the `cli` request type.
- Hard-excluded Request Monitor's own WP-CLI commands from tracing.
- Added `wp request-monitor` commands for status, enable/disable, Deep mode, scope control, active requests, fingerprints, clear, export and MU repair.
- Added CLI-first operational documentation.
- Split the normal plugin into core, store, admin and CLI modules.
- Split reusable MU runtime helpers from the bootstrap for a smaller tracing entry point.
- Removed incident-report generation from the immediate roadmap; raw diagnostic evidence remains the project focus.

## 0.4.0

- Added configurable automatic slow-request escalation.
- Added continuous whole-hook timing.
- Added eligible plugin/theme callback timing after threshold and from request start in Deep mode.
- Added callback owner/file/callable attribution with by-reference safety exclusions.
- Added explicit SQL/HTTP coverage metadata for basic, post-threshold and Deep captures.

## 0.3.0

- Made the MU bootstrap mandatory.
- Moved START/END ownership into the MU layer.
- Added lifecycle phase timing and automatic MU installation/repair.

## 0.2.0

- Added CPU/wall classification, resource deltas, SQL/HTTP attribution and active PID inspection.

## 0.1.0

- Initial request/PID tracer.
