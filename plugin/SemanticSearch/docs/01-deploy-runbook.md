# SemanticSearch — Deploy Runbook (145)

> Este runbook NO modifica código del plugin. Solo instalación/configuración/validación.

## 1) Prerrequisitos

- Host: `145`
- Docker y Docker Compose operativos
- MantisBT levantado
- Acceso a base `mantisbt`
- Credenciales OpenAI válidas

## 2) Variables de entorno

Editar:

`plugins/SemanticSearch/deploy/.env`

Completar al menos:
- `OPENAI_API_KEY`
- `QDRANT_URL`
- `QDRANT_COLLECTION`

## 3) Levantar Qdrant

```bash
cd <ruta-del-proyecto>/plugins/SemanticSearch/deploy/qdrant
cp ../.env .env
docker compose up -d
```

Verificar:

```bash
docker ps | grep qdrant
curl -fsS http://127.0.0.1:${QDRANT_HOST_PORT:-6333}/healthz
```

## 4) Inicializar/ajustar esquema de BD

```bash
cd <ruta-del-proyecto>/plugins/SemanticSearch
mariadb -u mantisbt -p mantisbt < deploy/db/01-semsearch-schema.sql
```

## 5) Reiniciar Mantis

```bash
docker restart mymantisbtq-app
```

## 6) Configuración en Mantis (UI)

- Administrar -> Complementos -> SemanticSearch
- Revisar:
  - Qdrant URL
  - Collection
  - Embedding model
  - Top K
  - Min score
  - Include notes/attachments
  - Index statuses

## 7) Validación funcional mínima

1. Abrir un issue
2. Ver panel **Indexación semántica**
3. Ejecutar **Guardar política**
4. Verificar acciones/niveles coherentes
5. Ejecutar **Guardar e indexar ahora**
6. Verificar que `Action` vuelva a `Nothing` en nodos procesados

## 8) Validación de búsqueda

1. Ir a menú **Semantic Search**
2. Buscar por texto libre
3. Validar score y enlace a issue

## 9) Criterio de salida (Go-Live)

- Qdrant healthy
- Panel de issue visible
- Revisión de política funcional
- Indexación funcional
- Búsqueda funcional

