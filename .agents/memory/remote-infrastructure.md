# Remote Infrastructure Memory

This file captures the currently relevant remote topology for the SemanticSearch project.

## Active Route

- Development source: this repository
- Source distribution: GitHub
- Active deployment host: `192.168.0.145`
- Public entrypoint is proxied through `192.168.0.130`

## Server 130

- Host role: reverse proxy
- Main app folder: `/home/gburgardt/Apps/nginx-proxy/`
- Relevant Nginx vhost:
  - `/home/gburgardt/Apps/nginx-proxy/nginx/conf.d/mymantisbtq.gburgardt.com.conf`
- Current behavior:
  - `mymantisbtq.gburgardt.com` proxies to `produccion.gb.lan:18110`

## Server 145

- Host role: active Mantis deployment for this repo
- Main folder:
  - `/home/gburgardt/Apps/myapps/mymantisbtq/`
- App root:
  - `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev`
- Plugin root:
  - `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev/plugins/SemanticSearch`
- Install/reference copy:
  - `/home/gburgardt/Apps/myapps/mymantisbtq/instalador/SemanticSearch`
- Compose file:
  - `/home/gburgardt/Apps/myapps/mymantisbtq/mantisbt-dev/docker-compose.yml`
- Relevant containers:
  - `mymantisbtq-app`
  - `mymantisbtq-db`
  - `mymantisbtq-qdrant`

## Deployment Expectation

For this repository, assume this workflow unless the user says otherwise:

1. Commit and push from local repo to GitHub.
2. On `192.168.0.145`, update the deployed code from GitHub.
3. Refresh the Mantis deployment on `145`.

## Important Constraint

- Do not assume `177` is a valid deployment target for this repo anymore.
- Ask the user which target to use only when they explicitly redirect deployment away from `145`.
