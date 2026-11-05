---
name: heptapod-frontend-verify
description: Use the agent-browser CLI to exercise web interfaces, inspect rendered accessibility state, verify interactions, and capture screenshots. Use for browser-based UI testing and evidence collection. Do not use as a substitute for deterministic Playwright coverage when an automated regression test is the requested deliverable.
---

<!-- Adapted from emdash-cms/emdash skills/agent-browser/SKILL.md (MIT). -->

# Verify the Heptapod frontend

Use the installed `agent-browser` CLI for rendered, user-facing verification. Load its version-matched instructions before relying on command syntax:

```bash
agent-browser skills get core --full
```

Use a specialized bundled guide when the target requires one. `agent-browser skills list` shows the available guides.

## Verification loop

1. Start or identify the target application and record the exact URL and viewport.
2. Open the page in a named session.
3. Take an accessibility snapshot and identify controls by role, label, or snapshot reference.
4. Perform the user action.
5. Wait for the resulting state, then take a fresh snapshot. References can become stale after navigation or a substantial DOM update.
6. Verify the observable result, console errors, and relevant network behavior.
7. Capture screenshots when visual evidence helps establish the result.

## Runtime and verification matrix

- Run project commands via `docker compose exec app …`. Use the running Compose stack; never use lerd.
- Use your own `AGENT_BROWSER_SESSION=<wp>` for every browser command. Never `close --all`, never port 9222; close only your own session.
- Open `http://localhost:8088/` from local Chrome (use the checkout's `APP_PORT` if different). From the Compose browser on CDP port 9334, use `http://heptapod-app/`, never `http://app/` because `.app` is HSTS-preloaded.
- Authenticate at `/login` with `test@example.test` / `password` (the printed `dev-up` login line). Seed minimal demo data through the project's real entry points when the state is unreachable; record what must be removed.
- Click through every touched screen at 1440, 1024, and 390 px, in dark and light, in loading, empty, populated, error, and down states where relevant. Name states that do not apply.
- Check console errors and failed requests for every state. Only Vite debug and React DevTools info are allowed; other warnings/errors fail verification.
- A server or browser that cannot start is a blocker. Report it; do not skip verification or claim success.

## Interaction guidance

- Prefer role and label locators when they express the user-facing control clearly. Use snapshot references for efficient exploration.
- Re-snapshot after a dialog opens, a route changes, or content is replaced.
- Wait for a selector or visible state instead of adding a fixed delay unless timing itself is under test.
- Use a named session for flows that depend on cookies or storage. Close sessions that are no longer needed.
- Check `agent-browser console` and `agent-browser errors` when the UI behaves unexpectedly.
- Use `agent-browser network requests` when the result depends on a request or response boundary.

## Evidence

Record the path tested, inputs, viewport, expected result, actual result, and any console or request failures. For UI changes, capture the rendered result and before-and-after screenshots when the difference is otherwise ambiguous.

Save screenshots outside the tracked tree in a review-specific artifact directory with descriptive filenames. Report full screenshot paths, what you clicked, and console/network error output (must be empty). Inspect the saved image before reporting success; a completed screenshot command does not prove that the intended state was visible.

PHP Gate and Vitest are enabled and blocking on every pull request. The Browser suite is not in CI for v0.1. Follow CONTRIBUTING.md for automated regression coverage and test scope; manual UI proof complements those checks.

## Definition of done

1. `scripts/dev-up.sh <scenario>` reaches ready. Non-stopped scenarios report live scheduler and dispatcher heartbeats and checks scheduled from a stated time (Horizon off); `stopped` reports stale heartbeats with services off.
2. Own agent-browser session. Perform the named action on the page under review.
3. Screenshots at 1440x1000 and 390x844, each in EN and DE, saved outside the tracked tree in the review artifact directory. Inspect every image.
4. Console and page errors are empty after the action.
5. `scripts/smoke.sh` passes on the same stack.
6. Restore the demo owner to EN and leave the stack on `attention`.
7. Report commands, exit codes, screenshot paths and the reviewer replay entry point.

The reviewer reads the diff, opens every claimed evidence artifact (including each screenshot and command output), and opens the same scenario URL or reruns the effect command. Handoff text alone is not proof. The reviewer then confirms the claimed result or reports a blocker.

For a confirmed defect, add the narrowest meaningful regression test within the enabled test scope. Read `.agents/skills/test-audit` before changing tests; ask maintainers before adding coverage outside that scope.
