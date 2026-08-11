# Architecture

```text
bounded capture deadline
        ↓
MU bootstrap admission gate
        ↓
request identity + PID + resource baseline
        ↓
Deep: request-local SAVEQUERIES=true
Hooks/Deep: callback timing from request start
        ↓
START
        ↓
normal plugin enrichment
  ├─ lifecycle marks
  ├─ slow-query stack annotation
  └─ outbound HTTP caller stacks
        ↓
shutdown
  ├─ wall / CPU / memory / I/O
  ├─ callback/hook profile
  ├─ normalized SQL groups
  ├─ HTTP groups
  └─ END
        ↓
Store pairs START + END
        ↓
Analyzer
  ├─ workload fingerprints
  ├─ CPU demand
  ├─ callback + owner + hook aggregation
  ├─ SQL fingerprints + caller stacks
  ├─ HTTP dependencies
  ├─ lifecycle hotspots
  ├─ action hotspots
  └─ residual wait estimate
        ↓
CLI analysis / inspect
```

## Safety boundary

The absolute capture deadline remains authoritative. `light` allows 5–300 seconds, `hooks` 5–60 seconds, and `deep` 5–30 seconds. A request admitted before the deadline may finish and write END afterward, but requests starting after expiry are not instrumented.

`SAVEQUERIES` is defined inside an admitted PHP request only. It therefore dies with the request and is not a persistent site setting.

Outside a capture, the MU bootstrap returns before loading runtime or profiler helpers.

## Profile behavior

- `light`: request/PID/resource/fingerprint evidence only.
- `hooks`: eligible plugin/theme callbacks and whole hooks are timed from request start. SQL retention begins only if the request crosses the slow threshold.
- `deep`: callback timing and SQL retention begin from the earliest MU stage available to Request Monitor.

## PHP stack scope

v0.7 intentionally does not add a host-level sampling adapter. PHP attribution is therefore:

- exact registered WordPress plugin/theme callback boundary timing
- source file/line and hook context
- richer PHP caller stacks for slow SQL and outbound HTTP points

It is not a continuously sampled arbitrary Zend VM stack.
