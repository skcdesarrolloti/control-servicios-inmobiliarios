<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Página propia de la hoja de cierre de negocio (public/hoja-cierre.php): paso 1 con los datos del negocio y paso 2
 * con la lista de chequeo (solo al crear, como el formulario original). Al crear, la sección Arrendatario trae el
 * buscador y la ventana "Crear arrendatario" (reemplazan la página intermedia de WordPress).
 */
final class ClosingSheetPageView
{
  private bool $editing = false;

  /**
   * @param array<string,mixed> $context ClosingSheetService::context()
   * @param array<string,mixed> $options ClosingSheetService::options()
   * @param array{user:string,base_url:string,logo:string,favicon:string,nonce:string,action:string,css:list<string>,js:list<string>,tenant?:array{current:?array<string,string>,options:array<string,mixed>,search:string,save:string}} $page
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
          <?php if ($sectionTitle === 'Propietario'): ?><?= $this->owners($fields, $context['owners'], $values, $options) ?>
          <?php else: ?><?= $this->section($sectionTitle, $fields, $values, $options, $creating && $sectionTitle === 'Arrendatario' && isset($page['tenant']) ? $this->tenantPicker($page['tenant']) : '') ?><?php endif; ?>
        <?php endforeach; ?>
        <div class="cs-actions">
          <button type="button" class="cs-primary" data-cs-next>Siguiente: lista de chequeo <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button>
        </div>
      </div>

      <div class="cs-step" data-cs-step="2" hidden>
        <?= $this->checklist($options['estado_checklist'] ?? [], $values, $creating, $context['owners'], $page['documents'] ?? null) ?>
        <div class="cs-actions">
          <button type="button" class="cs-secondary" data-cs-prev><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Anterior</button>
          <button type="submit" class="cs-primary"><span class="material-symbols-outlined" aria-hidden="true">save</span> <?= $creating ? 'Añadir hoja de cierre' : 'Guardar cambios' ?></button>
        </div>
      </div>
      <p class="cs-error" data-cs-error role="alert" hidden></p>
    </form>
    <?php if ($creating && isset($page['tenant'])): ?><?= $this->tenantDialog($page['tenant']['options']) ?><?php endif; ?>
    <?php if (isset($page['documents'])): ?><?= ClosingSheetDocumentsView::dialogs() ?><?php endif; ?>

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
  private function section(string $title, array $fields, array $values, array $options, string $before = ''): string
  {
    // Codeudores: un bloque por persona (se oculta entero según el número de codeudores).
    [$loose, $groups] = ClosingSheetForm::personGroups($fields);
    $html = '';
    foreach ($loose as $field) {
      $html .= $this->field($field, $values, $options);
    }
    $people = '';
    foreach ($groups as $person => $personFields) {
      $inner = '';
      foreach ($personFields as $field) {
        $inner .= $this->field($field, $values, $options);
      }
      $people .= '<div class="cs-person" data-cs-person="' . $this->e($person) . '"' . $this->sharedWhen($personFields) . '><h3><span class="material-symbols-outlined" aria-hidden="true">person</span>' . $this->e($person) . '</h3><div class="cs-grid">' . $inner . '</div></div>';
    }
    $when = $this->sharedWhen($fields);
    return '<fieldset class="cs-section"' . $when . '><legend>' . $this->e($title) . '</legend>' . $before . '<div class="cs-grid">' . $html . '</div>' . $people . '</fieldset>';
  }

  /**
   * Buscador del arrendatario: el elegido llena los campos de abajo y el id_arrendatario oculto.
   * @param array{current:?array<string,string>,search:string,save:string} $tenant
   */
  private function tenantPicker(array $tenant): string
  {
    $current = $tenant['current'];
    $detail = $current !== null ? trim($current['tipo_documento'] . ' ' . $current['documento'] . ($current['celular'] !== '' ? ' · ' . $current['celular'] : '')) : '';
    return '<div class="cs-tenant" data-cs-tenant data-cs-tenant-search-action="' . $this->e($tenant['search']) . '" data-cs-tenant-save-action="' . $this->e($tenant['save']) . '">'
      . '<div class="cs-tenant-current' . ($current === null ? ' is-empty' : '') . '" data-cs-tenant-current>'
      . '<span class="material-symbols-outlined" aria-hidden="true" data-cs-tenant-icon>' . ($current === null ? 'person_search' : 'how_to_reg') . '</span>'
      . '<div><strong data-cs-tenant-name>' . $this->e($current !== null ? $current['nombre'] : 'Sin arrendatario elegido') . '</strong>'
      . '<small data-cs-tenant-detail>' . $this->e($current !== null ? $detail : 'Búscalo por nombre, documento o celular. Si no aparece, créalo aquí mismo.') . '</small></div></div>'
      . '<div class="cs-tenant-bar">'
      . '<label class="cs-sr" for="cs_tenant_q">Buscar arrendatario</label>'
      . '<input type="search" id="cs_tenant_q" class="cs-input" placeholder="Buscar por nombre, documento o celular" autocomplete="off" data-cs-tenant-q aria-controls="cs_tenant_results">'
      . '<button type="button" class="cs-secondary" data-cs-tenant-new><span class="material-symbols-outlined" aria-hidden="true">person_add</span> Crear arrendatario</button>'
      . '</div>'
      . '<div class="cs-tenant-results" id="cs_tenant_results" data-cs-tenant-results aria-live="polite" hidden></div>'
      . '</div>';
  }

  /**
   * Ventana "Crear arrendatario", con los campos del JetForm "Añadir arrendatario" (fuera del formulario de la hoja).
   * @param array<string,mixed> $options ClosingSheetTenants::options()
   */
  private function tenantDialog(array $options): string
  {
    $label = fn(string $name, string $text, bool $required = true): string => '<label class="cs-label" for="cs_t_' . $name . '">' . $this->e($text) . ($required ? ' <span class="cs-req" aria-hidden="true">*</span>' : '') . '</label>';
    $select = function (string $name, string $text, array $choices, string $selected = '') use ($label): string {
      $tags = '<option value="">Selecciona…</option>';
      foreach ($choices as $value) {
        $tags .= '<option value="' . $this->e($value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . $this->e($value === 'Juridica' ? 'Jurídica' : $value) . '</option>';
      }
      return '<div class="cs-field">' . $label($name, $text) . '<select class="cs-input" id="cs_t_' . $name . '" name="' . $name . '" required>' . $tags . '</select></div>';
    };
    $input = fn(string $name, string $text, string $type = 'text', bool $required = true, string $attrs = ''): string => '<div class="cs-field">' . $label($name, $text, $required)
      . '<input class="cs-input" type="' . $type . '" id="cs_t_' . $name . '" name="' . $name . '"' . ($required ? ' required' : '') . $attrs . '></div>';
    $countries = '<option value="">Selecciona…</option>';
    foreach ((array) ($options['paises'] ?? []) as $row) {
      $countries .= '<option value="' . $this->e($row['codigo']) . '" data-pais="' . $this->e($row['pais']) . '"' . ($row['pais'] === 'Colombia' ? ' selected' : '') . '>' . $this->e($row['pais'] . ' (' . $row['codigo'] . ')') . '</option>';
    }
    // La tabla de ciudades no tiene todos los municipios: la ciudad se escribe, con sugerencias (primero las del
    // departamento elegido).
    $cities = array_map(static fn(array $row): array => ['c' => (string) $row['ciudad'], 'd' => (string) $row['departamento']], (array) ($options['ciudades'] ?? []));
    return '<dialog class="cs-dialog" data-cs-tenant-dialog aria-labelledby="cs_t_title">'
      . '<form class="cs-dialog-box" data-cs-tenant-form novalidate>'
      . '<header><h2 id="cs_t_title"><span class="material-symbols-outlined" aria-hidden="true">person_add</span> Crear arrendatario</h2>'
      . '<button type="button" class="cs-icon" data-cs-tenant-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>'
      . '<div class="cs-dialog-body">'
      . '<p class="cs-note">Escribe el documento sin puntos. Si ya está registrado, te lo ofrecemos para usarlo en la hoja. Al arrendatario nuevo le llega la bienvenida por correo.</p>'
      . '<div class="cs-grid">'
      . $select('tipo_persona', 'Tipo de persona', (array) ($options['tipo_persona'] ?? []), 'Natural')
      . $input('nombre', 'Nombre o empresa', 'text', true, ' autocomplete="off"')
      . $select('tipo_documento', 'Tipo de documento', (array) ($options['tipo_documento'] ?? []), 'Cédula de Ciudadania')
      . $input('documento', 'Documento o NIT', 'text', true, ' autocomplete="off"')
      . $input('expedicion', 'Lugar de expedición', 'text', false)
      . '<div class="cs-field" data-cs-tenant-company hidden>' . $label('representante_legal', 'Representante legal') . '<input class="cs-input" type="text" id="cs_t_representante_legal" name="representante_legal" disabled></div>'
      . '<div class="cs-field" data-cs-tenant-company hidden>' . $label('documento_legal', 'Documento del representante') . '<input class="cs-input" type="text" id="cs_t_documento_legal" name="documento_legal" disabled></div>'
      . '<div class="cs-field">' . $label('indicativo', 'País (indicativo)') . '<select class="cs-input" id="cs_t_indicativo" name="indicativo" required>' . $countries . '</select><input type="hidden" name="pais" value="Colombia"></div>'
      . $input('celular', 'Celular', 'tel', true, ' inputmode="numeric" autocomplete="off"')
      . $input('correo', 'Correo', 'email', true, ' autocomplete="off"')
      . $input('direccion', 'Dirección', 'text', true, ' autocomplete="off"')
      . $select('departamento', 'Departamento', (array) ($options['departamentos'] ?? []), 'Bolívar')
      . '<div class="cs-field">' . $label('ciudad', 'Ciudad o municipio') . '<input class="cs-input" type="text" id="cs_t_ciudad" name="ciudad" list="cs_t_ciudades" required autocomplete="off" placeholder="Escríbela o elígela de la lista" data-cs-cities="' . $this->e(json_encode($cities, JSON_UNESCAPED_UNICODE)) . '"><datalist id="cs_t_ciudades"></datalist></div>'
      . $select('sexo', 'Sexo', (array) ($options['sexo'] ?? []))
      . $input('cumpleanos', 'Cumpleaños', 'date', true, ' max="' . date('Y-m-d') . '"')
      . '</div>'
      . '<p class="cs-error" data-cs-tenant-error role="alert" hidden></p>'
      . '<div class="cs-tenant-exists" data-cs-tenant-exists hidden><p data-cs-tenant-exists-text></p><button type="button" class="cs-primary" data-cs-tenant-use><span class="material-symbols-outlined" aria-hidden="true">how_to_reg</span> Usar este arrendatario</button></div>'
      . '</div>'
      . '<footer><button type="button" class="cs-secondary" data-cs-tenant-close>Cancelar</button>'
      . '<button type="submit" class="cs-primary"><span class="material-symbols-outlined" aria-hidden="true">how_to_reg</span> Registrar y usar en la hoja</button></footer>'
      . '</form></dialog>';
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
    // Al crear, los campos con valor por defecto (horario de mudanza habitual) llegan prellenados.
    if (!$this->editing && isset($field['default']) && (is_array($value) ? $value === [] : trim((string) $value) === '')) {
      $value = $field['default'];
    }
    // Al editar, lo que la hoja guardada tenía vacío no es obligatorio (igual que en el servidor).
    $required = !empty($field['required']) && !($this->editing && (is_array($value) ? $value === [] : trim((string) $value) === ''));
    $readonly = !empty($field['readonly']);
    $id = 'cs_' . $name;
    $label = '<label class="cs-label" for="' . $id . '">' . $this->e($field['label']) . ($required ? ' <span class="cs-req" aria-hidden="true">*</span>' : '') . ($readonly ? ' <span class="cs-tag">' . (isset($field['fixed']) ? 'fijo' : 'del mandato') . '</span>' : '') . (!empty($field['mandate']) ? ' <span class="cs-tag">también en el mandato</span>' : '') . '</label>';
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

  /**
   * Sección Propietario: un bloque por persona con sus datos editables y su participación. El principal usa los campos
   * de la hoja (propietario, documento_propietario…); cada copropietario, copropietario[N][…]. Todo pasa al mandato.
   * @param list<array<string,mixed>> $fields @param list<array<string,mixed>> $owners @param array<string,mixed> $values @param array<string,mixed> $options
   */
  private function owners(array $fields, array $owners, array $values, array $options): string
  {
    $single = count($owners) <= 1;
    $byIndex = [];
    foreach ($owners as $owner) {
      $byIndex[(int) $owner['index']] = $owner;
    }
    $foot = function (array $owner, string $name) use ($single): string {
      return '<div class="cs-owner-foot"><span>' . ($owner['owner_id'] !== '' ? '<span class="cs-ok">Ficha registrada</span>' : '<span class="cs-warn">La ficha se registrará al guardar</span>') . '</span>'
        . '<label class="cs-percent"><span>Participación</span><input type="text" inputmode="decimal" class="cs-input" name="porcentaje[' . (int) $owner['index'] . ']" value="' . $this->e($owner['percent']) . '"' . ($single ? ' readonly' : '') . ' data-cs-percent aria-label="Porcentaje de ' . $this->e($name) . '"><span>%</span></label></div>';
    };
    $block = fn(string $title, string $icon, string $inner, string $footer): string => '<div class="cs-person cs-owner" data-cs-person="' . $this->e($title) . '"><h3><span class="material-symbols-outlined" aria-hidden="true">' . $icon . '</span>' . $this->e($title) . '</h3><div class="cs-grid">' . $inner . '</div>' . $footer . '</div>';

    $inner = '';
    foreach ($fields as $field) {
      $inner .= $this->field(['label' => ClosingSheetForm::ownerLabel((string) $field['label']), 'help' => ''] + $field, $values, $options);
    }
    $main = $byIndex[1] ?? null;
    $html = $block('Propietario principal', 'star', $inner, $main !== null ? $foot($main, (string) ($values['propietario'] ?? '')) : '');
    foreach ($byIndex as $index => $owner) {
      if ($index === 1) {
        continue;
      }
      $ownerValues = [];
      foreach (ClosingSheetForm::CO_OWNER_KEYS as $key => $property) {
        $ownerValues['copropietario[' . $index . '][' . $key . ']'] = (string) ($owner[$property] ?? '');
      }
      $inner = '';
      foreach (ClosingSheetForm::coOwnerFields($index) as $field) {
        $inner .= $this->field($field, $ownerValues, $options);
      }
      $html .= $block('Copropietario ' . $index, 'person', $inner, $foot($owner, (string) $owner['name']));
    }
    return '<fieldset class="cs-section"><legend>' . ($single ? 'Propietario' : 'Propietarios') . '</legend>'
      . '<p class="cs-note">' . ($single ? 'El contrato de mandato tiene un solo propietario: le corresponde el 100 %.' : 'Los propietarios del contrato de mandato, cada uno con sus datos y su participación. La participación define, por ejemplo, el cupo de créditos de cada uno y debe sumar 100 %. Al guardar, todo se actualiza también en el mandato.') . '</p>'
      . $html
      . ($single ? '' : '<div class="cs-percent-bar"><span data-cs-percent-sum></span><button type="button" class="cs-secondary" data-cs-split>Repartir en partes iguales</button></div>')
      . '</fieldset>';
  }

  /**
   * Al editar se marcan los estados y observaciones guardados; un ítem que la hoja tenía vacío (hojas anteriores a la
   * lista) no es obligatorio. Los ítems que son documentos llevan su casilla para adjuntar (los del propietario, una por
   * propietario) y al final van los documentos adicionales.
   * @param list<string> $states @param array<string,mixed> $values @param list<array<string,mixed>> $owners
   * @param array{config:array{api:string,nonce:string,upload:string,delete:string,scope:array<string,int>},items:list<array<string,mixed>>}|null $documents
   */
  private function checklist(array $states, array $values, bool $creating, array $owners = [], ?array $documents = null): string
  {
    $states = $states !== [] ? $states : ['Cumple', 'No cumple', 'No aplica'];
    $files = ClosingSheetDocumentsView::byItem($documents['items'] ?? []);
    $owners = $owners !== [] ? $owners : [['index' => 1, 'name' => (string) ($values['propietario'] ?? '')]];
    $html = '<p class="cs-note">Marca el estado de cada documento o requisito. La observación es opcional.'
      . ($documents !== null ? ' Adjunta el archivo de cada documento (PDF, foto, Word o Excel, máximo 10 MB). No es obligatorio, pero se avisa si un documento queda en «Cumple» sin archivo.' : '') . '</p>';
    foreach (ClosingSheetForm::CHECKLIST as $notesColumn => [$group, $when, $items]) {
      $rows = '';
      foreach ($items as [$column, $text]) {
        $state = $creating ? '' : (string) ($values[$column] ?? '');
        $required = $creating || $state !== '';
        $slots = '';
        if ($documents !== null && isset(ClosingSheetForm::DOCUMENT_ITEMS[$column])) {
          if ($notesColumn === ClosingSheetForm::OWNER_DOCUMENTS) {
            foreach ($owners as $owner) {
              $index = max(1, (int) ($owner['index'] ?? 1));
              $slots .= ClosingSheetDocumentsView::slot($column, $index, ClosingSheetDocuments::personLabel($index, (string) ($owner['name'] ?? '')), $files[$column . '|' . $index] ?? [], true, $column);
            }
          } else {
            $slots = ClosingSheetDocumentsView::slot($column, 0, '', $files[$column . '|0'] ?? [], true, $column);
          }
          $slots = '<div class="cd-slots">' . $slots . '</div>';
        }
        $rows .= '<div class="cs-check-item' . ($slots !== '' ? ' has-docs' : '') . '"><p>' . $this->e($text) . '</p>'
          . '<select class="cs-input" name="' . $this->e($column) . '"' . ($required ? ' data-cs-required' : '') . ' aria-label="Estado"><option value="">Estado…</option>' . $this->optionTags(array_combine($states, $states), $state) . '</select>'
          . '<textarea class="cs-input" rows="2" name="notas[' . $this->e($column) . ']" placeholder="Observación (opcional)">' . $this->e($values['__notes'][$column] ?? '') . '</textarea>' . $slots . '</div>';
      }
      $html .= '<fieldset class="cs-section"' . ($when !== null ? ' data-cs-when="' . $this->e(json_encode($when, JSON_UNESCAPED_UNICODE)) . '"' : '') . '><legend>' . $this->e($group) . '</legend>' . $rows . '</fieldset>';
    }
    if ($documents === null) {
      return $html;
    }
    $html .= '<fieldset class="cs-section"><legend>Documentos adicionales</legend><p class="cs-note">Otros soportes del negocio que no están en la lista (opcional).</p>'
      . ClosingSheetDocumentsView::slot(ClosingSheetForm::EXTRA_DOCUMENTS, 0, '', $files[ClosingSheetForm::EXTRA_DOCUMENTS . '|0'] ?? [], true) . '</fieldset>'
      . '<div class="cd-pending" data-cd-pending hidden role="alert"><p><strong>Hay documentos en «Cumple» sin archivo:</strong></p><ul data-cd-pending-list></ul>'
      . '<p>Puedes adjuntarlos ahora o guardar así y subirlos después desde la hoja o la carpeta del contrato.</p>'
      . '<div class="cs-actions"><button type="button" class="cs-secondary" data-cd-pending-go>Revisar</button><button type="button" class="cs-primary" data-cd-pending-save>Guardar sin esos archivos</button></div></div>';
    return '<div class="cd-root"' . ClosingSheetDocumentsView::rootAttributes($documents['config']) . '>' . $html . '</div>';
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
