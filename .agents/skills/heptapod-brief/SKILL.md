---
name: heptapod-brief
description: Turn a Heptapod feature request into a build-ready brief that one implementer can build and one reviewer can check without further context, or park an idea that is not ready to build. Use when asked to write, draft or file a feature brief, enhancement, feature issue or idea.
---

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Brief

First decide: **has someone decided to build this?** Then write a brief; otherwise park the idea. Either way, extend an existing brief or issue instead of duplicating it.

## Build-ready brief

The brief is the spec. The implementer builds from it alone, the reviewer checks against its acceptance criteria, and anything outside it is out of scope.

1. **Read the code before asking:** the pages, actions, addons and `lang/` keys involved, today's behavior, the nearest pattern. Never ask what the code answers.
2. **Settle only consequential forks** with the requester: behavior, scope edges, wording, permissions, defaults. One question at a time, with your recommendation; name each interpretation instead of picking one silently. Push back when the idea leaves the product scope in `AGENTS.md`.
3. **Write the sections** Problem, Outcome, Acceptance criteria, Non-goals, Affected areas, Required evidence (and Open questions only for forks deliberately deferred):
   - the outcome is user-visible;
   - each criterion is observable and testable, and covers EN/DE copy and the `docs/` page (or why none);
   - non-goals name what a reasonable implementer might add but must not;
   - affected areas say "probably" when unsure;
   - evidence is the `AGENTS.md` proof class matching the risk, with the concrete scenario or command.
4. **Split** unrelated outcomes, or a list that one review cannot verify, into separate briefs.

Once work starts, a material new requirement is a scope change: agree on it and update the brief before building it.

## Park an idea

An idea keeps a problem from being forgotten; it is not a spec. Say who runs into what (one example), why it matters and what is unknown. Mark numbers as measured or assumed. Optional directions are short and explicitly undecided. No acceptance criteria, screens, API shapes or file paths: whoever picks it up chooses the solution and then writes a brief. Name the problem in the title, not the feature.

## Either way

Use the product terms (Down, Failed, Check error). Examples are synthetic: no host names, local paths, internal URLs, personal addresses or customer sites. Publishing outside the workspace, such as a public issue, needs the requester's explicit OK on the full draft.
