# Architecture

```text
bounded capture deadline
        ↓
MU bootstrap admission gate
        ↓
request identity + PID + resource baseline
        ↓
Deep: request-local SAVEQUERIES=true
Hooks/Deep: hook profiler
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
  ├─ callback + owner aggregation
  ├─ SQL fingerprints + caller stacks
  ├─ HTTP dependencies
  ├─ lifecycle hotspots
  ├─ action hotspots
  └─ residual wait estimate
        ↓
CLI analysis / inspect
```

## Safety boundary

The 5–300 second absolute capture deadline remains authoritative. A request admitted before the deadline may finish and write END afterward, but requests starting after expiry are not instrumented.

`SAVEQUERIES` is defined inside an admitted PHP request only. It therefore dies with the request and is not a persistent site setting.

## PHP stack scope

v0.7 intentionally does not add a host-level sampling adapter. PHP attribution is therefore:

- exact registered WordPress plugin/theme callback boundary timing
- source file/line and hook context
- richer PHP caller stacks for slow SQL and outbound HTTP points

It is not a continuously sampled arbitrary Zend VM stack.
