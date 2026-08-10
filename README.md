# Request Monitor

Request Monitor is an incident-focused WordPress diagnostics plugin for tracing the exact PHP/LSAPI work behind slow requests and worker-pool saturation.

It correlates:

```text
request / CF-Ray / client
        ↓
exact PHP PID
        ↓
mandatory MU bootstrap
        ↓
CPU / wall / memory / I/O
        ↓
WordPress lifecycle + hook/callback timing
        ↓
SQL + outbound HTTP attribution
        ↓
request/query fingerprints
        ↓
repeated expensive request families
```

**Current version: 0.5.0**

## What v0.5 adds

### Repeated request/query fingerprints

Every traced request receives four privacy-conscious fingerprints:

- **Request fingerprint** — method + concrete normalized path + AJAX/WC action + query-value fingerprint.
- **Pattern fingerprint** — method + templated path + action + query-key shape.
- **Query fingerprint** — sorted query keys plus recursively hashed values.
- **Query-shape fingerprint** — sorted query keys only.

Pattern normalization groups families such as:

```text
/shop/page/37/  → /shop/page/{n}
/product/12345/ → /product/{n}
/api/550e8400-e29b-41d4-a716-446655440000/ → /api/{uuid}
```

Raw arbitrary query values are not persisted for fingerprinting. Values influence hashes, while only the existing safe-query allowlist is stored in readable form.

The dashboard and WP-CLI can aggregate fingerprints by count, slow requests, CPU-bound requests, average/max wall time, and CPU time.

### Configurable trace scopes

Tracing can be limited before a trace context/log record is created.

Supported dimensions:

- request type: `front`, `admin`, `ajax`, `rest`, `cron`, `cli`
- HTTP/CLI method
- include path globs
- exclude path globs
- include AJAX/WooCommerce actions
- exclude AJAX/WooCommerce actions

Defaults include all web request types and exclude WP-CLI. Add `cli` explicitly when you want to profile WP-CLI workloads.

Request Monitor's own WP-CLI management commands are always excluded from tracing.

### WP-CLI control

The normal operational workflow no longer requires wp-admin:

```bash
wp request-monitor repair
wp request-monitor enable --slow-ms=1500
wp request-monitor scope set --types=front,ajax,rest --include-paths='/shop/*,/furniture/*'
wp request-monitor status
wp request-monitor active
wp request-monitor fingerprints --mode=pattern --sort=cpu
```

Disable when finished:

```bash
wp request-monitor disable
```

See [docs/cli.md](docs/cli.md).

## Existing tracing foundations

### Mandatory MU foundation

Activation installs and continuously verifies:

```text
wp-content/mu-plugins/request-monitor-bootstrap.php
wp-content/mu-plugins/request-monitor-runtime.php
wp-content/mu-plugins/request-monitor-hook-profiler.php
```

Tracing is unavailable if the bundled MU foundation is unhealthy.

### Automatic slow-request escalation

Basic tracing remains bounded. A configurable slow threshold (default `1500 ms`) automatically escalates a request for richer diagnostics.

### Hook and callback timing

- whole-hook timing is collected from the beginning of traced requests
- exact eligible plugin/theme callback timing starts after automatic escalation
- Deep mode starts exact callback timing from request start
- callbacks with by-reference parameters are deliberately not wrapped

### CPU vs wait classification

Completed requests are classified as:

- `FAST`
- `CPU_BOUND`
- `WAIT_BOUND`
- `MIXED`

This is based on request wall time compared with actual PHP CPU time.

### Deep attribution

Deep mode can capture:

- `$wpdb` query timing and caller information
- slow normalized SQL shapes
- outbound WordPress HTTP calls, duration and caller
- hook/callback owner attribution
- WordPress main-query context

### Live PID escalation

For an active request, use its exact PID with host tools:

```bash
ps -p PID -o pid,ppid,user,stat,etime,time,%cpu,%mem,rss,wchan:32,cmd
timeout 5 strace -f -ttT -s 256 -p PID
lsof -nP -p PID
sudo phpspy --pid=PID --limit=200
```

## WP-CLI examples

Trace only frontend catalog traffic:

```bash
wp request-monitor scope set \
  --types=front \
  --methods=GET \
  --include-paths='/shop/*,/furniture/*'
wp request-monitor enable
```

Trace AJAX only:

```bash
wp request-monitor scope set --types=ajax
wp request-monitor enable --deep
```

Trace selected AJAX actions:

```bash
wp request-monitor scope set \
  --types=ajax \
  --include-actions='some_action,wc_*'
```

Trace WP-CLI work itself:

```bash
wp request-monitor scope set --types=cli
wp request-monitor enable
wp cron event run --due-now
wp request-monitor disable
```

`wp request-monitor ...` commands themselves are never traced.

Find repeated expensive families:

```bash
wp request-monitor fingerprints --mode=pattern --sort=cpu --min-count=2
```

Find exact repeated request/query combinations:

```bash
wp request-monitor fingerprints --mode=request --sort=wall --slow-only
```

## Security / privacy

Request Monitor is intended for short production diagnostic windows.

- arbitrary POST bodies are not logged
- arbitrary query values are not persisted by fingerprinting
- SQL literals are normalized/redacted before storage
- trace files use randomized `.php` filenames with an immediate `exit` / `__halt_compiler()` guard
- an `.htaccess` deny is added as defense in depth
- logs should still be treated as diagnostic/sensitive data

## Repository layout

```text
.
├── rocket-request-tracer.php
├── includes/
│   ├── class-request-monitor-core.php
│   ├── class-request-monitor-store.php
│   ├── class-request-monitor-admin.php
│   └── class-request-monitor-cli.php
├── mu/
│   ├── request-monitor-bootstrap.php
│   ├── request-monitor-runtime.php
│   └── request-monitor-hook-profiler.php
└── docs/
    ├── architecture.md
    ├── cli.md
    ├── fingerprints-scopes.md
    └── slow-hook-profiling.md
```

## Roadmap

The immediate roadmap intentionally focuses on richer raw diagnostic evidence rather than an incident-report generator.

Potential next foundations include:

- MySQL connection/process correlation
- richer active-PID host integration
- request-fingerprint trend windows
- configurable retention and sampling strategies
- Redis/object-cache attribution

## Development status

This is an experimental production diagnostic tool, not a permanent APM replacement. Keep tracing disabled when it is not needed and use Deep mode for controlled investigation windows.
