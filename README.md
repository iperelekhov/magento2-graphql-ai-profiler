# Prlkhv_GraphQlAiProfiler

Server-side GraphQL profiler for Magento 2. It instruments GraphQL execution,
builds an in-memory span tree (every resolver + every SQL query), and injects it
into the GraphQL response under `extensions.profiling` — OTLP-shaped, or a compact
LLM-friendly format for AI-assisted analysis.

No OTel Collector, no exporter, no MCP. The profiling payload rides back on the
same response the client already receives, so you can profile any GraphQL request
by adding a header — no separate tooling, no APM.

It also ships with a **Claude Code skill** so you can ask an AI "why is this
request slow?" and get a root-cause analysis with next steps (see
[Using it with Claude](#using-it-with-claude)).

- **Requirements:** Magento 2.4.x, PHP 8.1–8.3.
- **Safe by default:** inert unless explicitly enabled, gated behind dev mode and
  a secret header. See [Security](#-security).

---

## Installation

### Via Composer (recommended)

```bash
composer require prlkhv/module-graphql-ai-profiler
bin/magento module:enable Prlkhv_GraphQlAiProfiler
bin/magento setup:upgrade
bin/magento setup:di:compile        # only if you run in production mode
```

### Manual

Copy this directory to `app/code/Prlkhv/GraphQlAiProfiler`, then:

```bash
bin/magento module:enable Prlkhv_GraphQlAiProfiler
bin/magento setup:upgrade
```

---

## Setup

The module is **inert until every activation condition is met**. Configure it
under **Stores → Configuration → Advanced → Developer → GraphQL Profiler**, or via
CLI:

```bash
bin/magento config:set dev/graphql_profiler/enabled 1
bin/magento config:set dev/graphql_profiler/secret 's0me-long-random-secret'
bin/magento config:set dev/graphql_profiler/mode_allowlist developer
bin/magento cache:flush
```

`Model\Config::isActive()` returns `true` only when **all** of these hold:

| # | Condition | Config path | Default |
|---|-----------|-------------|---------|
| 1 | Enabled | `dev/graphql_profiler/enabled` = `1` | `0` |
| 2 | Deploy mode allowed | `dev/graphql_profiler/mode_allowlist` contains the current mode | `developer` |
| 3 | Secret matches | request header `X-GraphQl-Profiler` == `dev/graphql_profiler/secret` (`hash_equals`) | empty |

If any fails, every plugin passes through with near-zero overhead and nothing
profiling-related appears in the response.

### All settings

```
dev/graphql_profiler/enabled                    0|1
dev/graphql_profiler/mode_allowlist             developer[,default,production]
dev/graphql_profiler/secret                     <shared secret>   (stored encrypted)
dev/graphql_profiler/sql_statement_max_length   2000              (db.statement truncation)
```

---

## Usage

Add the activation header to any GraphQL request. Choose a response format with
`X-GraphQl-Profiler-Format`.

### Verbose OTLP format (default)

```bash
curl -s -X POST https://your-store.test/graphql \
  -H "Content-Type: application/json" \
  -H "X-GraphQl-Profiler: s0me-long-random-secret" \
  --data '{"query":"{ storeConfig { store_code } }"}'
```

The response gains `extensions.profiling` with an OTLP-shaped `resourceSpans`
tree. Feed it to any OTLP-aware viewer, or read it directly.

### Compact "AI" format

Add `X-GraphQl-Profiler-Format: ai` for a compact, LLM-friendly payload: the OTLP
envelope is dropped, keys are single letters, IDs are truncated to 6 hex chars,
and timestamps are microsecond offsets from trace start. Add
`X-GraphQl-Profiler-Sql: 1` to include the (truncated) raw SQL per query.

```bash
curl -s -X POST https://your-store.test/graphql \
  -H "Content-Type: application/json" \
  -H "X-GraphQl-Profiler: s0me-long-random-secret" \
  -H "X-GraphQl-Profiler-Format: ai" \
  -H "X-GraphQl-Profiler-Sql: 1" \
  --data '{"query":"{ storeConfig { store_code } }"}'
```

The payload carries **no legend** — the key mapping is a stable contract in
[`AI_FORMAT_MAPPING.md`](AI_FORMAT_MAPPING.md).

> **Tip:** for any query containing nested quotes, put the body in a file and use
> `curl --data @body.json`. Inline `-d` mangles the `\"` escapes and produces
> invalid JSON.

### Request headers

| Header | Values | Effect |
|--------|--------|--------|
| `X-GraphQl-Profiler` | the secret | **Required.** Activates profiling for this request. |
| `X-GraphQl-Profiler-Format` | `ai` | Compact format. Omit for verbose OTLP. |
| `X-GraphQl-Profiler-Sql` | `1` | Include raw SQL (`db.statement`) in each `db.query` span. |

---

## Using it with Claude

This module bundles a **Claude Code skill** (`.claude/skills/graphql-profiler/`)
that turns the profiler into an AI debugging tool. Ask in plain language:

> **why is this request slow:** `{ products(search: "bag", pageSize: 5) { items { sku price_range { minimum_price { final_price { value } } } } } }`

Claude will send the request through the profiler (asking you for the secret and
endpoint if it doesn't have them), replay it warm, then analyze the span tree and
report:

- **Verdict** — where the time actually goes (e.g. resolver vs DB, N+1).
- **Breakdown** — total wall time, slowest spans by self-time, DB/resolver split.
- **Root cause** — the specific resolver/query and *why* it's slow.
- **Next steps** — concrete, Magento-native fixes ranked by impact.

### Enabling the skill

The skill lives at `.claude/skills/graphql-profiler/` inside this module. Claude
Code discovers skills under a project's `.claude/skills/` directory, so either:

- **Symlink** it into your project skills dir:
  ```bash
  mkdir -p .claude/skills
  ln -s ../../app/code/Prlkhv/GraphQlAiProfiler/.claude/skills/graphql-profiler \
        .claude/skills/graphql-profiler
  ```
- **or copy** that folder to `<project-root>/.claude/skills/graphql-profiler`.

Then, in Claude Code, invoke it explicitly with `/graphql-profiler` or just ask a
"why is this slow" question and it triggers automatically.

The skill is two files: `SKILL.md` (the playbook) and `scripts/analyze.py` (a
zero-dependency Python 3 span analyzer). You can also run the analyzer standalone:

```bash
curl ... --data @body.json | python3 scripts/analyze.py
```

---

## How it works

Four plugins, all no-ops when the profiler is inactive:

| Plugin | Instruments | Emits |
|--------|-------------|-------|
| `ResolverPlugin` | `ResolverInterface::resolve` | a span per resolver call |
| `BatchResolverPlugin` | `BatchResolverInterface::resolve` | a span per batch resolve |
| `DbAdapterPlugin` | `Pdo\Mysql::query` / `multiQuery` | a `db.query` span with SQL hash |
| `ResponseInjectorPlugin` | `QueryProcessor::process` | serializes the tree into `extensions.profiling` |

Spans are held in a per-request `SpanCollector`; timing comes from a monotonic
`Clock` (`hrtime`). The `DbAdapterPlugin` guards against re-entrancy so config
lookups it triggers don't recurse.

### OTLP encoding notes

- `traceId` / `spanId` are emitted as **hex** (easy for a custom reader). Switch
  `Model\Otlp\Serializer` to base64 if you pipe to a real OTLP backend.
- Nanosecond timestamps are emitted as **strings** (they exceed JS safe-integer
  range). The AI format instead uses microsecond integer offsets.

---

## ⚠️ Security

**Do not enable this in production.** When active it exposes server internals and
SQL statements (`db.statement`, truncated) in the GraphQL response.

- Bind values are **never** logged — only the statement text.
- Activation requires **all** of: enabled flag + allowed deploy mode +
  constant-time secret match.
- The secret is stored encrypted and compared with `hash_equals()`. Treat it like
  a credential — a long, random value — and rotate it if it leaks.

These layers exist to make accidental exposure hard. Treat the secret like a
credential and keep `mode_allowlist` at `developer` unless you fully understand
the exposure.

---

## License

[Elastic License 2.0 (ELv2)](LICENSE). You may use this module freely, **including
inside commercial projects**. You may **not**:

- offer it to third parties as a hosted or managed service, or
- resell or sublicense it as a standalone product.

For a commercial license granting those rights, contact
Ivan Perelekhov &lt;iperelekhov@gmail.com&gt;.
