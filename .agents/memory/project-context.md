# Project Context

This file is an evolving repo memory for Codex-oriented work.

## Current Project Shape

- Main deliverable: `plugin/SemanticSearch/`
- Deployment SQL: `deploy/db/01-semsearch-schema.sql`
- Qdrant compose: `deploy/qdrant/docker-compose.yml`
- Install helper: `scripts/install-semsearch.sh`

## Deployment Decision

- Active deployment target for this repository is the Mantis instance on server `192.168.0.145`.
- The abandoned `mymantiszero` path on `192.168.0.177` is no longer the deployment target for this repo.
- Expected flow for this project:
  1. changes are made in this repository,
  2. changes are pushed to GitHub,
  3. the code is pulled or downloaded from GitHub on `192.168.0.145`,
  4. the Mantis deployment on `145` is refreshed there.

## Architectural Notes

- The plugin separates policy review from vectorization.
- Vector storage uses Qdrant.
- Embeddings use OpenAI.
- Batch processing is handled by the reindex pages and job tables.

## Known Risks Seen During Initial Analysis

- Manual issue vectorization path references a missing `is_locked_for_project()` method.
- "All projects" semantic search appears inconsistent with per-project Qdrant collections.
- Forced revectorization does not fully reconcile plugin state tables.
- Some config flags exposed in UI are not wired into the indexing text builder.

## Remote Infrastructure Notes

- `192.168.0.130`
  - Role: reverse proxy.
  - Relevant config root: `/home/gburgardt/Apps/nginx-proxy/nginx/conf.d/`
  - `mymantisbtq.gburgardt.com` proxies to the `145` environment.

- `192.168.0.145`
  - Role: active Mantis host for this repository.
  - Relevant tree: `/home/gburgardt/Apps/myapps/mymantisbtq/`
  - Main app path: `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev`
  - Plugin path: `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev/plugins/SemanticSearch`
  - Compose file: `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev/docker-compose.yml`
  - Main containers:
    - `mymantisbtq-app`
    - `mymantisbtq-db`
    - `mymantisbtq-qdrant`
  - There is no meaningful OpenClaw workspace on this host for this project.

- `192.168.0.177`
  - Role: previously associated with `mymantiszero`, now not used for this repo's deployment flow.
  - Keep only as historical context if future work explicitly references it.

## Usage

- Keep this file short and factual.
- Prefer updating this after major architecture or operational changes.
