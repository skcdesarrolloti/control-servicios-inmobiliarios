<?php

declare(strict_types=1);

// Página de prueba para closing-sheet-policy-ui-check.cjs: paso 1 mínimo y la lista de chequeo real (ClosingSheetPageView)
// con dos ítems con política. Imprime el HTML; el .cjs le agrega closing-sheet.js.
require dirname(__DIR__) . '/vendor/autoload.php';

$view = new SCM\Modules\ClosingSheets\ClosingSheetPageView();
$checklist = (new ReflectionMethod($view, 'checklist'))->invoke($view, ['Cumple', 'No cumple', 'No aplica'], ['__notes' => [], '__justifications' => []], true, [], [
  'config' => ['api' => 'api.php', 'nonce' => 'n', 'upload' => 'up', 'delete' => 'del', 'scope' => ['mandate_id' => 636]], 'items' => [],
], [
  'estado_amparo_integral' => ['states' => ['No cumple'], 'note' => 'Todos los contratos deben incluir la garantía de servicios públicos.', 'policies' => [['id' => 47, 'title' => 'Garantía de Servicios Públicos', 'text' => 'Texto de la política 47.']]],
  'estado_termino_contrato' => ['states' => ['No cumple'], 'note' => 'El término mínimo del contrato de arrendamiento es de 12 meses.', 'policies' => []],
], ['accounts' => ['Energia' => true, 'Agua' => false, 'Gas' => false], 'internet' => false, 'telefono' => true, 'amparo' => 'Si', 'cobertura' => 36, 'bank' => true, 'study' => true]);

echo '<form data-cs-form><p data-cs-error hidden></p>'
  . '<div data-cs-step="1">'
  . '<label><input type="checkbox" name="servicios_publicos[]" value="Energia" checked></label><label><input type="checkbox" name="servicios_publicos[]" value="Agua" checked></label><label><input type="checkbox" name="servicios_publicos[]" value="Gas"></label>'
  . '<input name="luz" value="123"><input name="agua" value="">'
  . '<select name="propiedad_horizontal"><option>Si</option><option selected>No</option></select>'
  . '<input name="meses_contrato" value="6"><input name="dias_gracia" value="">'
  . '<select name="tipo_persona_propietario"><option selected>Natural</option><option>Juridica</option></select>'
  . '<select name="tipo_persona_arrendatario"><option>Natural</option><option selected>Juridica</option></select>'
  . '<button type="button" data-cs-next>Siguiente</button></div>'
  . '<div data-cs-step="2" hidden>' . $checklist . '<p data-cs-error hidden></p><button type="submit">Guardar</button></div></form>'
  . SCM\Modules\ClosingSheets\ClosingSheetDocumentsView::dialogs();
