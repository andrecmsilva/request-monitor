# WP-CLI

Request Monitor can be controlled without loading wp-admin.

## Status

```bash
wp request-monitor status
wp request-monitor status --format=json
```

## Enable / disable

```bash
wp request-monitor enable
wp request-monitor enable --deep
wp request-monitor enable --slow-ms=2500 --callback-floor=10
wp request-monitor disable
wp request-monitor deep on
wp request-monitor deep off
```

`enable` verifies/repairs the mandatory MU foundation first.

## Trace scopes

Read the active scope:

```bash
wp request-monitor scope get
```

Reset to defaults:

```bash
wp request-monitor scope reset
```

Set a scope:

```bash
wp request-monitor scope set \
  --types=front,ajax,rest \
  --methods=GET,POST \
  --include-paths='/shop/*,/furniture/*' \
  --exclude-paths='/shop/private/*' \
  --include-actions='wc_*,product_filter_*' \
  --exclude-actions='heartbeat'
```

Supported request types:

```text
front admin ajax rest cron cli
```

Empty method/path/action lists mean no restriction for that dimension.

Path and action rules use simple glob matching (`*` and `?`). Excludes are evaluated after includes.

The `cli` request type is opt-in by default. Request Monitor's own `wp request-monitor ...` commands are hard-excluded to prevent recursive/self-generated traces.

## Active requests

```bash
wp request-monitor active
wp request-monitor active --format=json
```

This shows START records with no matching END record in the current trace window.

## Fingerprint groups

Pattern families:

```bash
wp request-monitor fingerprints --mode=pattern --sort=cpu
```

Exact request/query combinations:

```bash
wp request-monitor fingerprints --mode=request --sort=wall
```

Query-value combinations only:

```bash
wp request-monitor fingerprints --mode=query --sort=count
```

Query-key shapes only:

```bash
wp request-monitor fingerprints --mode=query-shape --sort=count
```

Useful filters:

```bash
wp request-monitor fingerprints --min-count=3 --limit=30 --slow-only
```

Available sort modes:

```text
cpu wall count max
```

## Logs

```bash
wp request-monitor clear
wp request-monitor export --file=/tmp/request-monitor.jsonl
```

## MU repair

```bash
wp request-monitor repair
```
