# WP-CLI

## Capture

```bash
wp request-monitor capture 30s
wp request-monitor capture 30s --profile=hooks
wp request-monitor capture 20s --profile=deep
```

Valid profiles are exactly `light`, `hooks`, and `deep`. Invalid values fail.

`capture` waits by default, then runs root-cause analysis automatically.

Fire-and-forget:

```bash
wp request-monitor capture 60s --no-wait
```

Later:

```bash
wp request-monitor analyze --session=last
```

## Analyze

```bash
wp request-monitor analyze --session=last
wp request-monitor analyze --session=last --format=json
```

## Inspect a fingerprint

```bash
wp request-monitor inspect <fingerprint> --session=last
```

This filters callback, SQL, HTTP, lifecycle and request evidence to matching request/pattern/query fingerprints and prints additional representative stacks.

## Scope

```bash
wp request-monitor scope get
wp request-monitor scope reset
wp request-monitor scope set \
  --types=front,ajax,rest \
  --methods=GET,POST \
  --include-paths='/shop/*,/furniture/*' \
  --exclude-paths='/shop/private/*' \
  --include-actions='wc_*,product_filter_*' \
  --exclude-actions='heartbeat'
```

## Raw fingerprint grouping

```bash
wp request-monitor fingerprints --session=last --mode=pattern --sort=cpu --min-count=1
wp request-monitor fingerprints --session=last --mode=request --sort=wall
wp request-monitor fingerprints --session=last --mode=query --sort=count
wp request-monitor fingerprints --session=last --mode=query-shape --sort=count
```

## Operations

```bash
wp request-monitor status
wp request-monitor active --session=last
wp request-monitor stop
wp request-monitor clear
wp request-monitor export --file=/tmp/request-monitor.jsonl
wp request-monitor repair
```
