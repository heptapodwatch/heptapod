---
name: heptapod-idea-issue
description: Record a product idea for Heptapod as a GitHub issue that describes the problem, not the solution. Use when asked to file, record or park an idea for later. For work that is ready to build, use heptapod-feature-issue.
---

# Idea issue

An idea issue keeps a problem from being forgotten. It is not a brief. Whoever picks it up later decides the solution, and may find a better one than the one that prompted the idea. When the idea is picked up, `heptapod-feature-issue` turns it into a buildable issue.

## Rules

- Describe the problem and who has it. A reader who has never seen the discussion must understand why it hurts.
- Do not specify the solution: no acceptance criteria, API shapes, field names, screens or file paths.
- Directions are optional, short and marked as undecided. Give more than one when you know more than one.
- State what is known and what is assumed. An unmeasured claim says so.
- Check for an existing issue first: `gh issue list -R heptapodwatch/heptapod --search "<keywords>" --state all`. Add to it instead of opening a second one.
- The product scope in `AGENTS.md` applies: monitoring, reporting and opt-in notifications. Say so in the issue when an idea touches the edge of that scope.
- The repository is public. Synthetic examples only: no host names, local paths, internal URLs, personal addresses or customer sites.
- Follow `0-outbound-comms` if it is installed: short, direct, no ceremony.

## Shape

The title names the problem or the missing outcome, not the feature: "One server outage sends one alert per site", not "Add shared-cause incident grouping".

```markdown
## Problem
Who runs into what, with one synthetic example. One short paragraph.

## Why it matters
What it costs the user and how often it happens. Mark numbers as measured or assumed.

## Possible directions
- One line each. Not decided. Leave out the section when none is known.

## Unknowns
- What to measure or learn before this is worth building, and what would make it not worth building.
```

Leave out a section that would be empty. An idea issue fits on one screen.

## File it

Write the body to a file, then:

```bash
gh issue create -R heptapodwatch/heptapod --title "<the problem>" --label idea --body-file <file>
```

Return the issue URL.
