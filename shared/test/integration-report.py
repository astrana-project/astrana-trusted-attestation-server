#!/usr/bin/env python3
"""Turn one or more conformance runs into a shareable pass/fail matrix.

Each run is a JSON file written by `check.py --json` (tagged with `--idp` and `--implementation`). This
renders them side by side: one row per check, one column per run, so a reader can see at a glance whether
every implementation behaves the same against every IdP -- which is the whole point of running the suite
across IdPs at all. A row whose results are not all the same (ignoring checks a run did not exercise) is
highlighted, because that is a divergence worth looking at.

    python shared/test/integration-report.py runs/*.json --out integration-report

Writes integration-report.html (styled, self-contained -- open or share as-is) and integration-report.md
(for a pull request or a wiki). Use --format html or --format md to write just one.

Columns are grouped by implementation, then IdP. Cells: check mark = pass, cross = fail, dash = the run did
not exercise that check (a skipped or IdP-specific one).
"""

import argparse
import html
import json
import pathlib
import sys

PASS, FAIL, NA = "pass", "fail", "na"
MARK = {PASS: "✓", FAIL: "✗", NA: "–"}  # check, cross, en-dash


def load_runs(paths):
    runs = []
    for p in paths:
        data = json.loads(pathlib.Path(p).read_text())
        impl = data.get("implementation") or ""
        idp = data.get("idp") or ""
        label = idp or data.get("base_url") or pathlib.Path(p).stem
        runs.append({
            "impl": impl, "idp": idp, "label": label, "meta": data,
            "status": {(c["section"], c["name"]): c["status"] for c in data.get("checks", [])},
        })
    # Stable column order: by implementation, then IdP.
    runs.sort(key=lambda r: (r["impl"].lower(), r["idp"].lower()))
    return runs


def ordered_rows(runs):
    """Every (section, name) across all runs, in first-seen order so sections stay grouped."""
    seen, rows = set(), []
    for run in runs:
        for check in run["meta"].get("checks", []):
            key = (check["section"], check["name"])
            if key not in seen:
                seen.add(key)
                rows.append(key)
    return rows


def cell_status(run, key):
    return run["status"].get(key, NA)


def is_divergent(runs, key):
    seen = {run["status"][key] for run in runs if key in run["status"]}
    return len(seen) > 1


# ---------------------------------------------------------------------------------------------------
# HTML
# ---------------------------------------------------------------------------------------------------

CSS = """
:root {
  --bg: #ffffff; --fg: #1b1f24; --muted: #6b7280; --line: #e3e6ea; --head: #f6f8fa;
  --pass: #1a7f37; --pass-bg: #e9f6ec; --fail: #cf222e; --fail-bg: #fbeaec; --na: #b0b6bd;
  --diverge: #fff4d6; --diverge-line: #e0b400; --card: #f6f8fa;
}
:root[data-theme="dark"], :root:not([data-theme="light"]) {
  @media (prefers-color-scheme: dark) {
    --bg:#0d1117; --fg:#e6edf3; --muted:#8b949e; --line:#30363d; --head:#161b22;
    --pass:#3fb950; --pass-bg:#12261a; --fail:#f85149; --fail-bg:#2b1416; --na:#484f58;
    --diverge:#2a2411; --diverge-line:#9e7700; --card:#161b22;
  }
}
:root[data-theme="dark"] {
  --bg:#0d1117; --fg:#e6edf3; --muted:#8b949e; --line:#30363d; --head:#161b22;
  --pass:#3fb950; --pass-bg:#12261a; --fail:#f85149; --fail-bg:#2b1416; --na:#484f58;
  --diverge:#2a2411; --diverge-line:#9e7700; --card:#161b22;
}
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--fg);
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
.wrap { max-width:1200px; margin:0 auto; padding:32px 24px 64px; }
h1 { font-size:22px; margin:0 0 4px; }
.sub { color:var(--muted); margin:0 0 24px; font-size:13px; }
.cards { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 24px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:12px 14px; min-width:150px; }
.card .who { font-weight:600; font-size:13px; }
.card .nums { margin-top:6px; font-size:13px; }
.card .p { color:var(--pass); font-weight:600; } .card .f { color:var(--fail); font-weight:600; }
.legend { display:flex; gap:18px; flex-wrap:wrap; color:var(--muted); font-size:13px; margin:0 0 16px; }
.legend b { font-weight:600; }
.scroll { overflow-x:auto; border:1px solid var(--line); border-radius:8px; }
table { border-collapse:separate; border-spacing:0; width:100%; font-size:13px; }
th, td { padding:7px 10px; border-bottom:1px solid var(--line); text-align:center; white-space:nowrap; }
thead th { position:sticky; top:0; background:var(--head); z-index:2; }
thead tr.impl th { border-bottom:1px solid var(--line); font-size:12px; color:var(--muted); font-weight:600; }
thead tr.idp th { top:34px; }
th.rowname, td.rowname { text-align:left; position:sticky; left:0; background:var(--bg); z-index:1;
  white-space:normal; min-width:340px; max-width:520px; }
thead th.rowname { background:var(--head); z-index:3; }
tr.section td { background:var(--head); font-weight:600; text-align:left; position:sticky; left:0; }
td.cell { font-size:15px; font-weight:700; }
td.pass { color:var(--pass); background:var(--pass-bg); }
td.fail { color:var(--fail); background:var(--fail-bg); }
td.na   { color:var(--na); }
tr.diverge td.rowname { box-shadow: inset 4px 0 0 var(--diverge-line); background:var(--diverge); }
tr.diverge td.na, tr.diverge td.pass, tr.diverge td.fail { background:var(--diverge); }
.count { display:block; font-size:11px; font-weight:400; color:var(--muted); margin-top:2px; }
.foot { color:var(--muted); font-size:12px; margin-top:24px; }
"""


def render_html(runs, rows, title):
    impls = []
    for run in runs:
        if not impls or impls[-1][0] != run["impl"]:
            impls.append((run["impl"], 1))
        else:
            impls[-1] = (run["impl"], impls[-1][1] + 1)

    out = ['<!doctype html><html lang="en"><head><meta charset="utf-8">',
           '<meta name="viewport" content="width=device-width, initial-scale=1">',
           f"<title>{html.escape(title)}</title><style>{CSS}</style></head><body><div class='wrap'>"]
    out.append(f"<h1>{html.escape(title)}</h1>")
    generated = runs[0]["meta"].get("generated_at", "") if runs else ""
    out.append(f"<p class='sub'>{len(runs)} run(s) &middot; {len(rows)} checks &middot; generated {html.escape(generated)}</p>")

    # Summary cards.
    out.append("<div class='cards'>")
    for run in runs:
        m = run["meta"]
        who = " &middot; ".join(x for x in [html.escape(run["impl"]), html.escape(run["idp"])] if x) or html.escape(run["label"])
        out.append(f"<div class='card'><div class='who'>{who}</div><div class='nums'>"
                   f"<span class='p'>{m.get('passed', 0)} passed</span>, "
                   f"<span class='f'>{m.get('failed', 0)} failed</span>"
                   f"<span class='count'>{html.escape(str(m.get('database','')))} &middot; "
                   f"{html.escape(m.get('sections_run',''))} sections</span></div></div>")
    out.append("</div>")

    out.append(f"<div class='legend'><span><b>{MARK[PASS]}</b> pass</span><span><b>{MARK[FAIL]}</b> fail</span>"
               f"<span><b>{MARK[NA]}</b> not exercised by that run</span>"
               "<span style='color:var(--diverge-line)'><b>&#9646;</b> results differ across columns</span></div>")

    out.append("<div class='scroll'><table><thead>")
    # Header row 1: implementation groups.
    out.append("<tr class='impl'><th class='rowname' rowspan='2'>Check</th>")
    for impl, span in impls:
        out.append(f"<th colspan='{span}'>{html.escape(impl) or '&mdash;'}</th>")
    out.append("</tr>")
    # Header row 2: IdP + per-column tally.
    out.append("<tr class='idp'>")
    for run in runs:
        m = run["meta"]
        out.append(f"<th>{html.escape(run['idp']) or html.escape(run['label'])}"
                   f"<span class='count'>{m.get('passed',0)}/{m.get('passed',0)+m.get('failed',0)}</span></th>")
    out.append("</tr></thead><tbody>")

    current_section = None
    for section, name in rows:
        if section != current_section:
            current_section = section
            out.append(f"<tr class='section'><td colspan='{len(runs)+1}'>{html.escape(section or '')}</td></tr>")
        diverge = is_divergent(runs, (section, name))
        out.append(f"<tr class='{'diverge' if diverge else ''}'><td class='rowname'>{html.escape(name)}</td>")
        for run in runs:
            st = cell_status(run, (section, name))
            out.append(f"<td class='cell {st}' title='{st}'>{MARK[st]}</td>")
        out.append("</tr>")
    out.append("</tbody></table></div>")

    base_urls = "; ".join(html.escape(f"{r['label']} = {r['meta'].get('base_url', '')}") for r in runs)
    out.append(f"<p class='foot'>Base URLs: {base_urls}</p></div></body></html>")
    return "".join(out)


# ---------------------------------------------------------------------------------------------------
# Markdown
# ---------------------------------------------------------------------------------------------------

def render_md(runs, rows, title):
    lines = [f"# {title}", ""]
    lines.append(f"{len(runs)} run(s), {len(rows)} checks. "
                 + " &nbsp;|&nbsp; ".join(f"**{(r['impl']+' / ' if r['impl'] else '')+r['idp'] or r['label']}**: "
                                          f"{r['meta'].get('passed',0)} passed, {r['meta'].get('failed',0)} failed"
                                          for r in runs))
    lines += ["", "Legend: ✓ pass · ✗ fail · – not exercised · **bold row** = results differ across columns.", ""]

    header = "| Check | " + " | ".join((f"{r['impl']} / " if r["impl"] else "") + (r["idp"] or r["label"]) for r in runs) + " |"
    sep = "|:--|" + "|".join([":--:"] * len(runs)) + "|"
    lines += [header, sep]

    current = None
    for section, name in rows:
        if section != current:
            current = section
            lines.append(f"| **{md_escape(section or '')}** |" + " |" * len(runs))
        diverge = is_divergent(runs, (section, name))
        label = f"**{md_escape(name)}**" if diverge else md_escape(name)
        cells = " | ".join(MARK[cell_status(run, (section, name))] for run in runs)
        lines.append(f"| {label} | {cells} |")
    return "\n".join(lines) + "\n"


def md_escape(text):
    return text.replace("|", "\\|")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("results", nargs="+", help="One or more check.py --json result files.")
    parser.add_argument("--out", default="integration-report", help="Output base name (default: integration-report).")
    parser.add_argument("--format", choices=["html", "md", "both"], default="both")
    parser.add_argument("--title", default="Astrana Trusted Attestation Integration Test Report")
    args = parser.parse_args()

    runs = load_runs(args.results)
    if not runs:
        parser.error("no runs loaded")
    rows = ordered_rows(runs)

    wrote = []
    if args.format in ("html", "both"):
        path = args.out + ".html"
        pathlib.Path(path).write_text(render_html(runs, rows, args.title), encoding="utf-8")
        wrote.append(path)
    if args.format in ("md", "both"):
        path = args.out + ".md"
        pathlib.Path(path).write_text(render_md(runs, rows, args.title), encoding="utf-8")
        wrote.append(path)

    diverged = sum(1 for key in rows if is_divergent(runs, key))
    print(f"{len(runs)} runs, {len(rows)} checks, {diverged} divergent row(s). Wrote: {', '.join(wrote)}")


if __name__ == "__main__":
    main()
