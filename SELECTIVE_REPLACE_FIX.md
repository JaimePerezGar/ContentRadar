# Selective Replace Fix - ContentRadar

## Problema Identificado
Cuando se seleccionaban elementos específicos para reemplazo, el sistema estaba reemplazando en TODOS los elementos encontrados, no solo en los seleccionados.

## Solución Implementada

### 1. Logging para Debugging (TextSearchForm::replaceSubmit)
- Añadido logging detallado para rastrear el flujo de datos
- Logs muestran: replace_mode, selected_items raw/filtered, y conteo

### 2. Validación Estricta (TextSearchForm::replaceSubmit)
- Si replace_mode es "selected" pero no hay items seleccionados, muestra error
- Previene ejecución accidental de reemplazo masivo
- Mensaje claro al usuario sobre qué hacer

### 3. Lógica Mejorada en Servicio (TextSearchService::replaceText)
- Añadido logging para confirmar ruta de ejecución
- Comentarios claros: "SOLO reemplazo selectivo" vs "SOLO si NO hay elementos seleccionados"
- Log al completar reemplazo selectivo con conteo

### 4. Verificación en replaceInSelectedItems
- Validación inicial: si array está vacío, retorna inmediatamente
- Logging de cuántos items se van a procesar

### 5. Mejora en Batch Processing (TextSearchForm::batchProcess)
- Añadido logging para cada entidad procesada
- Si no hay items seleccionados para una entidad específica, la omite
- Previene procesamiento innecesario

## Logs para Monitoreo

Los siguientes logs ayudarán a verificar el comportamiento:

```
DEBUG - Replace mode: selected
DEBUG - Selected items count: 1
INFO - Proceeding with selective replacement of 1 items
INFO - Processing 1 selected items for replacement
DEBUG - Batch process - Entity: node:123, Selected items: Array(...)
INFO - Selective replacement completed: 1 replacements in 1 entities
```

## Testing Recomendado

1. Buscar texto que aparezca en múltiples nodos
2. Seleccionar UN SOLO checkbox
3. Verificar que "Replace only selected occurrences" esté seleccionado
4. Click en "Replace"
5. Verificar en logs y resultados que SOLO se modificó el elemento seleccionado

## Comportamiento Esperado

- **Con elementos seleccionados**: Solo esos elementos se modifican
- **Sin elementos seleccionados + modo "selected"**: Error pidiendo selección
- **Sin elementos seleccionados + modo "all"**: Reemplazo masivo normal

## Notas Técnicas

- Los checkboxes tienen formato: `entity_type:entity_id:field_name:langcode`
- El JavaScript actualiza automáticamente el modo basado en la selección
- El batch processing filtra items por entidad para eficiencia