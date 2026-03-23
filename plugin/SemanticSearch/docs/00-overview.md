# SemanticSearch — Overview

Autor: **Gaston Burgardt**

## Propósito
Plugin de MantisBT para control de política de indexación semántica e indexación vectorial sobre Qdrant.

## Componentes del proyecto
1. **Plugin MantisBT** (`SemanticSearch/`)
2. **Script SQL de base** (`deploy/db/01-semsearch-schema.sql`)
3. **Infra Qdrant** (`deploy/qdrant/docker-compose.yml`)
4. **Variables de entorno** (`deploy/.env`)

## Alcance de esta documentación
- Instalación
- Configuración
- Operación diaria
- Validación funcional
- Troubleshooting
- Rollback
