#!/usr/bin/env python3
"""Automated accessibility checks against a running Astrana Trusted Attestation instance.

Implementation-blind, the same way the conformance suite is. It drives the landing page and the
self-service page at /me, and checks what a person using assistive technology needs, from outside, against
whichever implementation is answering.

Two layers. axe-core covers the machine-detectable WCAG 2.1 A/AA rules (labels, roles, contrast, heading
order, ...). Beyond it, this runs the checks a keyboard pass surfaces that axe cannot: that every control
is reachable by Tab and traps focus nowhere, that each shows a visible focus ring, that interactive
targets are at least 24x24 CSS pixels, that the page reflows to a 320px width and to 200% text with no
horizontal scrollbar, and that submitting an invalid key announces the error in a live region.

It does NOT replace a manual screen-reader audit -- announcement order, meaning, and the understandable/
robust criteria still need a person. What it does is stop a regression in the mechanical parts from ever
having to be found twice.

    python shared/test/accessibility.py --base-url https://localhost:15443 --member alice --password password

The /me form is only exercised when the member holds a relationship, so grant one first (the conformance
suite's fixtures do, or grant by hand) -- the run says plainly when it saw no form to check. Requires
Playwright, as the conformance suite's browser login does, and network access to fetch axe-core.
"""

from __future__ import annotations

import argparse
import sys

from playwright.sync_api import sync_playwright

AXE = "https://cdn.jsdelivr.net/npm/axe-core@4.10.2/axe.min.js"
AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa"]

GREEN, RED, DIM, RESET = "\033[32m", "\033[31m", "\033[2m", "\033[0m"


class Report:
    def __init__(self) -> None:
        self.passed = 0
        self.failed = 0

    def section(self, title: str) -> None:
        print(f"\n{title}")

    def check(self, description: str, ok: bool, detail: str = "") -> None:
        if ok:
            self.passed += 1
            print(f"  {GREEN}PASS{RESET}  {description}")
        else:
            self.failed += 1
            print(f"  {RED}FAIL{RESET}  {description}" + (f"{DIM} -- {detail}{RESET}" if detail else ""))

    def note(self, text: str) -> None:
        print(f"  {DIM}note  {text}{RESET}")


def run_axe(page) -> list[dict]:
    page.add_script_tag(url=AXE)
    result = page.evaluate(
        "async (tags) => (await axe.run(document, {runOnly: tags})).violations."
        "map(v => ({id: v.id, impact: v.impact, help: v.help, count: v.nodes.length}))",
        AXE_TAGS,
    )
    return result


def login_to_me(ctx, base: str, member: str, password: str):
    page = ctx.new_page()
    page.goto(base + "/me", wait_until="load", timeout=30000)
    if page.locator("#username").count() > 0:
        page.fill("#username", member)
        page.fill("#password", password)
        page.click("#kc-login")
        page.wait_for_url(lambda u: base in u and "8081" not in u, timeout=30000)
        page.wait_for_load_state("load")
    return page


def audit_keyboard(page, report: Report) -> None:
    page.evaluate("() => (document.activeElement && document.activeElement.blur && document.activeElement.blur())")
    make_info = "() => { const el=document.activeElement; if(!el||el===document.body) return null; const cs=getComputedStyle(el); const r=el.getBoundingClientRect(); return {label:(el.getAttribute('aria-label')||el.value||el.textContent||'').trim().slice(0,30), focusRing: !(cs.outlineStyle==='none'||parseFloat(cs.outlineWidth)===0) || cs.boxShadow!=='none', w:Math.round(r.width), h:Math.round(r.height), display:cs.display}; }"
    seq = []
    first = None
    cycled = False
    for _ in range(40):
        page.keyboard.press("Tab")
        page.wait_for_timeout(60)
        info = page.evaluate(make_info)
        if info is None:
            continue
        sig = info["label"]
        if first is None:
            first = sig
        elif sig == first:
            cycled = True
            break
        seq.append(info)

    report.check("every control is reachable by keyboard and focus is not trapped",
                 cycled and len(seq) > 0, f"{len(seq)} controls, cycled={cycled}")
    no_ring = [s["label"] or s.get("label", "?") for s in seq if not s["focusRing"]]
    report.check("every focusable control shows a visible focus indicator (WCAG 2.4.7)",
                 not no_ring, "no ring on: " + ", ".join(no_ring))
    # WCAG 2.5.8 exempts inline targets ("the target is in a sentence, or its size is otherwise constrained
    # by the line-height of non-target text"). A display:inline element -- a text link within a run of prose,
    # like the footer's attribution link -- is exactly that case, so it is not held to the 24x24 minimum.
    # Buttons and block/inline-block controls stay in scope.
    small = [f"{s['label']}({s['w']}x{s['h']})" for s in seq
             if s["w"] > 0 and s.get("display") != "inline" and (s["w"] < 24 or s["h"] < 24)]
    report.check("interactive targets are at least 24x24px (WCAG 2.5.8)",
                 not small, "too small: " + ", ".join(small))
    return len(seq)


def audit_reflow(page, report: Report) -> None:
    # Reflow (1.4.10): content fits a 320px-wide viewport at the default text size, no horizontal scroll.
    page.set_viewport_size({"width": 320, "height": 800})
    page.wait_for_timeout(150)
    over320 = page.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
    report.check("reflows to a 320px width with no horizontal scroll (WCAG 1.4.10)", over320 <= 1, f"{over320}px overflow")

    # Resize text (1.4.4): text scaled to 200% at the ordinary viewport, no horizontal scroll. Measured at
    # a normal width -- not stacked on top of the 320px reflow case above, which would be far stricter than
    # the criterion asks.
    page.set_viewport_size({"width": 1280, "height": 900})
    page.wait_for_timeout(150)
    over200 = page.evaluate("""() => {
      document.documentElement.style.fontSize = '200%';
      const o = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      document.documentElement.style.fontSize = '';
      return o;
    }""")
    report.check("survives 200% text with no horizontal scroll (WCAG 1.4.4)", over200 <= 1, f"{over200}px overflow")


def audit_error_flow(page, report: Report) -> None:
    if page.locator("[data-key-input]").count() == 0:
        report.note("no key form on /me -- grant the member a relationship to check the error flow")
        return
    page.locator("[data-key-input]").first.fill("this-is-not-a-valid-public-key")
    page.locator("[data-action=save]").first.click()
    # Wait for the message to actually appear rather than guessing at a delay: the request round-trips
    # before the live region is filled, and a fixed wait either flakes or slows every run.
    try:
        page.wait_for_function(
            "() => { const s = document.querySelector('[data-key-input]').closest('section') || document;"
            " const out = s.querySelector('[data-message]'); return out && out.textContent.trim().length > 0; }",
            timeout=10000,
        )
    except Exception:
        pass
    state = page.evaluate("""() => {
      const s = document.querySelector('[data-key-input]').closest('section') || document;
      const out = s.querySelector('[data-message]');
      return {text: out ? out.textContent.trim() : '', live: out ? out.getAttribute('aria-live') : null};
    }""")
    report.check("an invalid key is refused with a message announced in a live region (WCAG 3.3.1)",
                 bool(state["text"]) and state["live"] in ("polite", "assertive"),
                 f"text={state['text']!r} aria-live={state['live']}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True, help="The running instance, e.g. https://localhost:15443")
    parser.add_argument("--member", default="alice", help="IAM username to sign in as for the /me page")
    parser.add_argument("--password", default="password", help="That member's password in the dev IdP")
    args = parser.parse_args()

    report = Report()
    with sync_playwright() as p:
        browser = p.chromium.launch()
        ctx = browser.new_context(ignore_https_errors=True)

        report.section("the public landing page")
        landing = ctx.new_page()
        landing.goto(args.base_url + "/", wait_until="load", timeout=30000)
        violations = run_axe(landing)
        report.check("no axe-core WCAG 2.1 A/AA violations", not violations,
                     "; ".join(f"{v['id']} ({v['count']})" for v in violations))
        landing.close()

        report.section("the self-service page")
        me = login_to_me(ctx, args.base_url, args.member, args.password)
        if "/me" not in me.url:
            report.check("reached /me after signing in", False, f"landed on {me.url}")
        else:
            violations = run_axe(me)
            report.check("no axe-core WCAG 2.1 A/AA violations", not violations,
                         "; ".join(f"{v['id']} ({v['count']})" for v in violations))
            audit_keyboard(me, report)
            audit_error_flow(me, report)
            audit_reflow(me, report)

        ctx.close()
        browser.close()

    print(f"\n{report.passed} passed, {report.failed} failed")
    return 1 if report.failed else 0


if __name__ == "__main__":
    sys.exit(main())
