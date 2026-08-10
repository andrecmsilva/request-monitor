# Architecture

## Goal

Request Monitor answers one operational question:

> When the PHP/LSAPI worker pool is saturated, which exact request owns each worker, and what is that request doing with its lifetime?

## v0.3.0 foundation

The MU bootstrap is mandatory and owns the request lifecycle.

```text
wp-content/mu-plugins/request-monitor-bootstrap.php
        ↓
START: PID + request identity + resource baseline
        ↓
normal plugins load
        ↓
Request Monitor attaches enrichment callbacks
        ↓
WordPress lifecycle / SQL / HTTP / query context
        ↓
MU shutdown
        ↓
END: resource delta + classification + enrichment
```

The regular plugin bundles the MU source under `mu/request-monitor-bootstrap.php`, installs it during activation, verifies its hash from WordPress admin, and repairs it when the bundled version changes.

Tracing is intentionally unavailable when the bridge is unhealthy.

## START event

The MU layer records the earliest Request Monitor event available inside WordPress's MU-plugin stage:

- request ID
- PID / PPID / UID
- method / host / path
- Cloudflare Ray ID and connecting IP
- safe diagnostic query parameters
- WordPress/WooCommerce AJAX action
- content type/length
- user agent / referer
- cron/AJAX state
- CPU and `/proc/self/io` baseline

The request ID and PID remain stable through the rest of that PHP request.

## Shared request context

The MU layer stores the active context in `$GLOBALS['rrt_bootstrap_context']`.

The regular plugin attaches a finalizer callback and lifecycle marks to this context instead of creating a second independent trace.

This avoids conflicting START/END ownership and preserves one deterministic request record.

## Lifecycle marks

Where applicable, the regular plugin records timestamps for:

- regular plugin load
- `plugins_loaded`
- `after_setup_theme`
- `init`
- `wp_loaded`
- `parse_request`
- `wp`
- `rest_api_init`
- `admin_init`
- `template_redirect`

The MU shutdown converts those marks into phase-to-phase durations.

These measurements are not a function profiler, but they narrow the execution window that consumed the request.

## END event

The MU shutdown handler records:

- wall duration
- user/system/total CPU
- CPU/wall ratio
- classification
- peak/end PHP memory
- resource deltas
- lifecycle timestamps and durations
- included PHP file count
- connection-aborted state
- fatal shutdown error when present

It then merges the regular plugin's WordPress-aware enrichment.

## Deep attribution

When Deep mode is active, the MU bootstrap enables `$wpdb->save_queries` before normal plugins load. This increases SQL coverage compared with enabling it from a standard plugin.

The regular plugin adds:

### SQL

- query count
- total SQL duration
- slowest queries
- normalized/redacted query shape
- query hash
- WordPress caller information

### WordPress HTTP API

- destination without query string
- duration
- response/error
- caller summary
- transport class

### WordPress context

- admin/AJAX/cron/REST state
- PHP and WordPress versions
- memory limit
- main-query characteristics
- post/found-post counts when available

### Included-code ownership

Files are grouped by WordPress core, MU plugin, regular plugin, theme, or other code.

This is contextual evidence, not CPU attribution.

## Classification

The first-order classifier intentionally answers one narrow question:

> Was the PHP request actively using CPU, or mostly waiting?

- `FAST`: wall < 750 ms
- `CPU_BOUND`: CPU/wall >= ~70%
- `WAIT_BOUND`: CPU/wall <= ~25%
- `MIXED`: between those thresholds
- `ACTIVE`: START exists without matching END yet

## PID escalation

For ACTIVE requests, the PID is the escalation handle for host-level tools:

```bash
ps -p PID -o pid,ppid,user,stat,etime,time,%cpu,%mem,rss,wchan:32,cmd
```

```bash
timeout 5 strace -f -ttT -s 256 -p PID
```

```bash
lsof -nP -p PID
```

```bash
sudo phpspy --pid=PID --limit=200
```

The intended investigation chain is:

```text
request → CF-Ray → PID → lifecycle → CPU/I/O → SQL/HTTP → live PHP stack
```

## Remaining blind spots

The MU bootstrap starts earlier than a normal plugin, but it still starts after WordPress has begun loading core bootstrap code. `auto_prepend_file` would be required for visibility before WordPress itself.

Other blind spots include:

- network calls that bypass the WordPress HTTP API
- database access that bypasses `$wpdb`
- the live PHP stack of another worker without privileged host tools
- system-wide contention outside the current process
- server-side MySQL state not exposed through WordPress
