# ADR 0006: Variable precedence is deterministic and provenance-aware

Status: accepted

Variable sources are evaluated from persisted non-secret state, preserved
`.env`, process environment, Harbour defaults, identity, ports, resources,
project configuration, then resolver classes. Only names referenced by the
environment template are imported from `.env` and the process environment.
The last definition wins and retains its source and secret metadata. This lets
projects override defaults without making resolution order implicit while
keeping unrelated credentials out of Harbour's output surface.

Explicit secret metadata controls persistence and display. Conservative
name-based redaction remains a second defence. Debug output shows provenance,
not secret values. Template rendering fails on unresolved placeholders.
