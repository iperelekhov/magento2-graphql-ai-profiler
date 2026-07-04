---
name: graphql-profiler
description: Diagnose why a Magento GraphQL request is slow. Use when the user asks "why is this request slow", "profile this query", or pastes a curl command / GraphQL query (with optional variables) and wants a latency breakdown. Sends the request through the Prlkhv_GraphQlAiProfiler, parses the returned span tree, and reports the critical path, slowest resolvers, N+1 / duplicate SQL, and concrete next steps.
---

# GraphQL Profiler

Find out **why a Magento GraphQL request is slow** and tell the user what to do
about it.

The `Prlkhv_GraphQlAiProfiler` module instruments every GraphQL resolver and every
SQL query server-side, and returns a span tree in the response under
`extensions.profiling` — but only when the request carries the profiler headers.
This skill sends the request with those headers, then analyzes the spans.

The compact payload format (single-letter keys, no in-band legend) is defined
below under [Payload format](#payload-format-ai). `scripts/analyze.py` already
decodes it; consult the tables only when reading spans by hand.

## Inputs the user may give

- A full `curl ...` command → reuse its URL/method/headers/body; just add the
  profiler headers below.
- A GraphQL query string (+ optional variables JSON) → build the POST yourself.
- A `.graphql` file path → read it, then build the POST.

Ask the user for the endpoint URL if you can't determine it, and for the
**activation secret** (the value of `dev/graphql_profiler/secret`) if you don't
already have it from this conversation. Treat the secret as a per-session input —
don't store it in a file.

## Workflow

### 1. Send the request with the profiler headers

Add these headers on top of whatever the user provided:

```
X-GraphQl-Profiler: <SECRET>
X-GraphQl-Profiler-Format: ai
X-GraphQl-Profiler-Sql: 1          # include raw SQL so you can name the slow tables
```

**Put the request body in a file and use `curl --data @body.json`.** Inline
`-d '{"query":"{ ... \"x\" ... }"}'` gets its `\"` escapes mangled by shell
quoting and yields invalid JSON. This is the single most common failure — use a
body file for anything beyond a trivial query.

Run **2–3 times** and analyze the last run. The first hit warms caches (config,
EAV, FPC); cold-cache numbers misrepresent steady-state latency.

Save the JSON response. If `extensions.profiling` is absent, the profiler didn't
activate — most likely a wrong/missing secret. Do not analyze an empty result.

### 2. Analyze

Pipe the response JSON into `scripts/analyze.py`, or pass a saved file:

```
curl ... --data @body.json | python3 scripts/analyze.py
# or
python3 scripts/analyze.py response.json
```

Pure Python 3, no dependencies. It prints total wall time, slowest spans by
self-time, SQL grouped by `dh` hash (duplicate/N+1 detection), and per-resolver DB
cost. Read its output, then write the diagnosis — the script surfaces signals, you
provide the judgment.

### 3. Report to the user

1. **Verdict** — one line: where the time actually goes (e.g. "78% of 240ms is 34
   duplicate `catalog_product_entity` lookups fired by the `products` resolver —
   a classic N+1").
2. **Breakdown** — total ms, top 3–5 spans by self-time, DB vs resolver split.
3. **Root cause** — the specific resolver/query and *why* it's slow (N+1, one fat
   query, a serial resolver chain, repeated identical SQL hinting a missing index).
4. **Next steps** — concrete, Magento-native, ranked by impact: collection
   preloading / batched `addFieldToFilter`, a resolver-level `BatchResolver`, a
   data-loader, caching, an index, fewer requested fields. Tie each to a span you saw.

Lead with the answer — the user asked "why is it slow."

## Reading the spans

How to reason about the tree once decoded:

- **Self-time** = a span's `d` minus its children's `d` (spans whose `p` equals
  its `i`). High self-time = where time is actually spent.
- Sibling spans may overlap (batched/deferred resolvers); don't just sum all `d`.
- **N+1 signal** = many `db.query` spans sharing the same `dh` hash under resolvers
  of the same type. Count them and multiply.
- `db.statement` (`dq`) appears only when you sent `X-GraphQl-Profiler-Sql: 1` —
  use it to name the table and query shape.

## Payload format (ai)

The `ai` payload optimizes for LLM token cost: the OTLP envelope is dropped, keys
are single letters, IDs are truncated, timestamps are relative, and **no legend
rides in the response** — these tables are the legend.

**Top level** (`extensions.profiling`):

| key  | meaning                          |
|------|----------------------------------|
| `t`  | traceId, first 6 hex chars       |
| `sv` | service.name (`magento-graphql`) |
| `sp` | spans (array)                    |

**Per span** (each entry in `sp`):

| key | meaning           | notes                                           |
|-----|-------------------|-------------------------------------------------|
| `i` | spanId            | first 6 hex chars; unique within the response   |
| `p` | parentSpanId      | first 6 hex chars; `""` if root                 |
| `n` | name              | e.g. `Query.products`, `db.query`               |
| `s` | start offset (µs) | microseconds since the trace's earliest span    |
| `d` | duration (µs)     | microseconds                                    |
| `x` | statusCode        | **omitted when 0/unset**; `1` = ok, `2` = error |
| `a` | attributes        | **omitted when empty**                          |

Wall-clock end of a span is `s + d`. Trace start itself is not emitted.

**Attribute keys** (inside `a`):

| key  | original                 | notes                                          |
|------|--------------------------|------------------------------------------------|
| `gf` | graphql.field            |                                                |
| `gp` | graphql.parent_type      |                                                |
| `rc` | magento.resolver.class   |                                                |
| `ds` | db.system                |                                                |
| `dq` | db.statement (truncated) | only present with `X-GraphQl-Profiler-Sql: 1`  |
| `dh` | db.statement.hash        | xxh128 of normalized SQL; **not** truncated    |
| `em` | error.message            |                                                |

Unknown/dynamic attribute keys pass through unshortened. Attribute values are raw
scalars (no OTLP `{stringValue: ...}` wrapper). Times are microseconds, not
nanoseconds. IDs are 6 hex chars — safe to correlate `p` → `i` within one
response, not globally unique.

If nothing comes back, the profiler is disabled or the request lacks a valid
secret — that setup is the module's concern (see its `README.md`), not this skill's.
