---
name: heptapod-frontend-verify
description: Use the agent-browser CLI to exercise web interfaces, inspect rendered accessibility state, verify interactions, and capture screenshots. Use for browser-based UI testing and evidence collection. Do not use as a substitute for deterministic Playwright coverage when an automated regression test is the requested deliverable.
---

<!-- Adapted from emdash-cms/emdash skills/agent-browser/SKILL.md (MIT). -->

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Verify the Heptapod frontend

Rendered proof shows the changed action working, replayably. `AGENTS.md` "Delivery" sets which proof the risk needs. Load the CLI guide first: `agent-browser skills get core --full`.

1. **Right state:** the dev scenario that shows the change (`scripts/dev-up.sh <scenario>`), with the URL and login it prints. Seed missing states only through real entry points and record them.
2. **Own session:** your own `AGENT_BROWSER_SESSION`; close only that one.
3. **The action itself**, not just a page load, at the locales and viewports the risk calls for. Pick edge states that could break it (empty, error, long text) and name those that do not apply.
4. **Inspect:** open every screenshot and confirm it shows the promised behavior. Check the console and relevant requests for errors.
5. **Report for replay:** scenario, URL, locale, viewport, steps, screenshot paths (outside the tracked tree), console output. Smoke and stack handback follow `AGENTS.md`.

A stack or browser that will not start is a blocker; never substitute weaker proof. Manual proof never replaces a requested regression test (`test-audit`).
