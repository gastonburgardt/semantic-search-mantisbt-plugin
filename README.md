# semantic-search-mantisbt-plugin

Implementación del plugin **SemanticSearch** para MantisBT.

## Estructura
- `plugin/SemanticSearch/` → código del plugin
- `deploy/db/` → script SQL de tablas del plugin
- `deploy/qdrant/` → docker-compose para Qdrant
- `deploy/.env.example` → variables de configuración
- `scripts/get-mantis.sh` → descarga opcional de MantisBT (no embebido)

## Nota
MantisBT no se incluye en este repositorio. Se instala por separado o se descarga con el script opcional.
