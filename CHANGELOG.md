# Changelog

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
