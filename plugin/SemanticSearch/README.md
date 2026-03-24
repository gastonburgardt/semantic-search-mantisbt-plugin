# SemanticSearch (MantisBT Plugin)

Autor: **Gaston Burgardt**

Plugin para MantisBT que implementa:
- Política semántica por jerarquía `Issue -> IssueNote -> IssueNoteFile`
- Vectorización en Qdrant (OpenAI embeddings)
- Búsqueda semántica y generación de solución sugerida

---

## Objetivo

Separar con claridad:

1. **Revisión de política** (no vectoriza):
   - sincroniza inventario Mantis vs tablas de control,
   - calcula `Action` y `NivelDeRevision`.

2. **Vectorización**:
   - ejecuta sólo pendientes (`CreateIndex / UpdateIndex / DeleteIndex`),
   - normaliza estado final (`Action=Nothing`, `NivelDeRevision=NoRevisarNada`).

---

## Arquitectura

- `SemanticSearch.php`: registro, hooks, panel de issue
- `core/v2/SemanticV2Engine.php`: motor principal (policy + vectorize)
- `core/JobControl.php`: control de runs globales (lock, heartbeat, stop, stale unlock)
- `pages/reindex.php`: UI admin
- `pages/reindex_action.php`: API AJAX para runs batch
- `pages/reindex_worker.php`: worker background
- `files/reindex.js`: frontend de gestión de runs

---

## Requisitos

- MantisBT 2.25+
- PHP 8+
- MariaDB/MySQL
- Qdrant accesible desde el contenedor/app (`SEMSEARCH_QDRANT_URL`)
- `OPENAI_API_KEY`

> Sin Qdrant, la revisión de política puede funcionar pero la vectorización fallará.

---

## Modelo y reglas

Tablas de control:
- `*_plugin_semsearch_issue`
- `*_plugin_semsearch_issuenote`
- `*_plugin_semsearch_issuenotefile`
- `*_plugin_semsearch_job_run`
- `*_plugin_semsearch_job_lock`

Acciones válidas:
- `Nothing | CreateIndex | UpdateIndex | DeleteIndex`

Niveles válidos:
- Issue/Note: `NoRevisarNada | SoloYo | YoYMisHijos | SoloMisHijos`
- File: `NoRevisarNada | SoloYo`

Regla base:
- `Indexable=false + Indexed=true => DeleteIndex`
- `Indexable=true + Indexed=false => CreateIndex`
- `Indexable=true + Indexed=true + hash cambió => UpdateIndex`
- `Empty=true => Nothing`

---

## Reindex admin (batch)

La pantalla admin (`plugin.php?page=SemanticSearch/reindex`) opera con runs en background:

- **Revisar política**
- **Iniciar vectorización**
- **Detener run**
- **Actualizar estado**

### Recuperación por lock stale

Si ya existe un run en lock y su heartbeat está vencido, el backend responde:
- `confirm_restart=true`
- `stalled_seconds`

El frontend muestra confirmación para:
1. forzar unlock del scope,
2. relanzar automáticamente el run.

Esto evita el bloqueo permanente por procesos caídos.

---

## Despliegue rápido

1. Aplicar schema SQL de plugin (`deploy/db/01-semsearch-schema.sql`).
2. Levantar Qdrant (`deploy/qdrant/docker-compose.yml`) o apuntar a uno existente.
3. Definir env vars al app:
   - `OPENAI_API_KEY`
   - `SEMSEARCH_QDRANT_URL`
4. Instalar/actualizar plugin en Mantis.

---

## Checklist de validación

1. Crear incidente nuevo y marcar indexable en panel del issue.
2. Ejecutar **Revisar política** en admin.
3. Verificar `Action` pendiente (`Create/Update/Delete`).
4. Ejecutar **Vectorización**.
5. Verificar estado final `Action=Nothing` y `Indexed` coherente.
6. Simular stale lock y validar prompt de relanzar.
