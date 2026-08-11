# Snapshot-only safety model

## Why v0.6 changed direction

Request-level hook and callback instrumentation can materially affect a WordPress site that is already CPU-bound or worker-starved. A diagnostic tool must not become a persistent source of load.

Request Monitor therefore no longer has a continuous enabled state.

## Admission deadline

`rrt_capture_until` stores the absolute Unix timestamp of the active capture deadline.

```text
capture_until <= now
    → return immediately

capture_until > now
    → load runtime
    → apply scope
    → trace request
```

No cron, background worker, browser, or still-running CLI process is required to disable tracing.

## Idle behavior

While idle there are no trace writes, fingerprints, `/proc` reads, `getrusage()` snapshots, hook timing, callback wrappers, SQL retention, HTTP timing hooks, runtime include, or profiler include.

Only the mandatory bootstrap and the expiry lookup remain.

## Profiles

### light

Default. Captures fingerprints, PID, wall/CPU, memory/resources, lifecycle, and WordPress request context. The global hook profiler is not loaded.

### hooks

Loads whole-hook timing for the bounded window. Exact eligible plugin/theme callback timing arms only after the configured slow threshold.

### deep

Loads rich callback and SQL attribution from request start. Use the shortest practical window.

## Expiry vs in-flight requests

Expiry stops new request admission. Already-traced requests retain their in-memory context until shutdown so START and END correlation remains valid.
