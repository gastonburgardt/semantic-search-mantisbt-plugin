# How to install with Mantis (from zero)

Guía práctica para instalar SemanticSearch en una instalación nueva de MantisBT.

## Objetivo
Dejar operativo:
- MantisBT
- Plugin SemanticSearch
- Tablas de indexación semántica
- Qdrant
- Configuración inicial del plugin

---

## 1) Levantar Mantis + DB

Ejemplo mínimo con Docker Compose (Mantis + MariaDB):

```yaml
services:
  db:
    image: mariadb:11
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: mantisbt
      MYSQL_USER: mantisbt
      MYSQL_PASSWORD: mantisbt

  app:
    image: vimagick/mantisbt:latest
    depends_on: [db]
    ports:
      - "18080:80"
```

Levantar:

```bash
docker compose up -d
```

---

## 2) Instalar Mantis (web)

Entrar a:

- `http://<host>:18080/admin/install.php`

Completar:
- DB host: `db`
- DB user/pass: `mantisbt` / `mantisbt`
- DB name: `mantisbt`
- Admin DB user/pass: `root` / `root`

---

## 3) Descargar este repo

```bash
git clone https://github.com/gastonburgardt/semantic-search-mantisbt-plugin.git
cd semantic-search-mantisbt-plugin
```

---

## 4) Configurar Qdrant env

```bash
cp deploy/.env.example deploy/qdrant/.env
# editar valores necesarios (puertos, colección, api key si aplica a tu entorno)
```

---

## 5) Ejecutar instalación automática del plugin

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

Notas:
- `--plugin-prefix` puede ser `mantis_plugin_` o `mantisplugin_` según instalación.
- El script hace: copiar plugin + aplicar SQL + registrar plugin + levantar qdrant + validar tablas.

---

## 6) Configuración final en Mantis

Entrar a:
- `plugin.php?page=SemanticSearch/config_page`

Configurar:
- `qdrant_url`
- `qdrant_collection`
- `openai_embedding_model` (default recomendado: `text-embedding-3-large`)
- `top_k`, `min_score`, include notes/attachments

---

## 7) Smoke test

1. Abrir un issue
2. Ver panel **Indexación semántica**
3. Click en **Guardar política**
4. Click en **Guardar e indexar ahora**
5. Ir a `plugin.php?page=SemanticSearch/search` y probar búsqueda

---

## 8) Solución rápida de problemas

- Error de tabla no existe (`mantis_plugin_semsearch_*`):
  - revisar `--plugin-prefix` usado en install script.
- Redirección incorrecta a localhost:
  - ajustar `$g_path` en `config/config_inc.php`.
- Sin resultados en búsqueda:
  - verificar Qdrant (`/healthz`), colección y configuración de plugin.
