# Request Monitor

Request Monitor is a temporary production diagnostics plugin for WordPress that correlates an incoming HTTP request with the **exact PHP/LSAPI PID handling it**, then traces enough of the request lifecycle to explain why PHP workers remain occupied.

It is designed for incidents that start like this:

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
mandatory MU bootstrap START
        ↓
WordPress lifecycle + hook timing
        ↓
slow-request automatic escalation
        ↓
plugin/theme callback timing
        ↓
CPU / wall / memory / I/O
        ↓
SQL + outbound HTTP attribution
        ↓
optional live PID inspection
```

## Current version

**v0.4.0**

Request Monitor is an incident-analysis tool, not a permanent APM replacement. Keep tracing disabled when it is not needed.

## Mandatory MU foundation

The normal plugin automatically installs two required files into `wp-content/mu-plugins`:

```text
request-monitor-bootstrap.php
request-monitor-hook-profiler.php
```

The regular plugin will not enable tracing unless both deployed files match the bundled versions.

The MU layer owns the authoritative request START and END records so tracing begins before normal plugins load and survives through PHP shutdown.

## What is captured

### Request identity

- request ID
- PHP PID / PPID / UID
- UTC timestamp
- method / host / path
- safe selected query parameters
- WordPress AJAX action
- WooCommerce `wc-ajax`
- Cloudflare Ray ID
- Cloudflare connecting IP
- remote IP
- User-Agent / Referer
- request content type / length

### Runtime behavior

- wall time
- PHP user/system/total CPU
- CPU-to-wall ratio
- CPU / WAIT / MIXED / FAST classification
- peak/end PHP memory
- page faults
- voluntary/involuntary context switches
- block I/O counters
- `/proc/self/io` counters when available
- included PHP files and code ownership

### WordPress lifecycle

The MU/regular-plugin handoff records phase timing around points such as:

- MU bootstrap
- regular plugin load
- `plugins_loaded`
- `after_setup_theme`
- `init`
- `wp_loaded`
- request parsing
- `wp`
- REST/admin initialization
- `template_redirect`
- shutdown

## Automatic slow-request escalation

The default threshold is **1500 ms** and is configurable under **Tools → Request Monitor**.

Basic tracing deliberately does not enable every expensive collector from request start. Instead:

1. request/PID/resource tracing starts immediately
2. whole WordPress hooks are timed from request start
3. outbound WordPress HTTP calls are timed from request start
4. once elapsed request time crosses the slow threshold, exact eligible plugin/theme callback timing is armed
5. SQL `SAVEQUERIES` is enabled from the escalation point forward
6. slow requests persist the rich hook/callback report

The final record exposes:

```text
slow_request
slow_threshold_ms
slow_over_ms
capture_level
auto_escalated
auto_escalated_at_ms
auto_escalation_reason
```

This keeps basic tracing bounded while automatically enriching requests that become operationally interesting.

## Deep mode

Deep mode starts rich attribution from the beginning of the request:

- exact eligible plugin/theme callback timing
- SQL query retention
- outbound WordPress HTTP timing

Deep mode provides better coverage but has higher request-memory/CPU overhead and should be used for deliberate diagnostic windows.

## Hook timing

Every traced request receives lightweight whole-hook timing.

For slow/deep requests, the report includes the most expensive hooks with:

- invocation count
- total inclusive duration
- maximum duration
- bounded callback manifest

This is particularly useful on the first slow request: even when a single callback crosses the threshold before exact callback timing is armed, the enclosing WordPress hook can still be identified.

## Per-plugin/function callback timing

When callback timing is armed, Request Monitor times eligible application callbacks registered on WordPress hooks.

Each retained callback contains:

- hook
- priority
- callable / class method
- owning plugin, MU plugin, or theme
- source file
- invocation count
- inclusive total time
- maximum invocation time

The report also aggregates callback time by owner, making results such as this possible:

```text
plugin:woocommerce-product-filter
    4 callbacks
    3,812 ms inclusive

Vendor_Filter::build_facets
    hook: pre_get_posts
    2,947 ms
```

### Safety rules

Exact callback timing intentionally avoids callbacks that declare by-reference parameters. A generic timing proxy can alter PHP reference semantics, so those callbacks are left untouched and reported as skipped.

WordPress core callbacks are not wrapped. Whole-hook timing still includes their contribution; exact callback timing focuses on plugin, MU-plugin, and theme application code.

The callback timing store is bounded and ignores individual callback samples below the configurable detail floor (default **5 ms**).

See [`docs/slow-hook-profiling.md`](docs/slow-hook-profiling.md) for the coverage and safety model.

## SQL attribution

When SQL collection is active, Request Monitor records:

- query count
- total query time
- top slow queries
- normalized/redacted SQL shape
- query hash
- WordPress caller information
- explicit coverage (`from_start` or `post_threshold`)

SQL values are normalized before being persisted.

## Outbound HTTP attribution

WordPress HTTP API calls are timed from normal-plugin load for every traced request and can include:

- URL without query string
- duration
- status / error
- caller summary
- transport

Slow/deep requests retain the detailed call list.

## Active PID escalation

A START record without a matching END record is displayed as `ACTIVE`.

The dashboard produces commands for the exact PID:

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

This gives the escalation path:

```text
HTTP request
    ↓
PID
    ↓
Request Monitor evidence
    ↓
exact WordPress hook/plugin callback evidence
    ↓
phpspy / strace only when needed
```

## Installation

1. Install and activate the plugin normally.
2. Activation installs/updates the mandatory MU components.
3. Open **Tools → Request Monitor**.
4. Confirm **MU foundation: HEALTHY**.
5. Enable tracing.

If `wp-content/mu-plugins` cannot be written, activation fails closed instead of silently running with reduced capture.

## Recommended settings

For a first production incident capture:

```text
Tracing:               ON
Deep from request start: OFF
Slow threshold:        1500 ms
Callback detail floor: 5 ms
Max log:               25 MB
```

If the automatic capture identifies an expensive hook but callback coverage started too late, repeat the request briefly with **Deep from request start** enabled.

## Reading a slow request

A useful order is:

1. `classification`
2. wall / CPU ratio
3. `hook_profile.top_hooks`
4. `hook_profile.top_owners`
5. `hook_profile.top_callbacks`
6. lifecycle phases
7. SQL attribution
8. outbound HTTP
9. process I/O/resource deltas
10. live PID tooling if still needed

Always check the coverage fields before concluding that missing callback/SQL detail means no work occurred there.

## Security and privacy

The tracer avoids arbitrary POST-body capture and full query-string logging.

Runtime logs live under:

```text
wp-content/rocket-request-tracer/
```

The active trace file:

- uses a randomized name
- uses a `.php` extension
- starts with `exit; __halt_compiler();`
- receives an `.htaccess` deny rule
- is downloadable only through a capability/nonce-protected admin action

Treat trace output as sensitive diagnostic data and clear it after the investigation.

## Important limitations

- This is application instrumentation, not a kernel profiler.
- Automatic callback timing cannot retroactively recover callbacks that completed before the threshold was crossed.
- Whole-hook timing is inclusive and can include nested hook work.
- Exact callback rows are also inclusive and can double-count nested work when aggregating across call layers.
- Direct database/network calls that bypass WordPress APIs may require host-level tracing.
- `phpspy`/`strace` still require server-level permissions.

## Documentation

- [`docs/architecture.md`](docs/architecture.md)
- [`docs/slow-hook-profiling.md`](docs/slow-hook-profiling.md)
- [`SECURITY.md`](SECURITY.md)
- [`CHANGELOG.md`](CHANGELOG.md)

## Roadmap

Remaining likely directions include:

- repeated request/query fingerprint grouping
- incident-report generation
- CLI companion for active PIDs
- optional host-side `phpspy` integration
- MySQL connection/process correlation
- configurable trace scopes
- WP-CLI/cron-specific analysis modes
- before/after mitigation comparison

## Development status

Request Monitor is experimental production diagnostics software. Validate releases on staging or a controlled site before broad deployment.
