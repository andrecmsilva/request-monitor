# Request Monitor

Request Monitor is a **bounded snapshot profiler** for WordPress/PHP worker incidents. It is intentionally not a continuous monitoring agent.

**Current version: 0.7.0**

v0.7 changes the primary question from **“what URL is expensive?”** to **“why is it expensive?”**.

## Install

```bash
wp plugin install "https://github.com/andrecmsilva/request-monitor/releases/download/v0.7.0/request-monitor-v0.7.0.zip" --activate
```

## Recommended workflow

Start with a lightweight 30-second snapshot:

```bash
wp request-monitor capture 30s
```

If the result identifies a workload worth profiling, escalate deliberately:

```bash
wp request-monitor capture 30s --profile=hooks
wp request-monitor capture 20s --profile=deep
```

Capture windows are hard-bounded to **5–300 seconds**. The expiry timestamp lives in WordPress, so a lost SSH session cannot leave tracing active.

## What `capture` returns in v0.7

The command now runs root-cause analysis automatically after the snapshot. The output includes:

- completed/in-flight request counts
- aggregate PHP CPU and estimated CPU-core demand during the capture
- aggregate wall time and CPU share
- measured WordPress SQL time
- measured outbound HTTP time
- residual/unattributed wait estimate
- dominant request fingerprints
- slowest individual requests
- expensive AJAX/WooCommerce actions
- top timed plugin/theme callbacks
- top plugin/theme owners
- top normalized SQL fingerprints
- SQL execution counts, total/average/max time and WordPress caller
- bounded PHP backtraces for slow SQL queries
- outbound HTTP endpoint/caller aggregation
- WordPress lifecycle phase hotspots
- concise conclusions and warnings

Example shape:

```text
REQUEST MONITOR ANALYSIS

Completed requests        68
Total PHP CPU             181.4 s
Estimated CPU demand      6.05 cores
Measured SQL               41.2 s
Measured outbound HTTP      7.4 s
Residual wait              23.1 s

CONCLUSIONS
1. Dominant request family /product-tag/outdoor consumed ...
2. The workload is mixed: PHP execution and waiting both materially contribute.
3. Top SQL fingerprint ... dominant caller: WC_Product_Query::get_products ...
4. Top timed PHP callback ...
```

## Profiles

| Profile | Use | Hook/callback timing | SQL | HTTP |
|---|---|---:|---:|---:|
| `light` | first-line snapshot | No | No | No |
| `hooks` | PHP callback investigation | after slow threshold | after slow threshold | Yes |
| `deep` | shortest root-cause capture | from request start | from MU bootstrap | Yes |

### Deep SQL behavior

Deep mode defines `SAVEQUERIES` **inside each traced PHP request** from the MU bootstrap. The constant disappears when that request ends, so it does not persist beyond the bounded snapshot.

WordPress then records query SQL, query duration, caller and start time in `$wpdb->queries`. Request Monitor groups normalized query shapes and captures a bounded `debug_backtrace()` only for queries slower than the configurable threshold (default: `10 ms`).

If `SAVEQUERIES` was already defined `false` by application configuration, PHP cannot redefine it. Request Monitor reports that explicitly instead of pretending SQL attribution succeeded.

## PHP attribution

The hook profiler times eligible plugin/theme callbacks and returns:

- hook
- priority
- callable/class method
- plugin/theme owner
- source file + line
- invocation count
- aggregate and max callback time

This is application callback attribution, not a continuously sampled Zend VM stack. v0.7 deliberately does **not** add a host-level stack adapter.

## Analyze an existing capture

```bash
wp request-monitor analyze --session=last
```

JSON:

```bash
wp request-monitor analyze --session=last --format=json
```

## Inspect one fingerprint

```bash
wp request-monitor inspect <fingerprint> --session=last
```

`inspect` narrows all PHP callback, SQL, HTTP, lifecycle, and request evidence to that fingerprint and prints more caller-stack detail.

## Existing fingerprint view

The raw grouping command remains available:

```bash
wp request-monitor fingerprints --session=last --mode=pattern --sort=cpu --min-count=1
```

Modes: `pattern`, `request`, `query`, `query-shape`.

## Trace scopes

```bash
wp request-monitor scope set \
  --types=front,ajax,rest \
  --methods=GET,POST \
  --include-paths='/shop/*,/furniture/*'
```

Supported types: `front`, `admin`, `ajax`, `rest`, `cron`, `cli`.

Request Monitor's own WP-CLI commands are never traced.

## Idle behavior

Outside an active capture window:

- the MU bootstrap checks only the absolute capture-expiry option and returns
- the runtime helper is not loaded
- the hook profiler is not loaded
- no callback wrapping occurs
- `SAVEQUERIES` is not enabled by Request Monitor
- no request START/END records are written
- no `/proc` or resource sampling occurs

## Important interpretation note

PHP CPU, SQL duration, and outbound HTTP duration are measured separately. The reported **residual/unattributed wait** is:

```text
max(0, wall - PHP CPU - SQL - HTTP)
```

It is a diagnostic estimate, not a perfect accounting identity. Timers can overlap or include small amounts of client-side CPU. Residual time may include Redis/object-cache, filesystem, socket, lock, scheduler, or other waits.

## CLI

```bash
wp request-monitor status
wp request-monitor capture 30s
wp request-monitor capture 30s --profile=hooks
wp request-monitor capture 20s --profile=deep
wp request-monitor capture 60s --no-wait
wp request-monitor analyze --session=last
wp request-monitor inspect <fingerprint> --session=last
wp request-monitor active --session=last
wp request-monitor fingerprints --session=last
wp request-monitor stop
wp request-monitor clear
wp request-monitor export --file=/tmp/request-monitor.jsonl
wp request-monitor repair
```

Invalid profiles now hard-fail. For example `--profile=hook` is rejected; use `--profile=hooks`.

## Development status

Request Monitor remains an experimental production diagnostic tool. Use `light` first, then run short `hooks` or `deep` captures only when the initial snapshot identifies a workload worth deeper attribution.
