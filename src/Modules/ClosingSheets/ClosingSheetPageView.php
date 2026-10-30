<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Página propia de la hoja de cierre de negocio (public/hoja-cierre.php): paso 1 con los datos del negocio y paso 2
 * con la lista de chequeo (solo al crear, como el formulario original).
 */
final class ClosingSheetPageView
{
  private bool $editing = false;

  /**
   * @param array<string,mixed> $context ClosingSheetService::context()
   * @param array<string,mixed> $options ClosingSheetService::options()
   * @param array{user:string,base_url:string,logo:string,favicon:string,nonce:string,action:string,css:list<string>,js:list<string>} $page
   */
  public function render(array $context, array $options, array $page): string
  {
    $h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $creating = $context['mode'] === 'crear';
    $this->editing = !$creating;
    $title = $creating ? 'Añadir hoja de cierre' : 'Editar hoja de cierre #' . $context['closing_id'];
    $values = $context['values'];
    $facts = array_filter([
      'Mandato' => '#' . ($context['mandate']['_ID'] ?? ''),
      'Hoja' => $creating ? '' : '#' . $context['closing_id'],
      'Dirección' => $context['address'],
      'Arrendatario' => (string) ($values['arrendatario'] ?? ''),
    ], static fn($value): bool => trim((string) $value, ' #') !== '');

    ob_start();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($title) ?> · SKC SuCasa Inmobiliaria</title>
  <?php if ($page['favicon'] !== ''): ?><link rel="icon" href="<?= $h($page['favicon']) ?>" sizes="32x32"><?php endif; ?>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
  <?php foreach ($page['css'] as $href): ?><link rel="stylesheet" href="<?= $h($href) ?>">
  <?php endforeach; ?>
  <?php foreach ($page['js'] as $src): ?><script src="<?= $h($src) ?>" defer></script>
  <?php endforeach; ?>
</head>
<body class="scm-review-page cs-page<?= !empty($page['embed']) ? ' is-embed' : '' ?>">
  <main class="scm-review-page-shell cs-shell">
    <section class="rp-hero">
      <span class="rp-hero-icon material-symbols-outlined" aria-hidden="true">handshake</span>
      <div class="rp-hero-text">
        <p class="rp-eyebrow">Hoja de cierre de negocio</p>
        <h1><?= $h($title) ?></h1>
        <dl class="rp-facts">
          <?php foreach ($facts as $term => $value): ?><div><dt><?= $h($term) ?></dt><dd><?= $h($value) ?></dd></div><?php endforeach; ?>
        </dl>
      </div>
    </section>

    <form class="cs-form" data-cs-form data-cs-api="<?= $h($page['base_url'] . '/api.php') ?>" data-cs-draft="<?= $h($creating ? 'cierre:crear:' . $context['ids']['id_contrato'] . ':' . $context['ids']['id_arrendatario'] : 'cierre:editar:' . $context['closing_id']) ?>" novalidate>
      <input type="hidden" name="action" value="<?= $h($page['action']) ?>">
      <input type="hidden" name="nonce" value="<?= $h($page['nonce']) ?>">
      <input type="hidden" name="id_cierre" value="<?= (int) $context['closing_id'] ?>">
      <?php foreach ($context['ids'] as $key => $value): ?><input type="hidden" name="<?= $h($key) ?>" value="<?= $h($value) ?>">
      <?php endforeach; ?>

      <ol class="cs-steps" aria-label="Pasos">
        <li class="is-active" data-cs-step-tab="1"><span>1</span> Datos del negocio</li>
        <li data-cs-step-tab="2"><span>2</span> Lista de chequeo</li>
      </ol>

      <div class="cs-step" data-cs-step="1">
        <?php foreach (ClosingSheetForm::SECTIONS as [$sectionTitle, $fields]): ?>
          <?= $this->section($sectionTitle, $fields, $values, $options) ?>
          <?php if ($sectionTitle === 'Propietario'): ?><?= $this->owners($context['owners']) ?><?php endif; ?>
        <?php endforeach; ?>
        <div class="cs-actions">
          <button type="button" class="cs-primary" data-cs-next>Siguiente: lista de chequeo <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
        </div>
      </div>

      <div class="cs-step" data-cs-step="2" hidden>
        <?= $this->checklist($options['estado_checklist'] ?? [], $values, $creating) ?>
        <div class="cs-actions">
          <button type="button" class="cs-secondary" data-cs-prev><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Anterior</button>
          <button type="submit" class="cs-primary"><span class="material-symbols-outlined" aria-hidden="true">save</span> <?= $creating ? 'Añadir hoja de cierre' : 'Guardar cambios' ?></button>
        </div>
      </div>
      <p class="cs-error" data-cs-error role="alert" hidden></p>
    </form>

    <section class="rp-card cs-done" data-cs-done hidden aria-live="polite">
      <span class="material-symbols-outlined cs-done-icon" aria-hidden="true">task_alt</span>
      <h2 data-cs-done-title></h2>
      <p data-cs-done-text></p>
      <div class="cs-actions">
        <a class="cs-primary" data-cs-done-view href="#">Ver hoja de cierre</a>
        <a class="cs-secondary" data-cs-done-edit href="#">Editar</a>
        <a class="cs-secondary" href="<?= $h($page['base_url'] . '/index.php') ?>">Ir al panel</a>
      </div>
    </section>
  </main>
</body>
</html>
<?php
    return (string) ob_get_clean();
  }

  /** @param list<array<string,mixed>> $fields @param array<string,mixed> $values @param array<string,mixed> $options */
  private function section(string $title, array $fields, array $values, array $options): string
  {
    $html = '';
    foreach ($fields as $field) {
      $html .= $this->field($field, $values, $options);
    }
    $when = $this->sharedWhen($fields);
    return '<fieldset class="cs-section"' . $when . '><legend>' . $this->e($title) . '</legend><div class="cs-grid">' . $html . '</div></fieldset>';
  }

  /** Si todos los campos de la sección dependen de lo mismo, la sección entera se oculta. @param list<array<string,mixed>> $fields */
  private function sharedWhen(array $fields): string
  {
    $when = $fields[0]['when'] ?? null;
    if ($when === null) {
      return '';
    }
    foreach ($fields as $field) {
      if (($field['when'] ?? null) !== $when) {
        return '';
      }
    }
    return ' data-cs-when="' . $this->e(json_encode($when, JSON_UNESCAPED_UNICODE)) . '"';
  }

  /** @param array<string,mixed> $field @param array<string,mixed> $values @param array<string,mixed> $options */
  private function field(array $field, array $values, array $options): string
  {
    $name = (string) $field['name'];
    $value = $values[$name] ?? '';
    // Al editar, lo que la hoja guardada tenía vacío no es obligatorio (igual que en el servidor).
    $required = !empty($field['required']) && !($this->editing && (is_array($value) ? $value === [] : trim((string) $value) === ''));
    $readonly = !empty($field['readonly']);
    $id = 'cs_' . $name;
    $label = '<label class="cs-label" for="' . $id . '">' . $this->e($field['label']) . ($required ? ' <span class="cs-req" aria-hidden="true">*</span>' : '') . ($readonly ? ' <span class="cs-tag">del mandato</span>' : '') . '</label>';
    $common = ' id="' . $id . '" name="' . $this->e($name) . '"' . ($required ? ' data-cs-required' : '') . ($readonly ? ' readonly tabindex="-1"' : '');
    $choices = $this->choices($field, $options);
    $wide = in_array($field['type'], ['textarea', 'checkbox', 'repeater'], true);

    $control = match ($field['type']) {
      'select' => $readonly
        ? '<input type="text" class="cs-input"' . $common . ' value="' . $this->e(is_array($value) ? '' : $value) . '">'
        : '<select class="cs-input"' . $common . '><option value="">Selecciona…</option>' . $this->optionTags($choices, (string) (is_array($value) ? '' : $value)) . '</select>',
      'checkbox' => '<div class="cs-checks" role="group" aria-labelledby="' . $id . '_l">' . implode('', array_map(
        fn($optionValue, $optionLabel): string => '<label class="cs-check"><input type="checkbox" name="' . $this->e($name) . '[]" value="' . $this->e($optionValue) . '"' . (in_array((string) $optionValue, array_map('strval', (array) $value), true) ? ' checked' : '') . '> <span>' . $this->e($optionLabel) . '</span></label>',
        array_keys($choices), $choices
      )) . '</div>',
      'textarea' => '<textarea class="cs-input" rows="3"' . $common . '>' . $this->e(is_array($value) ? '' : $value) . '</textarea>',
      'repeater' => $this->repeater($name, is_array($value) ? $value : [], $options),
      'time' => '<input type="time" class="cs-input"' . $common . ' value="' . $this->e(substr((string) (is_array($value) ? '' : $value), 0, 5)) . '">',
      'number' => '<input type="text" inputmode="decimal" class="cs-input"' . $common . ' value="' . $this->e(is_array($value) ? '' : $value) . '">',
      default => '<input type="' . (str_starts_with($name, 'correo') ? 'email' : 'text') . '" class="cs-input"' . $common . ' value="' . $this->e(is_array($value) ? '' : $value) . '">',
    };
    if ($field['type'] === 'checkbox') {
      $label = '<span class="cs-label" id="' . $id . '_l">' . $this->e($field['label']) . '</span>';
    }
    $help = trim((string) ($field['help'] ?? ''));
    $when = isset($field['when']) ? ' data-cs-when="' . $this->e(json_encode($field['when'], JSON_UNESCAPED_UNICODE)) . '"' : '';
    return '<div class="cs-field' . ($wide ? ' is-wide' : '') . '"' . $when . ' data-cs-field="' . $this->e($name) . '">' . $label . $control
      . ($help !== '' ? '<small class="cs-help">' . $this->e($help) . '</small>' : '') . '</div>';
  }

  /** @param array<mixed> $rows @param array<string,mixed> $options */
  private function repeater(string $name, array $rows, array $options): string
  {
    $columns = ClosingSheetForm::REPEATERS[$name] ?? [];
    $row = function (string $index, array $data) use ($name, $columns, $options): string {
      $cells = '';
      foreach ($columns as $column) {
        $input = $name . '[' . $index . '][' . $column['name'] . ']';
        $value = (string) ($data[$column['name']] ?? '');
        $cells .= '<label class="cs-mini"><span>' . $this->e($column['label']) . '</span>' . (($column['type'] ?? '') === 'select'
          ? '<select class="cs-input" name="' . $this->e($input) . '"><option value=""></option>' . $this->optionTags($this->choices($column, $options), $value) . '</select>'
          : '<input type="text" class="cs-input" name="' . $this->e($input) . '" value="' . $this->e($value) . '">') . '</label>';
      }
      return '<div class="cs-repeat-row" data-cs-row>' . $cells . '<button type="button" class="cs-icon" data-cs-remove aria-label="Quitar"><span class="material-symbols-outlined" aria-hidden="true">delete</span></button></div>';
    };
    $html = '';
    foreach (array_values($rows) as $i => $data) {
      $html .= $row((string) $i, is_array($data) ? $data : []);
    }
    return '<div class="cs-repeat" data-cs-repeat="' . $this->e($name) . '"><div data-cs-rows>' . $html . '</div>'
      . '<template data-cs-template>' . $row('__i__', []) . '</template>'
      . '<button type="button" class="cs-secondary cs-add" data-cs-add><span class="material-symbols-outlined" aria-hidden="true">add</span> Agregar</button></div>';
  }

  /** @param list<array<string,mixed>> $owners */
  private function owners(array $owners): string
  {
    if ($owners === []) {
      return '';
    }
    $single = count($owners) === 1;
    $rows = '';
    foreach ($owners as $owner) {
      $index = (int) $owner['index'];
      $details = implode(' · ', array_filter([
        $owner['type'] === 'Juridica' ? 'Jurídica' : $owner['type'],
        trim($owner['document_type'] . ' ' . $owner['document']),
        $owner['representative'] !== '' ? 'Rep. legal: ' . trim($owner['representative'] . ' ' . $owner['representative_document']) : '',
      ], static fn(string $part): bool => $part !== ''));
      $rows .= '<tr><td>' . $index . '</td><td><strong>' . $this->e($owner['name']) . '</strong><small>' . $this->e($details) . '</small></td>'
        . '<td>' . ($owner['owner_id'] !== '' ? '<span class="cs-ok">Registrado</span>' : '<span class="cs-warn">Se registrará al guardar</span>') . '</td>'
        . '<td><label class="cs-percent"><input type="text" inputmode="decimal" class="cs-input" name="porcentaje[' . $index . ']" value="' . $this->e($owner['percent']) . '"' . ($single ? ' readonly' : '') . ' data-cs-percent aria-label="Porcentaje de ' . $this->e($owner['name']) . '"><span>%</span></label></td></tr>';
    }
    return '<fieldset class="cs-section"><legend>Copropietarios y participación</legend>'
      . '<p class="cs-note">Propietarios del contrato de mandato. El porcentaje de cada uno define su participación (por ejemplo, en el cupo de créditos). Al guardar, también se actualiza en el mandato.</p>'
      . '<div class="cs-table-wrap"><table class="cs-table"><thead><tr><th>#</th><th>Propietario</th><th>Ficha</th><th>Participación</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
      . ($single ? '' : '<div class="cs-percent-bar"><span data-cs-percent-sum></span><button type="button" class="cs-secondary" data-cs-split>Repartir en partes iguales</button></div>')
      . '</fieldset>';
  }

  /**
   * Al editar se marcan los estados y observaciones guardados; un ítem que la hoja tenía vacío (hojas anteriores a la
   * lista) no es obligatorio.
   * @param list<string> $states @param array<string,mixed> $values
   */
  private function checklist(array $states, array $values, bool $creating): string
  {
    $states = $states !== [] ? $states : ['Cumple', 'No cumple', 'No aplica'];
    $html = '<p class="cs-note">Marca el estado de cada documento o requisito. La observación es opcional.</p>';
    foreach (ClosingSheetForm::CHECKLIST as [$group, $when, $items]) {
      $rows = '';
      foreach ($items as [$column, $text]) {
        $state = $creating ? '' : (string) ($values[$column] ?? '');
        $required = $creating || $state !== '';
        $rows .= '<div class="cs-check-item"><p>' . $this->e($text) . '</p>'
          . '<select class="cs-input" name="' . $this->e($column) . '"' . ($required ? ' data-cs-required' : '') . ' aria-label="Estado"><option value="">Estado…</option>' . $this->optionTags(array_combine($states, $states), $state) . '</select>'
          . '<textarea class="cs-input" rows="2" name="notas[' . $this->e($column) . ']" placeholder="Observación (opcional)">' . $this->e($values['__notes'][$column] ?? '') . '</textarea></div>';
      }
      $html .= '<fieldset class="cs-section"' . ($when !== null ? ' data-cs-when="' . $this->e(json_encode($when, JSON_UNESCAPED_UNICODE)) . '"' : '') . '><legend>' . $this->e($group) . '</legend>' . $rows . '</fieldset>';
    }
    return $html;
  }

  /** @param array<string,mixed> $field @param array<string,mixed> $options @return array<string,string> valor => etiqueta */
  private function choices(array $field, array $options): array
  {
    $source = $field['options'] ?? null;
    $list = is_array($source) ? $source : (is_string($source) ? ($options[$source] ?? []) : []);
    if ($source === 'club_pph' || (is_array($source) && !array_is_list($source))) {
      return array_map('strval', $list);
    }
    $list = array_map('strval', array_values($list));
    return $list === [] ? [] : array_combine($list, $list);
  }

  /** @param array<string,string> $choices */
  private function optionTags(array $choices, string $selected): string
  {
    $html = '';
    $found = false;
    foreach ($choices as $value => $label) {
      $isSelected = (string) $value === $selected;
      $found = $found || $isSelected;
      $html .= '<option value="' . $this->e($value) . '"' . ($isSelected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
    }
    // Un valor guardado que ya no está en el glosario se conserva visible.
    if ($selected !== '' && !$found) {
      $html .= '<option value="' . $this->e($selected) . '" selected>' . $this->e($selected) . '</option>';
    }
    return $html;
  }

  private function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
