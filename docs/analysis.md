# Root-cause analysis

v0.7 treats raw traces as intermediate evidence. The primary user-facing result is an analysis of a bounded capture session.

## Commands

```bash
wp request-monitor analyze --session=last
wp request-monitor inspect <fingerprint> --session=last
```

## Analysis layers

### Request workload

Requests are grouped by stable pattern fingerprint and summarized by:

- count
- slow count
- aggregate/average/max wall time
- aggregate/average PHP CPU
- CPU share
- measured SQL duration
- measured outbound HTTP duration
- residual wait estimate
- peak request memory

### PHP callbacks

Hook profiles are combined across completed requests. In `hooks` and `deep`, eligible plugin/theme callbacks are timed from request start. Callback identity includes owner, callable, hook, priority and source location.

The profiler measures the registered WordPress callback boundary. It does not continuously sample arbitrary nested PHP functions inside that callback.

### SQL

Deep mode enables `SAVEQUERIES` at the MU stage for each traced request. `hooks` mode may enable it after the slow threshold is crossed.

Normalized SQL groups include:

- query fingerprint
- execution count
- request count
- total/average/max query duration
- normalized query shape
- dominant WordPress caller
- dominant plugin/theme owner when a slow-query stack identifies one
- representative slow-query PHP stack

A `log_query_custom_data` filter captures `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, ...)` only when the query exceeds the configured slow-query threshold.

### Outbound HTTP

WordPress HTTP calls are grouped by sanitized endpoint, owner and caller. Representative caller stacks are retained in `hooks` and `deep` profiles.

### Lifecycle

MU/WordPress lifecycle phase durations are aggregated to identify where wall time accumulates before/after `plugins_loaded`, `init`, `wp`, `template_redirect`, admin/REST initialization and shutdown.

## Residual wait

The analyzer computes:

```text
residual = max(0, wall - PHP CPU - SQL - HTTP)
```

This is intentionally labelled an estimate. It can indicate time worth investigating in Redis/object cache, filesystem, locks, sockets, scheduler contention or uninstrumented dependencies.
