# semantic-search-mantisbt-plugin

Implementación del plugin **SemanticSearch** para MantisBT.

## Estructura
- `plugin/SemanticSearch/` → código del plugin
- `deploy/db/` → script SQL de tablas del plugin (template por prefijo)
- `deploy/qdrant/` → docker-compose para Qdrant
- `deploy/.env.example` → variables de configuración
- `scripts/get-mantis.sh` → descarga opcional de MantisBT (no embebido)
- `scripts/install-semsearch.sh` → instalación rápida (plugin + SQL + registro + qdrant)

## Instalación rápida en una instalación nueva

```bash
./scripts/install-semsearch.sh \
  --mantis-app <contenedor_app_mantis> \
  --mantis-db <contenedor_db_mantis> \
  --db-name mantisbt \
  --db-user mantisbt \
  --db-pass mantisbt \
  --plugin-prefix mantis_plugin_ \
  --plugin-dir /var/www/html/plugins \
  --qdrant-dir ./deploy/qdrant
```

> `--plugin-prefix` soporta diferencias entre instalaciones (`mantis_plugin_` o `mantisplugin_`).

## Guías
- `docs/06-how-to-install-with-mantis.md` → instalación desde cero con Mantis + plugin + qdrant.

## Cambios recientes (UI + seguridad)
- Panel de issue con jerarquía visual Core → Notas → Archivos.
- Etiquetas de estado en español en UI (`Indexado`, `Sí/No`, `CrearIndice/ActualizarIndice/EliminarIndice/SinAccion`).
- En Core se muestra `Issue` con `#ID` en la grilla.
- Protección CSRF agregada en:
  - acciones del panel de issue (`attachment_index_action`)
  - endpoints AJAX de reindex (`reindex_action`).

## Nota
MantisBT no se incluye en este repositorio. Se instala por separado o se descarga con el script opcional.
