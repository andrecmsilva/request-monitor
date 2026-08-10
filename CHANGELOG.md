# Changelog

## 0.3.0

- Made the MU bootstrap a mandatory part of Request Monitor rather than an optional roadmap item.
- Added automatic MU bridge installation on plugin activation.
- Added fail-closed activation when `wp-content/mu-plugins` cannot be created or written.
- Added automatic MU bridge health/version repair from WordPress admin.
- Added a manual **Repair MU bridge** control.
- Disabled tracing controls when the required MU bridge is missing or outdated.
- Added safe MU bridge cleanup on plugin deactivation.
- Moved request START ownership to the MU bootstrap so tracing begins before normal plugins load.
- Moved request END ownership to the MU bootstrap so the same early request context survives through shutdown.
- Added a finalizer handoff from MU bootstrap to the regular plugin for WordPress-aware enrichment.
- Added lifecycle phase timestamps and durations for regular plugin load, `plugins_loaded`, `after_setup_theme`, `init`, `wp_loaded`, request parsing, `wp`, REST/admin initialization, `template_redirect`, and shutdown.
- Enabled `$wpdb->save_queries` from MU-plugin load time when Deep attribution is enabled, increasing SQL coverage.
- Added WordPress request context at shutdown, including admin/AJAX/cron/REST state and main-query characteristics.
- Added cron and AJAX markers to the early START event.
- Preserved PID, Cloudflare Ray, safe query, CPU/wall classification, memory, `/proc/self/io`, `getrusage()`, included-code ownership, SQL attribution, outbound HTTP attribution, and live `ps`/`strace`/`phpspy`/`lsof` escalation.

## 0.2.0

- Added CPU/wall request classification.
- Added `getrusage()` resource deltas.
- Added `/proc/self/io` counters when available.
- Added safe WooCommerce/filter query capture.
- Added WordPress AJAX and WooCommerce AJAX attribution.
- Added included-code ownership grouping.
- Added optional Deep attribution mode.
- Added SQL count, total duration, slow-query summaries, query normalization, and caller data.
- Added WordPress outbound HTTP timing and caller summaries.
- Added expensive-path aggregation.
- Added active-request prioritization.
- Added ready-to-run `ps`, `strace`, `phpspy`, and `lsof` commands for active PIDs.
- Added protected JSONL download from WordPress admin.
- Added bounded log rotation.

## 0.1.0

- Initial request/PID tracer.
- Added START/END request records.
- Added Cloudflare Ray ID and connecting IP.
- Added wall time, CPU time, memory, status, and included file count.
- Added WordPress admin request table.
