# Snapshot and WP-CLI workflow

Request Monitor v0.6 is snapshot-only.

## First-line capture

```bash
wp request-monitor capture 30s
```

Default profile: `light`. The command clears the previous trace, opens a 30-second admission window, waits, closes it, then prints fingerprints.

## Profiles

```bash
wp request-monitor capture 30s --profile=light
wp request-monitor capture 30s --profile=hooks
wp request-monitor capture 20s --profile=deep
```

Use `light` first.

## Self-expiring no-wait capture

```bash
wp request-monitor capture 60s --no-wait
```

The timestamp expires even if SSH disconnects.

Later:

```bash
wp request-monitor status
wp request-monitor fingerprints --session=last --min-count=1
```

## Stop early

```bash
wp request-monitor stop
```

## Status

```bash
wp request-monitor status
wp request-monitor status --format=json
```

States are only `idle` and `capturing`.

## Scopes

```bash
wp request-monitor scope set \
  --types=front,ajax,rest \
  --methods=GET,POST \
  --include-paths='/shop/*,/furniture/*'
```

## Continuous mode removed

`wp request-monitor enable` and `wp request-monitor deep on` intentionally fail and explain the bounded capture syntax.
