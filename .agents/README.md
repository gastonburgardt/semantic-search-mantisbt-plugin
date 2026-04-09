# .agents

This directory is the repo-local Codex support area for this project.

What is officially supported by Codex/OpenAI:
- `AGENTS.md` at the repo root for project instructions.
- `.codex/config.toml` for project-scoped MCP configuration.
- `.agents/plugins/marketplace.json` as the local plugin marketplace catalog.
- Local plugins referenced by that marketplace, for example `./plugins/<plugin-name>`.

What this repo uses as organization on top of that:
- `.agents/memory/` for evolving project notes.
- `.agents/docs/` if the team later wants Codex-specific design notes.

Current local plugin:
- `plugins/semantic-search-codex`

Current marketplace:
- `.agents/plugins/marketplace.json`
