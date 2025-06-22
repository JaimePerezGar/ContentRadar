# Checkbox Synchronization Fix - ContentRadar (v2)

## Problema
Los checkboxes seleccionados en la tabla de resultados no estaban siendo enviados correctamente al servidor, causando el error "No items selected for replacement".

## Causa Raíz
Los checkboxes en el template Twig no estaban conectados con el formulario Drupal. El enfoque anterior con checkboxes ocultos no funcionaba correctamente al reconstruir el formulario.

## Solución Implementada (Enfoque JSON)

### 1. Campo Hidden para Almacenar Selecciones
```php
// En TextSearchForm::buildForm()
$form['selected_items_data'] = [
  '#type' => 'hidden',
  '#default_value' => '',
  '#attributes' => ['id' => 'selected-items-data'],
];
```

### 2. JavaScript para Actualizar el Campo Hidden
```javascript
function updateSelectedItemsData() {
  var selectedKeys = [];
  $itemCheckboxes.filter(':checked').each(function() {
    var key = $(this).data('checkbox-key');
    if (key) {
      selectedKeys.push(key);
    }
  });
  
  $('#selected-items-data').val(JSON.stringify(selectedKeys));
}
```

### 3. Procesamiento en PHP
```php
// En TextSearchForm::replaceSubmit()
$selected_items_json = $form_state->getValue('selected_items_data', '');
if (!empty($selected_items_json)) {
  $selected_items_array = json_decode($selected_items_json, TRUE);
  if (is_array($selected_items_array)) {
    foreach ($selected_items_array as $key) {
      $selected_items[$key] = 1;
    }
  }
}
```

## Flujo de Datos
1. Usuario marca checkboxes en la tabla
2. JavaScript recolecta las keys de los items seleccionados
3. JavaScript actualiza el campo hidden con un array JSON
4. Al enviar el formulario, PHP decodifica el JSON
5. Los items seleccionados se procesan correctamente

## Ventajas del Nuevo Enfoque
- No depende de la reconstrucción del formulario
- Más simple y directo
- Menos elementos DOM
- Compatible con AJAX

## Archivos Modificados
- `src/Form/TextSearchForm.php`
- `templates/content-radar-results.html.twig`
- `js/content-radar.js`
- `content_radar.libraries.yml`