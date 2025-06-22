# Checkbox Synchronization Fix - ContentRadar

## Problema
Los checkboxes seleccionados en la tabla de resultados no estaban siendo enviados correctamente al servidor, causando el error "No items selected for replacement".

## Causa Raíz
Los checkboxes en el template Twig no estaban conectados con el formulario Drupal. Eran elementos HTML simples que no se procesaban como parte del form submission.

## Solución Implementada

### 1. Creación de Checkboxes en el Formulario PHP
```php
// En TextSearchForm::buildForm()
foreach ($results['items'] as $item) {
  $checkbox_key = $item['entity_type'] . ':' . $item['id'] . ':' . $item['field_name'] . ':' . $item['langcode'];
  $form['results_container']['selected_items'][$checkbox_key] = [
    '#type' => 'checkbox',
    '#default_value' => FALSE,
    '#attributes' => [
      'class' => ['result-item-checkbox-hidden'],
      'data-checkbox-key' => $checkbox_key,
    ],
  ];
}
```

### 2. Actualización del Template Twig
- Removido el atributo `name` de los checkboxes visibles
- Añadido `data-checkbox-key` para identificación

### 3. Sincronización JavaScript
- Los checkboxes visibles ahora sincronizan su estado con los checkboxes ocultos del formulario
- Cuando el usuario marca/desmarca un checkbox visible, JavaScript actualiza el correspondiente checkbox del formulario

### 4. Obtención Correcta de Valores
```php
// En TextSearchForm::replaceSubmit()
$selected_items = $form_state->getValue(['results_container', 'selected_items'], []);
```

### 5. CSS para Ocultar Checkboxes del Formulario
- Los checkboxes del formulario están ocultos visualmente pero presentes en el DOM

## Flujo de Datos
1. Usuario marca checkbox visible en la tabla
2. JavaScript detecta el cambio
3. JavaScript actualiza el checkbox oculto correspondiente del formulario
4. Al enviar, Drupal procesa los checkboxes del formulario
5. El servidor recibe los valores seleccionados correctamente

## Archivos Modificados
- `src/Form/TextSearchForm.php`
- `templates/content-radar-results.html.twig`
- `js/content-radar.js`
- `css/content-radar-hidden-checkboxes.css`
- `content_radar.libraries.yml`