# Request Monitor

Request Monitor is a **bounded snapshot profiler** for WordPress/PHP worker incidents. It is intentionally **not a continuous monitoring tool**.

## Current version

**0.6.0**

The v0.6 safety model is:

```text
IDLE
  ↓
wp request-monitor capture 30s
  ↓
trace only requests beginning inside that 30-second window
  ↓
deadline expires automatically
  ↓
new requests are no longer instrumented
  ↓
fingerprint summary
  ↓
IDLE
```

If the initiating SSH/WP-CLI session disconnects, the capture still expires because the deadline is stored as an absolute Unix timestamp.

## Idle cost

The mandatory MU bootstrap remains installed, but when no snapshot is active it performs only one `get_option('rrt_capture_until')`, one timestamp comparison, then immediately returns.

The runtime and hook-profiler files are **not loaded while idle**. There is no callback wrapping, `SAVEQUERIES`, log writing, START/END tracing, `/proc` sampling, or hook instrumentation outside an active window.

## Install

```bash
wp plugin install "https://github.com/andrecmsilva/request-monitor/releases/download/v0.6.0/request-monitor-v0.6.0.zip" --activate
```

## Recommended first capture

```bash
wp request-monitor capture 30s
```

The default `light` profile captures request/PID correlation, fingerprints, Cloudflare metadata, wall/CPU time, CPU-vs-wait classification, memory, `getrusage()`, `/proc/self/io`, lifecycle timing, and WordPress request context.

It deliberately does **not** load the global hook profiler.

At the end of the window, the command prints fingerprint groups sorted by aggregate CPU.

## Profiles

```bash
wp request-monitor capture 30s --profile=light
wp request-monitor capture 30s --profile=hooks
wp request-monitor capture 20s --profile=deep
```

| Profile | Intended use | Hook profiler | SQL |
|---|---|---:|---:|
| `light` | first-line snapshot | No | No |
| `hooks` | callback investigation | Yes | after slow escalation |
| `deep` | shortest targeted investigation | Yes, from start | from start |

Use `light` first. Only escalate after the fingerprint output identifies a workload worth profiling.

## Hard safety limits

Capture duration is bounded to **5–300 seconds**.

Continuous mode has been removed. This now fails deliberately:

```bash
wp request-monitor enable
```

and directs you to a bounded capture instead.

## Start and return immediately

```bash
wp request-monitor capture 60s --no-wait
```

The capture expires automatically even if the shell disconnects.

Check later:

```bash
wp request-monitor status
wp request-monitor fingerprints --session=last --min-count=1
```

## Stop early

```bash
wp request-monitor stop
```

`disable` remains only as a safety alias for `stop`.

## Scopes

```bash
wp request-monitor scope set \
  --types=front,ajax,rest \
  --methods=GET,POST \
  --include-paths='/shop/*,/furniture/*'
```

## Fingerprints

```bash
wp request-monitor fingerprints \
  --session=last \
  --mode=pattern \
  --sort=cpu \
  --min-count=1
```

Modes: `pattern`, `request`, `query`, `query-shape`.

Sorts: `cpu`, `wall`, `count`, `max`.

## Important expiry behavior

The deadline stops **new request admission**. A request that began at second 29 of a 30-second capture is allowed to finish and write its END record after the deadline. Requests arriving after second 30 are not traced.

## Development status

Request Monitor remains experimental production diagnostics. v0.6 specifically changes the architecture so expensive instrumentation cannot be left enabled indefinitely by mistake.
