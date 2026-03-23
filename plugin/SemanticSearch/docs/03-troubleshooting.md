# SemanticSearch — Troubleshooting

## 1) No aparece panel en issue

Checklist:
- Plugin habilitado en Mantis
- `mymantisbtq-app` reiniciado
- Sin errores PHP en logs

Comandos:
```bash
docker logs --tail 200 mymantisbtq-app
```

## 2) No hay resultados en búsqueda

Checklist:
- Qdrant activo
- OpenAI API key válida
- Ya existen issues indexados
- `min_score` no demasiado alto

## 3) Errores al indexar

Checklist:
- Conectividad a Qdrant (`QDRANT_URL`)
- Colección existente/creable
- Estado de red/DNS en contenedores

## 4) Política no refleja cambios

Checklist:
- Ejecutar `Revisar política`
- Verificar tablas `mantisplugin_semsearch_*`
- Confirmar `Indexable/Empty/Indexed/Action/NivelDeRevision`

