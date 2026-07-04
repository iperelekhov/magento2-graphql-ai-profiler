#!/usr/bin/env python3
"""Analyze a Prlkhv_GraphQlAiProfiler AI-format response and surface latency signals.

Reads the full GraphQL JSON response (with extensions.profiling in the compact
"ai" format) from stdin or a file argument, and prints a structured breakdown:
total wall time, slowest spans by self-time, resolver/DB split, and SQL
aggregated by statement hash for N+1 / duplicate-query detection.

The script only surfaces signals. The diagnosis and advice are written by the
agent from this output. Format contract: see the "Payload format (ai)" section in SKILL.md.
"""
import json
import sys
from collections import defaultdict


def load():
    raw = sys.stdin.read() if len(sys.argv) < 2 else open(sys.argv[1]).read()
    raw = raw.strip()
    # Tolerate leading curl noise: grab from the first '{'.
    brace = raw.find("{")
    if brace > 0:
        raw = raw[brace:]
    try:
        doc = json.loads(raw)
    except json.JSONDecodeError as e:
        sys.exit(f"Could not parse response as JSON ({e}).\n"
                 "Usually a shell-escaping problem in the request: put the GraphQL "
                 "body in a file and pass it with curl --data @file.json rather than "
                 "inline, so the \\\" escapes survive.")
    prof = doc.get("extensions", {}).get("profiling")
    if not prof:
        sys.exit("No extensions.profiling in response — profiler did not activate. "
                 "Check the secret, dev mode, and that X-GraphQl-Profiler-Format: ai was sent.")
    if "errors" in doc:
        print("!! GraphQL response contains errors:", file=sys.stderr)
        print(json.dumps(doc["errors"], indent=2)[:1500], file=sys.stderr)
    return prof


def us(v):
    return f"{v/1000:.2f}ms"


def main():
    prof = load()
    spans = prof.get("sp", [])
    if not spans:
        sys.exit("Profiling payload has zero spans.")

    by_id = {s["i"]: s for s in spans}
    children = defaultdict(list)
    for s in spans:
        children[s.get("p", "")].append(s)

    # Total wall time: from earliest start (0) to the max end (s + d).
    total = max(s["s"] + s["d"] for s in spans)

    # Self-time = own duration minus children durations (children may overlap;
    # clamp at 0 so overlap never yields negative self-time).
    def self_time(s):
        kids = sum(c["d"] for c in children.get(s["i"], []))
        return max(s["d"] - kids, 0)

    db_spans = [s for s in spans if s.get("n") == "db.query"]
    resolver_spans = [s for s in spans if s.get("n") != "db.query"]

    db_total = sum(s["d"] for s in db_spans)

    print("=" * 68)
    print(f"TOTAL WALL TIME     {us(total)}")
    print(f"SPANS               {len(spans)}  ({len(resolver_spans)} resolver, {len(db_spans)} db.query)")
    print(f"DB TIME (sum d)     {us(db_total)}  (~{db_total/total*100:.0f}% of wall; siblings may overlap)")
    print("=" * 68)

    print("\nSLOWEST BY SELF-TIME (where time is actually spent)")
    ranked = sorted(spans, key=self_time, reverse=True)[:8]
    for s in ranked:
        a = s.get("a", {})
        label = s.get("n", "?")
        if s.get("n") != "db.query":
            label = a.get("gp", "?") + "." + a.get("gf", label) if a.get("gf") else label
        print(f"  {us(self_time(s)):>10}  self  | {us(s['d']):>10} total  | {label}")

    # SQL aggregation by hash -> duplicate / N+1 detection.
    by_hash = defaultdict(lambda: {"count": 0, "total": 0, "sql": None})
    for s in db_spans:
        a = s.get("a", {})
        h = a.get("dh", "?")
        by_hash[h]["count"] += 1
        by_hash[h]["total"] += s["d"]
        if by_hash[h]["sql"] is None:
            by_hash[h]["sql"] = a.get("dq")

    print("\nSQL BY STATEMENT (grouped by hash — repeats = duplicate / N+1)")
    for h, info in sorted(by_hash.items(), key=lambda kv: kv[1]["total"], reverse=True)[:12]:
        flag = "  <-- N+1?" if info["count"] >= 3 else ""
        sql = (info["sql"] or "(SQL not captured; send X-GraphQl-Profiler-Sql: 1)")
        sql = " ".join(sql.split())[:110]
        print(f"  x{info['count']:<3} {us(info['total']):>10} total  {flag}")
        print(f"        {sql}")

    dupes = {h: i for h, i in by_hash.items() if i["count"] >= 3}
    if dupes:
        n = sum(i["count"] for i in dupes.values())
        t = sum(i["total"] for i in dupes.values())
        print(f"\n!! {n} queries across {len(dupes)} repeated statement(s) = {us(t)} "
              f"(~{t/total*100:.0f}% of wall). Strong N+1 signal.")

    # DB cost attributed to the nearest resolver ancestor.
    def resolver_ancestor(s):
        cur = s
        seen = set()
        while cur is not None and cur["i"] not in seen:
            seen.add(cur["i"])
            if cur.get("n") != "db.query":
                return cur
            cur = by_id.get(cur.get("p", ""))
        return None

    per_resolver = defaultdict(lambda: {"count": 0, "total": 0})
    for s in db_spans:
        r = resolver_ancestor(s)
        key = "(root)"
        if r is not None:
            a = r.get("a", {})
            key = (a.get("gp", "") + "." + a.get("gf", "")) if a.get("gf") else r.get("n", "?")
        per_resolver[key]["count"] += 1
        per_resolver[key]["total"] += s["d"]

    print("\nDB COST BY OWNING RESOLVER")
    for key, info in sorted(per_resolver.items(), key=lambda kv: kv[1]["total"], reverse=True)[:8]:
        print(f"  {info['count']:>3} queries  {us(info['total']):>10}  | {key}")

    errs = [s for s in spans if s.get("x") == 2]
    if errs:
        print("\n!! ERROR SPANS")
        for s in errs:
            print(f"  {s.get('n')}  {s.get('a', {}).get('em', '(no message)')}")

    print()


if __name__ == "__main__":
    main()
