# SemanticSearch (MantisBT Plugin)

Autor: **Gaston Burgardt**

Plugin de MantisBT para:
- gestionar política de indexación semántica por jerarquía (`Issue -> Note -> File`),
- ejecutar indexación vectorial en Qdrant,
- buscar incidencias por semántica (OpenAI embeddings + Qdrant).

---

## 1) Objetivo funcional

El plugin separa claramente dos procesos:

1. **Revisar política**
   - Recorre Mantis + tablas de control del plugin.
   - Decide `Action` y `NivelDeRevision` por nodo.
   - No indexa; deja estado preparado.

2. **Indexar**
   - Toma solo lo pendiente en tablas de control.
   - Ejecuta `CreateIndex / UpdateIndex / DeleteIndex`.
   - Actualiza estado final (`Indexed`, `IndexedAt`, `Action=Nothing`, etc.).

---

## 2) Stack de software

- **PHP 8.x** (plugin MantisBT)
- **MantisBT 2.25+**
- **MariaDB/MySQL** (tablas de control del plugin)
- **OpenAI Embeddings API**
- **Qdrant** (almacenamiento vectorial)
- **JS vanilla** para UI de reindex por lotes

---

## 3) Estructura de carpetas

> Proyecto resuelto en 3 piezas operativas:
> 1. plugin MantisBT,
> 2. script de BD Mantis,
> 3. stack Qdrant + `.env`.

```text
SemanticSearch/
├── SemanticSearch.php                 # clase principal del plugin (registro, hooks, panel issue)
├── README.md                          # documentación principal
├── core/
│   ├── IssueIndexer.php               # entrypoint del motor v2
│   ├── OpenAIEmbeddingClient.php      # cliente OpenAI embeddings
│   ├── QdrantClient.php               # cliente Qdrant
│   ├── SemanticSearchService.php      # búsqueda semántica
│   ├── V2Schema.php                   # ensure/migración de schema plugin
│   └── v2/
│       ├── SemanticDomain.php         # constantes de dominio (acciones/niveles/tipos)
│       ├── SemanticIssueInventoryRepository.php  # lectura de Mantis (issue/note/file)
│       ├── SemanticPolicyRepository.php          # persistencia tablas plugin
│       └── SemanticV2Engine.php                 # núcleo de política + indexación + batch
├── pages/
│   ├── config_page.php                # UI configuración
│   ├── config.php                     # guardado configuración
│   ├── search.php                     # UI búsqueda semántica
│   ├── reindex.php                    # UI proceso por lotes
│   ├── reindex_action.php             # endpoint AJAX de revisión/indexación
│   └── attachment_index_action.php    # guardar política / indexar desde issue
├── files/
│   └── reindex.js                     # frontend del proceso batch
├── lang/
│   ├── strings_spanish.txt            # textos ES
│   └── strings_english.txt            # textos EN
├── deploy/
│   ├── .env                             # variables de entorno de despliegue
│   ├── db/
│   │   └── 01-semsearch-schema.sql      # script SQL de tablas plugin en Mantis
│   └── qdrant/
│       └── docker-compose.yml           # stack de Qdrant
└── docs/
    └── (documentación operativa adicional)
```

---

## 4) Modelo de datos (tablas de control)

Tablas activas:
- `mantisplugin_semsearch_issue`
- `mantisplugin_semsearch_issuenote`
- `mantisplugin_semsearch_issuenotefile`

Campos clave por tabla (según nivel):
- IDs de nodo (IssueId / NoteId / FileId)
- `CreatedAt`, `UpdatedAt`, `IndexedAt`
- `Indexable`, `Empty`, `Indexed`
- `Hash`
- `Action` (`Nothing|CreateIndex|UpdateIndex|DeleteIndex`)
- `NivelDeRevision` (`NoRevisarNada|SoloYo|YoYMisHijos|SoloMisHijos`)

---

## 5) Flujo de ejecución

### A) Hooks automáticos
- update de issue
- alta/edición/baja de nota

Estos hooks disparan **revisión de política** para mantener tablas en estado consistente.

### B) Desde el issue (panel "Indexación semántica")
- Guardar política
- Guardar e indexar ahora

### C) Reindex general
Pantalla batch con filtros:
- proyecto
- issue puntual opcional
- fecha de creación (desde/hasta)
- lote y tope

---

## 6) Criterios de decisión (resumen)

- Si `Indexable=false` y `Indexed=true` => `DeleteIndex`
- Si `Indexable=false` y `Indexed=false` => `Nothing`
- Si `Empty=true` => `Nothing`
- Si `Indexable=true` y `Indexed=false` => `CreateIndex`
- Si `Indexable=true` y `Indexed=true`:
  - hash igual => `Nothing`
  - hash distinto / actualización fuente posterior => `UpdateIndex`

Además, se respeta jerarquía (padre -> hijos) para casos de bloqueo/cascada.

---

## 7) Configuración importante

En `config_page.php`:
- URL y colección de Qdrant
- modelo de embeddings
- `top_k`, `min_score`
- incluir notas / adjuntos
- estados de Mantis considerados indexables
- comportamiento al pasar a no indexable

---

## 8) Despliegue rápido (DB + Qdrant + env)

### A) Variables
Editar:
- `deploy/.env`

### B) Qdrant
Desde `deploy/qdrant/`:

```bash
cp ../.env .env
docker compose up -d
```

### C) Script BD Mantis
Aplicar `deploy/db/01-semsearch-schema.sql` sobre la BD `mantisbt`.

Ejemplo:

```bash
mariadb -u mantisbt -p mantisbt < deploy/db/01-semsearch-schema.sql
```

## 10) Publicación y autoría en GitHub

Si vas a subir este plugin a GitHub con autoría tuya:

```bash
git config user.name "Gaston Burgardt"
git config user.email "tu-email@dominio.com"
```

Commit sugerido:

```bash
git add plugins/SemanticSearch
git commit -m "docs: agregar README de arquitectura y estructura de SemanticSearch"
```

Si querés autor explícito en un commit puntual:

```bash
git commit --author="Gaston Burgardt <tu-email@dominio.com>" -m "..."
```

Luego push al remoto de GitHub que definas.
uctura de SemanticSearch"
```

Si querés autor explícito en un commit puntual:

```bash
git commit --author="Gaston Burgardt <tu-email@dominio.com>" -m "..."
```

Luego push al remoto de GitHub que definas.
