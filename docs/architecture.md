# Architecture

## v0.5 request path

```text
incoming request / WP-CLI workload
        ↓
MU runtime classifies request type and evaluates trace scope
        ↓ allowed
fingerprints + request identity + resource baseline
        ↓
MU START record
        ↓
hook profiler / slow escalation
        ↓
normal Request Monitor plugin enrichment
        ↓
MU shutdown
        ↓
END record with CPU/wall/I/O/hooks/SQL/HTTP
        ↓
store pairs START + END
        ↓
fingerprint aggregation / CLI / admin UI
```

## Modules

### `mu/request-monitor-runtime.php`

Low-level utilities available before the normal plugin:

- resource snapshots
- safe query handling
- path normalization and pattern templating
- request/query fingerprint creation helpers
- request-type detection
- scope parsing/matching
- log storage/rotation
- lifecycle utility functions

### `mu/request-monitor-bootstrap.php`

Owns trace START/END and the shared request context.

It rejects requests outside the configured scope before creating a trace.

### `mu/request-monitor-hook-profiler.php`

Owns whole-hook timing and safe plugin/theme callback timing after automatic slow escalation or from request start in Deep mode.

### `includes/class-request-monitor-core.php`

Owns WordPress-aware enrichment, settings, mandatory MU install/repair and lifecycle integration.

### `includes/class-request-monitor-store.php`

Reads trace records, pairs START/END events and aggregates fingerprint groups.

### `includes/class-request-monitor-cli.php`

Provides operational control without wp-admin.

### `includes/class-request-monitor-admin.php`

Provides an optional visual interface; it is not required for incident operation.

## Fingerprinting

Four identifiers are written at START and propagated to END:

- request fingerprint
- pattern fingerprint
- query fingerprint
- query-shape fingerprint

Client IP, User-Agent and CF-Ray are intentionally not part of these fingerprints. The goal is to group application workload independent of who generated it.

## Scope evaluation

Scope filtering occurs in the MU layer before resource tracing begins. This keeps narrow incident captures narrow at the source instead of collecting everything and filtering afterward.

Request Monitor's own WP-CLI commands are always excluded. Other WP-CLI workloads are eligible when `cli` is included in scope.

## Operational principle

The dashboard is a viewer, not a dependency. During a saturated server incident the preferred control plane is:

```bash
wp request-monitor ...
```
