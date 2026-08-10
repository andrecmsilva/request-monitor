# Fingerprints and trace scopes

## Why fingerprint requests

A traffic incident often contains many IPs, Ray IDs and URLs that are individually different but exercise the same application workload.

Request Monitor creates stable identifiers so repeated work can be grouped by cost rather than by client identity.

## Fingerprint levels

### Request fingerprint

Represents a concrete request/query combination.

Inputs include:

- method
- normalized concrete path
- AJAX/WooCommerce action
- query fingerprint

Use it to answer: **is this exact application request repeating?**

### Pattern fingerprint

Represents a request family.

Path templates normalize common identifiers:

```text
/shop/page/37/     → /shop/page/{n}
/product/12345/    → /product/{n}
/api/<uuid>/       → /api/{uuid}
```

The pattern fingerprint combines:

- method
- templated path
- action
- query-key shape

Use it to answer: **is this family of requests filling the worker pool?**

### Query fingerprint

Sorted query keys plus recursively hashed values.

Different values produce different fingerprints without requiring the raw values to be persisted.

### Query-shape fingerprint

Sorted query keys only.

This groups requests that have the same parameter structure regardless of values.

## Privacy properties

Fingerprint generation hashes arbitrary query values. Those raw values are not written into the fingerprint basis.

Readable query values remain limited to Request Monitor's existing explicit safe-query allowlist.

## Group metrics

Fingerprint aggregation records:

- count
- slow request count
- CPU-bound request count
- average wall time
- average CPU time
- total CPU time
- maximum wall time
- maximum CPU time
- peak memory
- representative path/pattern/action/query keys

Total CPU is particularly useful during saturation: a moderately slow fingerprint repeated hundreds of times can be more important than one extreme outlier.

## Trace scopes

Scopes are evaluated by the MU runtime before START is written.

Dimensions:

- request type
- method
- include paths
- exclude paths
- include actions
- exclude actions

Default request types:

```text
front admin ajax rest cron
```

WP-CLI is excluded until `cli` is explicitly added.

### Matching order

1. request type must be allowed
2. method must match when method restrictions exist
3. include path must match when include paths exist
4. exclude path must not match
5. include action must match when include actions exist
6. exclude action must not match

Excludes therefore win over includes.

### Glob syntax

Scopes use lightweight globs:

```text
*  any sequence
?  one character
```

Examples:

```text
/shop/*
/wp-json/wc/*
product_filter_*
```
