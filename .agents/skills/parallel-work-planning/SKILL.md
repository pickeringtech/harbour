---
name: parallel-work-planning
description: Plan ticket execution order by identifying dependency stages and marking work that can safely happen in parallel. Use when asked to sequence issues, tickets, backlog items, milestones, or agent work lanes.
---

# Parallel Work Planning

Use this skill to turn a backlog into a practical implementation path.

## Outcome

Produce a sequence of stages where:

- `|` separates tickets or tasks that can be worked in parallel.
- `->` separates stages that should happen after prior stages.
- The path is based on dependencies, integration risk, and product coherence, not issue number order.

Example format:

```text
#3 | #4 | #12 -> #2 -> #6 | #8 -> #5 -> #7 | #12 -> #11 -> #9 -> #10
```

## Method

1. Gather the ticket list and, when available, read enough issue detail to understand scope and dependencies.
2. Identify foundation tickets that create data models, shared abstractions, infrastructure, or product primitives other tickets need.
3. Identify integration tickets that join earlier foundation work into a user-facing milestone.
4. Mark tickets as parallel only when they do not require the same unfinished schema, application action, UI surface, or product decision.
5. Place QA, accessibility, and hardening tickets alongside feature work when they can run continuously; repeat them later if they need a final pass after integration.
6. Put exploratory work, AI, broad API design, and native-client work after enough structured product state exists, unless the user explicitly asks for an earlier spike.

## Parallel Safety Rules

Tickets can usually run in parallel when they:

- Touch separate domains or modules.
- Can define independent migrations without foreign keys to each other.
- Have clear contracts that can be integrated later.
- Produce reviewable PRs that do not depend on unmerged code from each other.

Tickets should usually be staged serially when one:

- Depends on another ticket's schema or domain model.
- Needs another ticket's UI flow to make sense.
- Would create rework if started before a product decision lands.
- Changes shared authentication, authorization, layout, routing, or deployment foundations.

## Output Styles

If the user asks for a simple path, return only the compact chain:

```text
#3 | #4 -> #2 -> #6 | #8 -> #5
```

If the user asks for explanation, include:

- The compact path first.
- A short stage-by-stage rationale.
- Explicit notes for anything that can start as discovery but should wait for implementation.

Keep the answer concise. Do not create new tickets unless the user asks.
