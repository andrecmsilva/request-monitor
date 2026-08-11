# Architecture

## v0.6 snapshot path

```text
IDLE request
    ↓
mandatory MU bootstrap
    ↓
read rrt_capture_until
    ↓ expired / unset
return immediately
```

During an active capture:

```text
incoming request
    ↓
MU bootstrap validates absolute capture deadline
    ↓ active
load lightweight runtime
    ↓
classify request type + evaluate trace scope
    ↓ allowed
fingerprints + request identity + resource baseline
    ↓
MU START record
    ↓
normal Request Monitor enrichment
    ↓
optional hook profiler only for hooks/deep profile
    ↓
MU shutdown
    ↓
END record
    ↓
store pairs START + END by request/session
    ↓
fingerprint aggregation / CLI / admin UI
```

## Capture lifecycle

There is no persistent monitoring-enabled state in v0.6.

`rrt_capture_until` is an absolute Unix timestamp. New requests are admitted only while that value is greater than the current time. This makes capture expiry independent of WP-Cron, a browser session, or the WP-CLI process that initiated it.

Already-admitted requests keep their in-memory context and may write END after the deadline. Expiry blocks new admission; it does not truncate requests that are already executing.

Capture windows are hard-limited to 5–300 seconds.

## Profiles

### `light`

Default. Does not load the global hook profiler. Captures request/PID identity, fingerprints, Cloudflare metadata, CPU/wall/memory/resource deltas, lifecycle timing and WordPress context.

### `hooks`

Loads hook timing during the bounded window and can arm eligible plugin/theme callback timing after the slow threshold.

### `deep`

Loads hook/callback profiling and SQL retention from request start. Intended for the shortest targeted windows.

## Idle behavior

While idle the MU bootstrap performs the capture-deadline option lookup and timestamp comparison, then returns before loading runtime or profiler helpers.

For ordinary frontend traffic, the normal plugin also has a fast path: when the current MU foundation is loaded and no trace context exists, it returns before loading its core/store/admin classes.

## Modules

### `mu/request-monitor-bootstrap.php`

Owns the capture-deadline gate and traced request START/END lifecycle.

### `mu/request-monitor-runtime.php`

Loaded only during an active capture. Provides resource snapshots, fingerprinting, request classification, scope matching and trace storage.

### `mu/request-monitor-hook-profiler.php`

Loaded only for `hooks` or `deep` captures. Owns whole-hook timing and safe plugin/theme callback timing.

### `includes/class-request-monitor-core.php`

Owns bounded capture sessions, upgrade safety, MU install/repair, scopes and WordPress-aware enrichment.

### `includes/class-request-monitor-store.php`

Reads START/END records, filters by capture session and aggregates fingerprint groups.

### `includes/class-request-monitor-cli.php`

Primary operational control plane. `capture` opens a bounded window; `stop` closes it early.

### `includes/class-request-monitor-admin.php`

Optional visual interface exposing bounded snapshot controls only.

## Upgrade safety

A v0.5 installation may have legacy continuous options set. On the first v0.6 load, Request Monitor forcibly clears those legacy states, closes the capture deadline, installs the current MU files, and stores the v0.6 schema/version marker.

This migration does not rely on the plugin activation hook, because WordPress updates an already-active plugin without re-running activation.

## Fingerprinting

Four identifiers are written at START and propagated to END:

- request fingerprint
- pattern fingerprint
- query fingerprint
- query-shape fingerprint

Client IP, User-Agent and CF-Ray are intentionally not part of these fingerprints. Pattern normalization templates numeric IDs, UUIDs and long hexadecimal identifiers without collapsing ordinary long WordPress slugs.

## Operational principle

Request Monitor is a short-lived diagnostic instrument, not an APM agent.

Preferred first-line use:

```bash
wp request-monitor capture 30s
```

Escalate only when needed:

```bash
wp request-monitor capture 20s --profile=hooks
wp request-monitor capture 15s --profile=deep
```
