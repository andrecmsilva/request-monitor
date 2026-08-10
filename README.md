# Rocket Request Tracer

A temporary production diagnostics plugin for WordPress that correlates an incoming HTTP request with the **exact PHP/LSAPI process handling it**, then records enough runtime evidence to distinguish CPU-heavy PHP execution from requests waiting on databases, APIs, storage, locks, or other dependencies.

The project grew out of a common managed-hosting incident pattern:

```text
LSAPI queue fills
    ↓
dozens of lsphp workers appear
    ↓
traffic looks suspicious, but the actual server-side work is unclear
```

Rocket Request Tracer turns that into:

```text
HTTP request
    ↓
Cloudflare Ray / client / path
    ↓
PHP PID
    ↓
wall time vs CPU time
    ↓
memory + process resource behavior
    ↓
SQL / outbound HTTP attribution
    ↓
plugin/theme code ownership
    ↓
optional live PID inspection with strace / phpspy
```

## Why this exists

A process list can tell you that 50 `lsphp` workers exist, but not necessarily **which request each one is serving** or **what that request is doing**.

Cloudflare can tell you who reached the site and which path they requested, but it cannot explain what WordPress did after the request reached PHP.

This plugin bridges those layers.

It is designed for short incident-investigation windows on production WordPress sites, especially when you need to answer questions such as:

- Which URL is consuming the PHP pool?
- Which exact `lsphp` PID belongs to that request?
- Is the request actually burning CPU or mostly waiting?
- Is SQL responsible?
- Is WordPress waiting on an external HTTP API?
- Which plugins/themes contribute most of the loaded PHP code?
- Which live PID should I attach `strace` or `phpspy` to?
- Are repeated bot/catalog/filter requests individually expensive enough to exhaust the worker pool?

---

## Current version

**v0.2.0**

The project is still intentionally diagnostic and iterative. It is not intended to be a permanent APM replacement.

## Features

### Request ↔ PID correlation

Each traced request receives a unique request ID and records:

- PHP PID
- PPID
- effective UID
- UTC timestamp
- request method
- host
- path
- selected safe query parameters
- WordPress AJAX action
- WooCommerce `wc-ajax` action
- Cloudflare Ray ID
- Cloudflare connecting IP
- remote IP
- User-Agent
- Referer
- request content type and content length

That gives you a direct mapping such as:

```text
CF-Ray a28a04dadf32240a-EWR
GET /furniture/home-decor/
PID 2777019
```

### CPU vs wall-time classification

For every completed request the tracer records:

- wall time
- PHP user CPU
- PHP system CPU
- total CPU time
- CPU / wall ratio
- peak PHP memory
- ending PHP memory

Requests are automatically classified:

| Classification | Meaning |
|---|---|
| `FAST` | Wall time below 750 ms |
| `CPU_BOUND` | CPU time is at least ~70% of wall time |
| `WAIT_BOUND` | CPU time is at most ~25% of wall time |
| `MIXED` | Between the above thresholds |
| `ACTIVE` | START exists but no END has been recorded yet |
| `UNKNOWN` | Not enough runtime information |

Example:

```text
Wall: 29,009 ms
CPU:  28,947 ms
CPU ratio: 99.8%

→ CPU_BOUND
```

That is strong evidence that PHP/userspace execution — not an external wait — occupied a core for almost the entire request.

### Linux process resource deltas

Where available, the plugin records `getrusage()` and `/proc/self/io` deltas:

- minor page faults
- major page faults
- voluntary context switches
- involuntary context switches
- block input/output counters
- logical bytes read/written
- physical bytes read/written
- read syscall count
- write syscall count

These measurements help separate computation from I/O-heavy behavior.

### Included-code ownership

At shutdown, loaded PHP files are grouped into:

- WordPress core
- regular plugins
- MU plugins
- themes
- other PHP code

This is **not execution-time attribution**, but it is useful context when a request loads thousands of files.

### Deep attribution mode

Deep attribution is optional because it adds overhead.

When enabled, the tracer also collects:

#### SQL

- query count
- total SQL duration
- 10 slowest queries
- normalized/redacted query shape
- query hash
- WordPress caller information

SQL literal values are normalized before storage so diagnostic logs do not intentionally retain arbitrary customer query data.

#### WordPress outbound HTTP

- target URL without query string
- duration
- HTTP response code / error
- WordPress caller/backtrace summary
- transport class

This can turn a generic wait-bound request into something actionable:

```text
POST /wp-admin/admin-ajax.php
action=sync_inventory

Wall: 14.8s
CPU:   0.4s
→ WAIT_BOUND

Outbound HTTP:
13.9s https://api.vendor.example/sync

Caller:
Vendor_Plugin->sync_inventory()
```

### Expensive-path aggregation

The WordPress admin screen aggregates completed requests by path and shows:

- request count
- average wall time
- average CPU time
- maximum CPU time
- CPU-bound request count
- wait-bound request count

This makes repeated pathological endpoints visible without manually parsing the raw log.

### Active PID inspection

Requests with a START event and no matching END event are displayed as `ACTIVE`.

The UI generates ready-to-run server commands for the PID:

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

The plugin identifies the process. External server tooling can then inspect that exact live process.

---

## Installation

### WordPress Admin

1. Download or package the repository as a ZIP.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP.
4. Activate **Rocket Request Tracer**.
5. Open **Tools → Request Tracer**.

### Manual

Copy the plugin directory/file into:

```text
wp-content/plugins/rocket-request-tracer/
```

Then activate it from WordPress.

---

## Usage

Open:

**Tools → Request Tracer**

### Basic incident capture

1. Enable **Tracing**.
2. Leave **Deep attribution** disabled initially if the server is already under significant load.
3. Let traffic reproduce the problem.
4. Refresh the tracer page.
5. Check active PIDs, CPU-bound requests, wait-bound requests, expensive paths, peak memory, and included-code ownership.
6. If needed, enable Deep attribution for a shorter second capture.

### Deep incident capture

Enable tracing and Deep attribution, then reproduce the issue for a short diagnostic window.

Deep mode enables WordPress query capture and HTTP attribution, so it should not be treated as permanent production monitoring.

---

## Reading the results

### CPU-bound

```text
GET /furniture/home-decor/

Wall: 29.0 s
CPU:  28.9 s
Peak memory: 178 MB
Included PHP files: 6031

Classification: CPU_BOUND
```

The request spent almost all of its lifetime actively using PHP CPU. Check SQL contribution and included-code ownership; if they do not explain the time, inspect the active PID with a PHP stack sampler such as `phpspy`.

### Wait-bound

```text
Wall: 18.0 s
CPU:   0.2 s

Classification: WAIT_BOUND
```

Investigate MySQL, Redis, outbound HTTP, filesystem, sockets, locks, and upstream dependencies. Deep attribution may identify the dependency directly.

### Mixed

Both CPU execution and waiting materially contribute to response time.

---

## Live PHP stack inspection

A normal WordPress plugin cannot safely `ptrace()` another arbitrary live PHP worker. That is intentionally left to privileged host-level tools.

```text
Rocket Request Tracer
       ↓
ACTIVE request
       ↓
PID 2781087
       ↓
phpspy / strace / lsof
       ↓
executing PHP functions / syscalls / open sockets
```

Example:

```bash
sudo phpspy --pid=2781087 --limit=200
```

This provides the final hop:

```text
URL → PID → runtime behavior → executing PHP stack
```

---

## Security and privacy

The tracer intentionally avoids dumping arbitrary request bodies or full query strings. Captured query values are limited to explicitly allowed diagnostic keys.

The underlying trace is stored under:

```text
wp-content/rocket-request-tracer/
```

The active log has a randomized filename, uses a `.php` extension, starts with `exit; __halt_compiler();`, is additionally protected by an `.htaccess` deny rule, and can be downloaded through a nonce-protected WordPress admin action.

SQL statements are normalized/redacted before being stored.

Even with these protections, treat the output as diagnostic data and remove/clear it when the investigation is complete.

---

## Performance considerations

Basic mode records request/process metadata and lightweight runtime measurements and is intended for temporary production debugging.

Deep mode enables `$wpdb->save_queries`, which retains query details in PHP memory for the request. On sites executing very large numbers of SQL queries, this can increase request memory usage. Use Deep attribution for **short, controlled diagnostic windows**.

---

## Architecture

```text
Cloudflare / client request
          ↓
WordPress request
path / CF-Ray / IP / PID
          ↓
Runtime measurement
wall / CPU / memory / I/O
          ↓
SQL + HTTP attribution
          ↓
CPU / WAIT / MIXED / FAST
          ↓
Active PID escalation
ps / strace / lsof / phpspy
```

More detail is available in [`docs/architecture.md`](docs/architecture.md).

---

## Limitations

### Plugin bootstrap timing

This is a normal WordPress plugin. Tracing begins when WordPress loads the active plugin, not at the very first PHP instruction. A future implementation can use an MU-plugin or `auto_prepend_file` bridge while preserving the same dashboard/log format.

### PHP stack attribution

The plugin cannot inspect the executing PHP stack of another worker by itself. Use the reported PID with host-level tooling when function-level sampling is required.

### Included files are not CPU attribution

A plugin contributing many included PHP files does not prove that plugin consumed equivalent CPU time. Included-code ownership is contextual evidence only.

---

## Roadmap

- optional MU-plugin/bootstrap bridge
- safe automatic slow-request thresholding
- per-plugin/function hook timing
- automatic correlation of repeated query hashes
- request fingerprint grouping
- exportable incident reports
- CLI companion for active PIDs
- optional `phpspy` integration when available on the host
- automatic MySQL connection/process correlation
- configurable capture scopes
- WP-CLI and cron tracing modes
- time-window comparison before/after mitigation

---

## Repository layout

```text
.
├── rocket-request-tracer.php
├── README.md
├── CHANGELOG.md
├── SECURITY.md
├── .gitignore
└── docs/
    └── architecture.md
```

## Development status

This project is currently an internal/experimental diagnostic tool. Test carefully before long-running production use, and keep tracing disabled when it is not needed.
