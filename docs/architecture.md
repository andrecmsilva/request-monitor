# Architecture

## Goal

Request Monitor answers one operational question:

> When the PHP/LSAPI worker pool is saturated, which exact request owns each worker, and what is that request doing with its lifetime?

## v0.4.0 foundation

The MU foundation owns request identity, process measurement, slow-request escalation, and WordPress hook profiling.

```text
wp-content/mu-plugins/
├── request-monitor-bootstrap.php
└── request-monitor-hook-profiler.php
        ↓
START: PID + request identity + resource baseline
        ↓
whole-hook timing from request start
        ↓
normal plugins load
        ↓
Request Monitor attaches WordPress-aware collectors
        ↓
request crosses slow threshold?
   no ───────────────→ bounded basic record
   yes
    ↓
automatic callback + post-threshold SQL escalation
    ↓
MU shutdown
    ↓
END: CPU/wall + hooks + callbacks + SQL/HTTP + lifecycle
```

The regular plugin bundles both MU files, installs them during activation, verifies their hashes, and repairs them when either bundled component changes.

Tracing is unavailable when the mandatory MU foundation is unhealthy.

## START event

The MU bootstrap records:

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
- slow-request threshold
- callback detail floor
- Deep-mode state
- hook-profiler availability

## Shared request context

The active request lives in `$GLOBALS['rrt_bootstrap_context']`.

The context contains the authoritative start time, PID, thresholds, resource baseline, lifecycle phases, escalation state, and the finalizer supplied by the regular plugin.

There is one START/END owner: the MU bootstrap.

## Lifecycle marks

Where applicable, Request Monitor marks:

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
- shutdown

These marks answer **which broad WordPress lifecycle window consumed time**.

## Whole-hook timing

The MU hook profiler observes WordPress hook dispatch and adds a lightweight end sentinel to hooks that execute during the request.

It aggregates:

- invocation count
- inclusive total duration
- maximum invocation duration
- a bounded manifest of registered callbacks

Whole-hook timing begins from request start in both Basic and Deep modes.

This layer is deliberately safer than exact callback instrumentation and provides useful first-request evidence even when a long callback itself causes the threshold crossing.

## Automatic slow-request escalation

The default threshold is 1500 ms.

Basic mode starts with bounded collectors. At a hook boundary, once elapsed request time exceeds the configured threshold, the profiler sets the shared context to `auto_escalated` and arms richer capture.

Automatic escalation enables:

- exact eligible plugin/theme callback timing for subsequent hooks
- `$wpdb->save_queries` from that point forward
- detailed callback/hook persistence in the final slow record

Outbound WordPress HTTP timing is attached from normal-plugin load so calls made before threshold crossing are still available when a request later becomes slow.

The END record exposes the escalation timestamp and reason so partial coverage is explicit.

## Deep mode

Deep mode does not wait for the threshold.

It enables:

- callback timing from the first eligible application callback
- `$wpdb->save_queries` from MU bootstrap
- outbound HTTP timing from normal-plugin load

Deep mode trades higher profiling overhead for better coverage.

## Callback timing safety model

The profiler does not replace WordPress's `WP_Hook` class. Instead, when exact timing is armed, it selectively proxies eligible application callbacks already registered on the hook being dispatched.

Only callbacks attributed to:

- regular plugins
- MU plugins
- themes

are candidates for exact timing.

### By-reference callbacks

Callbacks declaring parameters by reference are not proxied because a generic wrapper can change PHP reference semantics.

They remain visible in hook manifests and are counted as skipped coverage.

### WordPress core

Core callbacks are not wrapped. Their cost remains visible inside whole-hook duration.

### Bounded memory

Only callback samples above the configurable detail floor are retained. The in-memory callback aggregate is capped and the END record emits only the highest-cost hooks/callbacks/owners.

## Callback attribution

Each retained callback aggregate contains:

- hook
- priority
- callable
- source file
- plugin/MU-plugin/theme owner
- invocation count
- inclusive total duration
- maximum duration

Owner aggregates make plugin-level attribution possible without equating included-file counts with execution cost.

## SQL and HTTP coverage

### SQL

- Deep: query retention from MU bootstrap (`from_start`)
- automatic slow escalation: query retention from threshold crossing (`post_threshold`)
- Basic fast request: disabled

SQL output includes normalized query shape, hash, duration, and WordPress caller information.

### WordPress HTTP API

HTTP call timing is attached for every traced request once the normal plugin loads. Detailed calls are retained for Deep or automatically escalated requests.

## END event

MU shutdown records:

- wall duration
- user/system/total CPU
- CPU/wall ratio and classification
- slow threshold state
- capture level (`basic`, `auto_slow`, `deep`, `deep_slow`)
- automatic escalation coverage
- peak/end memory
- process resource deltas
- lifecycle durations
- hook/callback profile
- SQL/HTTP enrichment
- included-code ownership
- connection-aborted state
- fatal shutdown error when present

## Classification

- `FAST`: wall < 750 ms
- `CPU_BOUND`: CPU/wall >= ~70%
- `WAIT_BOUND`: CPU/wall <= ~25%
- `MIXED`: between those thresholds
- `ACTIVE`: START exists without matching END yet

The slow threshold is independent of CPU classification. A slow request may be CPU-bound, wait-bound, or mixed.

## PID escalation

For ACTIVE requests:

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
request
 → CF-Ray
 → PID
 → lifecycle
 → whole hook
 → plugin/function callback
 → CPU/I/O
 → SQL/HTTP
 → live PHP stack if still needed
```

## Remaining blind spots

- code executed before the MU-plugin stage
- exact callbacks completed before automatic callback timing was armed
- callbacks intentionally skipped because of reference semantics
- direct network calls that bypass the WordPress HTTP API
- database access that bypasses `$wpdb`
- live stack inspection of another worker without host privileges
- system-wide contention outside the current PHP process
- MySQL server-side state not exposed through WordPress

See [`slow-hook-profiling.md`](slow-hook-profiling.md) for the detailed profiling coverage model.
