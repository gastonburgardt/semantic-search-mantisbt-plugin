# AGENTS.md

## Repository Scope

- This repository contains a MantisBT plugin under `plugin/SemanticSearch/`.
- Deployment helpers live in `deploy/` and `scripts/`.
- Prefer keeping changes compatible with MantisBT 2.25+ and PHP 8+.

## Working Rules

- Treat `plugin/SemanticSearch/` as the source of truth for the plugin code shipped into MantisBT.
- Prefer small, reviewable edits over broad rewrites.
- When changing runtime behavior, update the relevant docs under `plugin/SemanticSearch/docs/` or `docs/`.
- Preserve the separation between policy review and vectorization unless the task explicitly asks to redesign it.

## Validation

- If PHP is available, run syntax checks on touched PHP files.
- If external services are required, call out what could not be verified locally.

## Project Context

- Repo-specific Codex support files live under `.agents/`.
- Project-scoped Codex MCP configuration lives under `.codex/config.toml`.
- Optional evolving project notes live under `.agents/memory/project-context.md`.
