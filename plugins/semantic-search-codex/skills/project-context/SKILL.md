---
name: project-context
description: Use when working on this repository to quickly recover architecture, deployment paths, and known risk areas for the SemanticSearch MantisBT plugin.
---

# Project Context

Use this skill when the task is specific to this repository and you need fast orientation before making changes.

## What This Repo Contains

- MantisBT plugin source: `plugin/SemanticSearch/`
- Deployment helpers: `deploy/`, `scripts/`
- Plugin docs: `plugin/SemanticSearch/docs/`
- Top-level install/use docs: `README.md`, `docs/`

## Key Concepts

1. The plugin distinguishes between policy review and vectorization.
2. Qdrant stores vectors.
3. OpenAI generates embeddings.
4. Batch processing and locks are handled by the reindex pages and job tables.

## Read First

- `plugin/SemanticSearch/SemanticSearch.php`
- `plugin/SemanticSearch/core/v2/SemanticV2Engine.php`
- `plugin/SemanticSearch/core/v2/SemanticPolicyRepository.php`
- `plugin/SemanticSearch/core/JobControl.php`

## Known Weak Spots

- Manual issue vectorization path references a missing lock helper.
- Search behavior for "all projects" should be validated against per-project Qdrant collections.
- Some UI config flags are not wired into the actual indexing text builder.

## Supporting Notes

- See `./references/project-context.md` for concise repo memory.
