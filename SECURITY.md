# Security

Rocket Request Tracer is intended for short diagnostic windows on production WordPress sites.

## Data handling

The plugin does not intentionally log arbitrary POST bodies or full query strings.

It stores selected request metadata and explicitly allowed diagnostic query keys.

Deep attribution normalizes common SQL literal values before persisting query text.

## Log storage

Logs are written under:

```text
wp-content/rocket-request-tracer/
```

The active log uses:

- a randomized filename
- a `.php` extension
- an immediate `exit`
- `__halt_compiler()`
- an `.htaccess` deny rule

Log downloads require a WordPress administrator capability and nonce validation.

## Operational recommendation

- Keep tracing disabled when not investigating an issue.
- Use Deep attribution only for short windows.
- Clear diagnostic logs after the investigation.
- Do not publish captured trace files in bug reports without reviewing them first.

## Reporting security issues

If this repository is used publicly, report potential security issues privately to the repository owner rather than publishing sensitive reproduction data in an issue.
