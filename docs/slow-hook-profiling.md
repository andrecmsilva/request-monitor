# Slow-request escalation and hook profiling

Request Monitor v0.4.0 adds two complementary tracing layers:

1. automatic slow-request escalation
2. WordPress hook and plugin/theme callback timing

The goal is to increase attribution without turning every traced request into a full profiler run.

## Slow threshold

The default slow threshold is **1500 ms** and can be changed under **Tools → Request Monitor**.

Every traced request still receives the normal MU-level START/END measurements. The threshold controls when richer application profiling is retained and when callback timing is automatically armed.

A completed END record contains:

```json
{
  "slow_request": true,
  "slow_threshold_ms": 1500,
  "slow_over_ms": 8412.2,
  "capture_level": "auto_slow",
  "auto_escalated": true,
  "auto_escalated_at_ms": 1513.7
}
```

### Basic mode

Basic mode is optimized for temporary production diagnostics.

From request start it records:

- request/PID correlation
- CPU and wall time
- process resource counters
- WordPress lifecycle phases
- whole-hook timing
- outbound WordPress HTTP timing

Once elapsed wall time crosses the configured slow threshold at a hook boundary, Request Monitor automatically arms:

- eligible plugin/theme callback timing
- `$wpdb->save_queries` for SQL occurring after escalation

The final record is marked `auto_slow` if the request completes above the threshold.

### Deep mode

Deep mode starts the richer collectors immediately:

- callback timing from the first eligible hook callback
- `$wpdb->save_queries` from MU bootstrap
- outbound WordPress HTTP timing from normal-plugin load

Deep mode is intended for shorter, deliberate diagnostic windows.

## Why escalation cannot be perfectly retroactive

PHP executes synchronously. If a request spends its first 4 seconds inside one callback, code running at second 4 cannot recover exact function timing from seconds 0–4.

Request Monitor handles this honestly:

- whole-hook timing is collected from the beginning
- exact eligible callback timing starts when Deep mode is enabled or automatic escalation is armed
- records include `callback_timing_started_ms`
- SQL records identify whether coverage was `from_start` or `post_threshold`

This means the first slow request can still identify the slow WordPress hook and the callbacks registered on it even if exact callback timing began later.

## Whole-hook timing

The MU profiler attaches a lightweight end sentinel to WordPress hooks. It records inclusive duration for each hook invocation and aggregates:

- invocation count
- total time
- maximum single invocation
- a bounded callback manifest

Slow/deep records retain the top hooks by total duration.

Example:

```json
{
  "hook": "woocommerce_product_query",
  "count": 1,
  "total_ms": 2812.4,
  "max_ms": 2812.4,
  "callbacks": []
}
```

Hook duration is inclusive: nested work performed by a hook callback is part of that hook's elapsed time.

## Per-plugin/function callback timing

When callback timing is armed, Request Monitor inspects callbacks registered on each WordPress hook and times eligible application callbacks.

It records:

- hook name
- priority
- callable/class method
- source file
- owning plugin, MU plugin, or theme
- invocation count
- inclusive total time
- maximum invocation time

Example:

```json
{
  "hook": "pre_get_posts",
  "priority": 20,
  "callable": "Vendor_Filter::apply_filters",
  "owner": "plugin:vendor-filter",
  "count": 1,
  "total_ms": 1843.2,
  "max_ms": 1843.2
}
```

The report also aggregates timed callbacks by owner:

```json
{
  "owner": "plugin:vendor-filter",
  "count": 8,
  "total_ms": 3177.5,
  "max_ms": 1843.2
}
```

## Callback floor

The default callback detail floor is **5 ms**.

Callbacks faster than this are executed normally but are not retained as individual timing rows. This keeps memory and JSON output bounded on hook-heavy WordPress sites.

The profiler retains at most 250 callback aggregates in memory and emits only the highest-cost rows in the final record.

## Safety rules

Exact callback timing deliberately avoids callbacks that are unsafe to wrap.

### By-reference callbacks

A callback with a parameter declared by reference is not wrapped because inserting a generic userland proxy can change PHP reference semantics.

These callbacks are counted under:

```text
skipped_by_reference
```

and a bounded manifest is included for slow/deep requests.

### Core callbacks

WordPress core callbacks are not wrapped. Whole-hook timing still includes their contribution, but exact callback timing focuses on application code:

- regular plugins
- MU plugins
- themes

### Request Monitor callbacks

The profiler never wraps its own callbacks.

## Reading the report

The most useful order is:

1. `classification` — CPU-bound, wait-bound, mixed, fast
2. `hook_profile.top_hooks` — where WordPress lifecycle time accumulated
3. `hook_profile.top_owners` — which plugin/theme dominated timed callbacks
4. `hook_profile.top_callbacks` — exact callable/hook pairs
5. SQL and outbound HTTP attribution
6. live PID escalation with `phpspy`, `strace`, or `lsof` if application evidence remains insufficient

## Coverage fields

Do not interpret absent callback rows as proof that a plugin did no work.

Check:

- `hook_profile.mode`
- `hook_profile.callback_timing_armed`
- `hook_profile.callback_timing_started_ms`
- `hook_profile.skipped_by_reference`
- `deep_coverage.mode`
- SQL `coverage`

These fields describe how much of the request was observable by each collector.
