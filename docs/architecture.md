# Architecture

## Goal

Rocket Request Tracer is designed to answer a specific operational question:

> When the LSAPI/PHP worker pool is saturated, what exact HTTP request is each worker serving, and what is that request doing with its lifetime?

The project deliberately sits between edge/request observability and full APM.

## Data flow

### START event

When WordPress loads the plugin for a traced web request, the plugin creates a request ID and records:

- PID / PPID / UID
- request metadata
- Cloudflare metadata
- selected safe parameters
- initial CPU/resource snapshot

The START record is written immediately.

This means a request still executing during an incident can be correlated with its current PID.

### END event

A shutdown function records:

- status
- wall duration
- CPU duration
- CPU/wall ratio
- memory
- resource deltas
- included code
- optional SQL summary
- optional outbound HTTP summary
- fatal shutdown information

A START without a matching END in the active log window is shown as `ACTIVE`.

## Why PID correlation matters

LSAPI workers are persistent and may serve multiple requests during their lifetime.

The worker lifetime shown by `ps` therefore does not equal the runtime of the current HTTP request.

The trace associates each current request with the PID that accepted it.

## Classification

The first-order classifier uses CPU time divided by wall time.

This intentionally answers one narrow question first:

> Was PHP actively using CPU, or was it mostly waiting?

Thresholds are heuristic, not universal truths.

### CPU_BOUND

High CPU/wall ratio.

Typical follow-up:

- PHP stack sampling
- plugin/theme logic
- expensive loops
- serialization
- regex
- product filtering/calculation
- application-level computation

### WAIT_BOUND

Low CPU/wall ratio.

Typical follow-up:

- SQL
- outbound HTTP
- Redis
- filesystem
- sockets
- locks
- upstream service waits

### MIXED

Both classes materially contribute.

## Deep attribution

Deep attribution instruments WordPress-level facilities.

### SQL

WordPress query saving provides:

- query text
- query duration
- caller information

The tracer normalizes common literal values before persisting SQL.

### HTTP API

The plugin hooks the WordPress HTTP API and captures:

- destination without query string
- elapsed time
- result
- caller summary

This will not catch networking performed outside the WordPress HTTP API.

## OS-level escalation

A plugin cannot replace privileged process inspection.

The dashboard therefore treats the PID as an escalation handle.

Useful tools:

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

The intended model is:

```text
plugin evidence
    ↓
identify exact live PID
    ↓
host-level inspection only when necessary
```

## Security model

The tracer stores diagnostics under WordPress content storage, so it adds multiple protections:

1. randomized log filename
2. `.php` extension
3. immediate `exit`
4. `__halt_compiler()`
5. `.htaccess` deny rule
6. authenticated/nonced download action

The plugin still treats logs as sensitive diagnostics.

## Known blind spots

- PHP activity before normal plugin loading
- direct socket/network calls that bypass WordPress HTTP API
- database calls that bypass `$wpdb`
- executing function stack of another PID
- kernel/system-wide contention outside current process statistics
- full MySQL server-side query state

Those are intentionally delegated to future integrations or host-level tooling.
