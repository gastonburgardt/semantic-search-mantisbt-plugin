# SemanticSearch — Rollback

## Objetivo
Volver a estado operativo anterior sin perder trazabilidad.

## Paso 1: Backup rápido

```bash
# backup SQL
docker exec mymantisbtq-db sh -lc 'mysqldump -umantisbt -pmantisbt mantisbt > /tmp/mantisbt_backup_semsearch.sql'
```

## Paso 2: Deshabilitar plugin temporalmente

Desde UI de Mantis o tabla de plugins.

## Paso 3: Restaurar configuración previa

- Restaurar `.env` anterior
- Restaurar compose previo (si cambió)
- Reiniciar `mymantisbtq-app`

## Paso 4: Verificación

- Mantis accesible
- Sin errores PHP en logs
- UI de issues normal

## Nota
No borrar tablas semsearch en rollback rápido; primero estabilizar servicio.
