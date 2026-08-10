# Request Monitor

Request Monitor is a temporary production diagnostics plugin for WordPress that correlates an incoming HTTP request with the **exact PHP/LSAPI PID handling it**, then records enough runtime evidence to determine whether that request is consuming CPU, waiting on dependencies, or doing a mixture of both.

The project is designed around a common hosting incident:

```text
LSAPI queue fills
    ↓
dozens of lsphp workers appear
    ↓
traffic exists, but the actual server-side work is unclear
```

Request Monitor turns that into:

```text
request / CF-Ray / client
        ↓
exact PHP PID
        ↓
MU bootstrap START
        ↓
WordPress lifecycle phases
        ↓
CPU / wall / memory / I/O
        ↓
SQL + outbound HTTP attribution
        ↓
plugin/theme code ownership
        ↓
optional live PID inspection
```

## Current version

**v0.3.0**

## Mandatory MU foundation

The MU bootstrap is no longer optional.

The normal plugin bundles:

```text
mu/request-monitor-bootstrap.php
```

On activation, Request Monitor installs it as:

```text
wp-content/mu-plugins/request-monitor-bootstrap.php
```

If that installation cannot be completed, plugin activation fails instead of silently falling back to lower-quality tracing.

The normal plugin also verifies the MU bridge from WordPress admin and repairs an outdated copy automatically. The dashboard reports the bridge as `HEALTHY` or `MISSING / OUTDATED` and provides a manual repair control.

### Why the MU layer matters

A normal WordPress plugin starts too late to be the authoritative beginning of a request. The MU bootstrap loads before ordinary plugins, so it can create the request context earlier and preserve the same PID/request ID until PHP shutdown.

The architecture is now:

```text
MU bootstrap
    ├─ request ID / PID
    ├─ edge/request metadata
    ├─ initial CPU + /proc snapshot
    ├─ optional early SQL collection
    └─ START log record
          ↓
regular Request Monitor plugin
    ├─ WordPress lifecycle marks
    ├─ SQL attribution
    ├─ WordPress HTTP attribution
    ├─ query/request context
    └─ plugin/theme ownership
          ↓
MU shutdown finalizer
    ├─ END resource snapshot
    ├─ CPU/wall classification
    ├─ lifecycle durations
    └─ enriched END record
```

The MU layer owns both START and END. The regular plugin enriches that shared context.

## Captured request identity

Each traced request records:

- request ID
- PHP PID / PPID / UID
- UTC timestamp
- method / host / path
- safe diagnostic query parameters
- WordPress AJAX action
- WooCommerce `wc-ajax` action
- Cloudflare Ray ID
- Cloudflare connecting IP
- remote IP
- User-Agent / Referer
- content type / content length
- cron and AJAX markers

This provides a direct correlation such as:

```text
CF-Ray a28a04dadf32240a-EWR
GET /furniture/home-decor/
PID 2777019
```

## Runtime classification

Completed requests record:

- wall time
- user CPU
- system CPU
- total CPU
- CPU/wall ratio
- peak PHP memory
- ending PHP memory

Classification is intentionally simple:

| Class | Interpretation |
|---|---|
| `FAST` | wall time below 750 ms |
| `CPU_BOUND` | CPU is at least ~70% of wall time |
| `WAIT_BOUND` | CPU is at most ~25% of wall time |
| `MIXED` | meaningful CPU and waiting |
| `ACTIVE` | START exists without END yet |

Example:

```text
Wall: 29,009 ms
CPU:  28,947 ms
CPU ratio: 99.8%
→ CPU_BOUND
```

That request effectively occupied one core for almost its entire lifetime.

## WordPress lifecycle timing

v0.3.0 records phase marks where applicable, including:

- MU bootstrap load
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

The END record contains durations between observed phases, which helps locate *where* the time accumulated before a function-level profiler is required.

## Linux process resource deltas

Where available, Request Monitor captures `getrusage()` and `/proc/self/io` deltas:

- minor / major page faults
- voluntary / involuntary context switches
- block I/O counters
- logical characters read/written
- physical bytes read/written
- read/write syscall counts

## Deep attribution

Deep attribution is explicitly opt-in because it adds overhead.

When enabled, the MU bootstrap turns on `$wpdb->save_queries` as early as the MU-plugin stage, improving coverage compared with enabling it from a normal plugin.

The final trace includes:

### SQL

- query count
- total SQL duration
- top 10 slowest queries
- normalized/redacted SQL shape
- query hash
- WordPress caller information

### WordPress outbound HTTP

- destination URL without query string
- duration
- response/error
- WordPress caller/backtrace summary
- transport class

## WordPress context

At shutdown the regular plugin contributes:

- admin / AJAX / cron / REST state
- PHP version
- WordPress version
- PHP memory limit
- main-query characteristics
- post count / found posts when available

## Included-code ownership

Loaded PHP files are grouped by:

- WordPress core
- plugins
- MU plugins
- themes
- other code

This is contextual evidence, **not execution-time attribution**.

## Active PID escalation

An ACTIVE request exposes the exact live PID. The UI provides ready-to-run commands:

```bash
ps -p PID -o pid,ppid,user,stat,etime,time,%cpu,%mem,rss,wchan:32,cmd
```

```bash
timeout 5 strace -f -ttT -s 256 -p PID
```

```bash
sudo phpspy --pid=PID --limit=200
```

```bash
lsof -nP -p PID
```

The intended final chain is:

```text
URL → CF-Ray → PID → runtime classification → WordPress evidence → live PHP stack
```

## Installation

1. Install the repository as a normal WordPress plugin.
2. Activate **Request Monitor**.
3. Activation installs the mandatory MU bridge automatically.
4. Open **Tools → Request Monitor**.
5. Confirm `MU bootstrap: HEALTHY`.
6. Enable tracing.
7. Enable Deep attribution only for short investigation windows when needed.

The WordPress/PHP user must be able to create/write `wp-content/mu-plugins` during activation or repair.

## Security and privacy

Request Monitor does not intentionally log arbitrary POST bodies or complete query strings.

The trace directory is:

```text
wp-content/rocket-request-tracer/
```

The active log uses a randomized `.php` filename, starts with `exit; __halt_compiler();`, and the directory receives an `.htaccess` deny rule. JSONL download requires WordPress administrator capability plus nonce validation.

SQL literals are normalized before persistence.

Treat trace output as sensitive diagnostic data and clear it after an investigation.

## Performance considerations

Basic tracing uses lightweight request/process measurements.

Deep attribution retains WordPress SQL query metadata in PHP memory and should be enabled only for short diagnostic windows on production sites.

## Remaining blind spots

The MU layer materially improves coverage, but it still does not execute before WordPress begins loading core bootstrap files. If pre-WordPress PHP bootstrap visibility is ever required, an `auto_prepend_file` layer would be earlier still.

The plugin also cannot safely inspect another worker's executing PHP stack by itself; `phpspy`, `strace`, and similar privileged tools remain the escalation layer.

## Repository layout

```text
.
├── rocket-request-tracer.php
├── mu/
│   └── request-monitor-bootstrap.php
├── README.md
├── CHANGELOG.md
├── SECURITY.md
└── docs/
    └── architecture.md
```

## Next roadmap items

With the MU foundation in place, the next pieces can be tackled independently:

- slow-request thresholding and sampling controls
- per-hook / per-callback timing
- request fingerprint grouping
- query-hash correlation
- MySQL process correlation
- CLI companion for active PIDs
- tighter `phpspy` integration when available on the host
- incident report/export views
- dedicated WP-CLI tracing controls
