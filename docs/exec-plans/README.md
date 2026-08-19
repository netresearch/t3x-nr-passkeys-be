# Execution plans

Working directory for multi-step agent execution plans.

- `active/` -- plans currently being executed (create on demand)
- `completed/` -- finished plans kept for reference (create on demand)

Keep plans small and dated (`YYYY-MM-DD-topic.md`). A plan is a scratch artifact, not documentation: durable outcomes belong in `docs/adr/`, `docs/ARCHITECTURE.md`, or the AGENTS.md files.
