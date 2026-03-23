# SemanticSearch — Operación diaria

## Tareas operativas

### A) Revisión de política (recomendado diario)
- Pantalla: `Semantic Indexing`
- Botón: `Revisar política`
- Objetivo: mantener estado real en tablas de control

### B) Indexación
- Pantalla: `Semantic Indexing`
- Botón: `Iniciar indexación`
- Objetivo: aplicar acciones pendientes (`Create/Update/Delete`)

### C) Revisión puntual en issue
- Panel `Indexación semántica`
- Opciones:
  - `Guardar política`
  - `Guardar e indexar ahora`

## Métricas a observar

- Pendientes para indexar
- Pendientes para borrar
- Fallos por batch
- Tiempo por lote

## Buenas prácticas

- Ejecutar primero política y luego indexación
- Lotes moderados (ej. 25)
- `max_issues` para corridas controladas
