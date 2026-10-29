<?php
/**
 * Plugin Name: SKC Copropietarios
 * Description: Copropietarios del contrato de mandato y su porcentaje de participación. Shortcode [skc_copropietarios] para verlos y editarlos (hoja de cierre, contrato) y sincronización automática con la hoja de cierre y el contrato de arrendamiento.
 * Version: 1.0.0
 * Author: SKC SuCasa Inmobiliaria
 *
 * Instalación: copiar este archivo en wp-content/mu-plugins/ (se activa solo) o pegarlo en WPCode como snippet PHP
 * "en todo el sitio".
 *
 * Fuente de verdad: el contrato de mandato (propietarios, nombre_N, documento_N, id_propietario_nuevo_N y
 * porcentaje_N). Al guardar en el shortcode:
 *  - se valida que los porcentajes sumen 100 (cada uno > 0, máximo 2 decimales);
 *  - el propietario sin ficha se registra en Propietarios (o se enlaza a la que ya tenga su documento);
 *  - se escribe en el mandato y se copia a sus hojas de cierre (id_contrato) y contratos de arrendamiento
 *    (id_contrato_mandato): propietarios, id_propietario_2..6 y porcentaje_1..6;
 *  - queda en el historial del inmueble.
 * Además, cuando JetEngine crea una hoja de cierre o un contrato de arrendamiento, se les copian los copropietarios
 * de su mandato. Las columnas que todavía no existan se omiten.
 */

if (!defined('ABSPATH')) exit;

const SKC_COPROP_MAX = 6;

function skc_coprop_table(string $name): string
{
  global $wpdb;
  return $wpdb->prefix . 'jet_cct_' . $name;
}

/** Columnas existentes de la tabla (una consulta por petición). @return array<string,bool> */
function skc_coprop_columns(string $table): array
{
  global $wpdb;
  static $cache = [];
  if (!isset($cache[$table])) {
    $cache[$table] = [];
    foreach ((array) $wpdb->get_col("SHOW COLUMNS FROM `" . esc_sql($table) . "`") as $col) $cache[$table][(string) $col] = true;
  }
  return $cache[$table];
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function skc_coprop_filter(string $table, array $data): array
{
  return array_intersect_key($data, skc_coprop_columns($table));
}

/** Igual que el panel: sin espacios, puntos, comas ni guiones, y sin el dígito de verificación del NIT. */
function skc_coprop_doc($value): string
{
  $doc = strtoupper(preg_replace('/[\s.,]+/', '', trim((string) $value)) ?? '');
  $doc = preg_replace('/-\d$/', '', $doc) ?? $doc;
  return str_replace('-', '', $doc);
}

/** "33,34" o "33.34 %" → 33.34; vacío o inválido → null. */
function skc_coprop_number($value): ?float
{
  $text = str_replace(['%', ' '], '', trim((string) $value));
  if ($text === '') return null;
  $text = str_replace(',', '.', $text);
  return is_numeric($text) ? (float) $text : null;
}

function skc_coprop_mandate(int $id): ?array
{
  global $wpdb;
  if ($id <= 0) return null;
  $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . skc_coprop_table('contrato_mandato') . '` WHERE _ID = %d LIMIT 1', $id), ARRAY_A);
  return is_array($row) ? $row : null;
}

/**
 * Propietarios del mandato (1..propietarios). Nombre y documento son los del propietario (persona o empresa); el
 * representante legal va en empresa_N / nit_N.
 * @return list<array{index:int,name:string,document:string,id:string,percent:string,email:string,phone:string}>
 */
function skc_coprop_owners(array $mandate): array
{
  $count = max(1, min(SKC_COPROP_MAX, (int) ($mandate['propietarios'] ?? 1)));
  $owners = [];
  for ($i = 1; $i <= $count; $i++) {
    $name = trim((string) ($mandate['nombre_' . $i] ?? ''));
    $document = trim((string) ($mandate['documento_' . $i] ?? ''));
    if ($name === '' && $document === '') continue;
    $owners[] = [
      'index' => $i, 'name' => $name, 'document' => $document,
      'id' => trim((string) ($mandate['id_propietario_nuevo_' . $i] ?? '')) ?: ($i === 1 ? trim((string) ($mandate['id_propietario'] ?? '')) : ''),
      'percent' => trim((string) ($mandate['porcentaje_' . $i] ?? '')),
      'email' => trim((string) ($mandate['correo_' . $i] ?? '')),
      'phone' => trim((string) ($mandate['celular_' . $i] ?? '')),
    ];
  }
  return $owners;
}

/** id_empleado del funcionario con el correo del usuario actual (para cct_author_id). */
function skc_coprop_employee_id(): string
{
  global $wpdb;
  $user = wp_get_current_user();
  $table = skc_coprop_table('funcionarios');
  $cols = skc_coprop_columns($table);
  if (!$user || !$user->user_email || !isset($cols['id_empleado'])) return '';
  foreach (['correo', 'correo_dian', 'email'] as $col) {
    if (!isset($cols[$col])) continue;
    $id = $wpdb->get_var($wpdb->prepare("SELECT id_empleado FROM `{$table}` WHERE LOWER(TRIM(`{$col}`)) = %s LIMIT 1", strtolower($user->user_email)));
    if ($id) return (string) $id;
  }
  return '';
}

/** Ficha del propietario: la del mandato si existe, la que tenga su documento, o una nueva. */
function skc_coprop_ensure_profile(array $owner, array $mandate, string $author): string
{
  global $wpdb;
  $table = skc_coprop_table('propietarios');
  $cols = skc_coprop_columns($table);
  if ($cols === []) return $owner['id'];
  if ($owner['id'] !== '' && $wpdb->get_var($wpdb->prepare("SELECT _ID FROM `{$table}` WHERE _ID = %d", (int) $owner['id']))) return $owner['id'];
  $doc = skc_coprop_doc($owner['document']);
  if (strlen($doc) >= 4 && isset($cols['documento'])) {
    foreach ((array) $wpdb->get_results("SELECT _ID, documento FROM `{$table}` ORDER BY _ID", ARRAY_A) as $row) {
      if (skc_coprop_doc($row['documento'] ?? '') === $doc) return (string) $row['_ID'];
    }
  }
  if ($owner['name'] === '') return '';
  $i = $owner['index'];
  $now = current_time('mysql');
  $row = skc_coprop_filter($table, [
    'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => $now, 'cct_modified' => $now,
    'nombre' => $owner['name'], 'documento' => $owner['document'], 'tipo_persona' => (string) ($mandate['tipo_' . $i] ?? ''),
    'tipo_documento' => (string) ($mandate['tipo_documento_' . $i] ?? ''), 'expedicion' => (string) ($mandate['lugar_' . $i] ?? ''),
    'nombre_juridico' => (string) ($mandate['empresa_' . $i] ?? ''), 'documento_juridico' => (string) ($mandate['nit_' . $i] ?? ''),
    'correo' => $owner['email'], 'celular' => $owner['phone'], 'indicativo' => (string) ($mandate['indicativo_' . $i] ?? ''),
    'direccion' => (string) ($mandate['direccion_' . $i] ?? ''),
  ]);
  if (!$wpdb->insert($table, array_map('strval', $row))) throw new RuntimeException('No se pudo registrar a ' . $owner['name'] . ' en Propietarios.');
  return (string) $wpdb->insert_id;
}

/** Columnas de copropietarios para la hoja de cierre y el contrato (desde el mandato). @return array<string,string> */
function skc_coprop_copy_columns(array $mandate): array
{
  $owners = skc_coprop_owners($mandate);
  $data = ['propietarios' => (string) count($owners)];
  for ($n = 1; $n <= SKC_COPROP_MAX; $n++) {
    if ($n > 1) $data['id_propietario_' . $n] = '';
    $data['porcentaje_' . $n] = '';
  }
  // Mismo número que en el mandato (1 = id_propietario principal, 2..6 = copropietarios): así el panel los empareja.
  foreach ($owners as $owner) {
    $n = $owner['index'];
    if ($n > 1) $data['id_propietario_' . $n] = $owner['id'];
    $data['porcentaje_' . $n] = $owner['percent'];
  }
  return $data;
}

/** Copia los copropietarios del mandato a sus hojas de cierre y contratos de arrendamiento. @return int filas actualizadas */
function skc_coprop_sync(array $mandate, ?string $onlyTable = null, ?int $onlyId = null): int
{
  global $wpdb;
  $copy = skc_coprop_copy_columns($mandate);
  $updated = 0;
  foreach (['cierres' => 'id_contrato', 'contratos_arrendamiento' => 'id_contrato_mandato'] as $name => $link) {
    $table = skc_coprop_table($name);
    if ($onlyTable !== null && $onlyTable !== $name) continue;
    $cols = skc_coprop_columns($table);
    $data = skc_coprop_filter($table, $copy);
    if ($data === [] || !isset($cols[$link])) continue;
    if ($onlyId !== null) {
      $updated += (int) $wpdb->update($table, $data, ['_ID' => $onlyId]);
      continue;
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT _ID FROM `{$table}` WHERE TRIM(`{$link}`) = %s", (string) $mandate['_ID']));
    foreach ($ids as $id) $updated += (int) $wpdb->update($table, $data, ['_ID' => (int) $id]);
  }
  return $updated;
}

/** Valida porcentajes del formulario. @param array<int,string> $input índice => texto @return array<int,string> índice => "33.34" */
function skc_coprop_validate(array $owners, array $input): array
{
  if (count($owners) === 1) return [$owners[0]['index'] => '100'];
  $out = [];
  $sum = 0.0;
  foreach ($owners as $owner) {
    $text = str_replace(['%', ' '], '', trim((string) ($input[$owner['index']] ?? '')));
    $value = skc_coprop_number($text);
    if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $text) || $value === null || $value <= 0) {
      throw new InvalidArgumentException('Escribe el porcentaje de ' . ($owner['name'] ?: 'el propietario #' . $owner['index']) . ': un número mayor que 0 con máximo 2 decimales.');
    }
    $sum += $value;
    $out[$owner['index']] = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
  }
  if (abs($sum - 100) > 0.001) throw new InvalidArgumentException('Los porcentajes suman ' . rtrim(rtrim(number_format($sum, 2, ',', '.'), '0'), ',') . ' %: deben sumar 100 %.');
  return $out;
}

/** Mandato del contexto: atributo, ?id_contrato (hoja de cierre), ?id_cierre, ?id_contrato_mandato o ?contrato_arrendamiento. */
function skc_coprop_context_mandate(array $atts): int
{
  global $wpdb;
  $id = absint($atts['mandato'] ?? 0) ?: absint($_GET['id_contrato'] ?? 0) ?: absint($_GET['id_contrato_mandato'] ?? 0) ?: absint($_GET['id_mandato'] ?? 0);
  if ($id) return $id;
  if ($closing = absint($atts['cierre'] ?? 0) ?: absint($_GET['id_cierre'] ?? 0)) {
    return absint($wpdb->get_var($wpdb->prepare('SELECT id_contrato FROM `' . skc_coprop_table('cierres') . '` WHERE _ID = %d', $closing)));
  }
  if ($contract = absint($atts['contrato'] ?? 0) ?: absint($_GET['id_contrato_arrendamiento'] ?? 0)) {
    return absint($wpdb->get_var($wpdb->prepare('SELECT id_contrato_mandato FROM `' . skc_coprop_table('contratos_arrendamiento') . '` WHERE _ID = %d', $contract)));
  }
  return 0;
}

/* ============================== Shortcode ============================== */

add_shortcode('skc_copropietarios', function ($atts) {
  $atts = shortcode_atts(['mandato' => '', 'cierre' => '', 'contrato' => '', 'editable' => '1'], (array) $atts, 'skc_copropietarios');
  if (!is_user_logged_in()) return '';
  $mandateId = skc_coprop_context_mandate($atts);
  if (!$mandateId) return '';
  $mandate = skc_coprop_mandate($mandateId);
  if (!$mandate) return '<p class="skc-coprop-warn">No se encontró el contrato de mandato #' . esc_html($mandateId) . '.</p>';
  $owners = skc_coprop_owners($mandate);
  $editable = $atts['editable'] !== '0' && current_user_can('edit_posts');
  $hasColumn = isset(skc_coprop_columns(skc_coprop_table('contrato_mandato'))['porcentaje_1']);
  $rows = '';
  foreach ($owners as $owner) {
    $value = $owner['percent'] !== '' ? str_replace('.', ',', $owner['percent']) : (count($owners) === 1 ? '100' : '');
    $rows .= '<tr><td>' . (int) $owner['index'] . '</td><td>' . esc_html($owner['name'] ?: 'Sin nombre') . '</td><td>' . esc_html($owner['document'] ?: '-') . '</td>'
      . '<td>' . ($owner['id'] !== '' ? 'Ficha #' . esc_html($owner['id']) : '<span class="skc-coprop-bad">Sin ficha</span>') . '</td>'
      . '<td class="skc-coprop-num">' . ($editable && count($owners) > 1
        ? '<input type="text" inputmode="decimal" name="porcentaje[' . (int) $owner['index'] . ']" value="' . esc_attr($value) . '" aria-label="Porcentaje de ' . esc_attr($owner['name']) . '"> %'
        : esc_html($value !== '' ? $value . ' %' : 'Sin %')) . '</td></tr>';
  }
  $uid = 'skc-coprop-' . $mandateId . '-' . wp_rand(1000, 9999);
  ob_start(); ?>
  <div class="skc-coprop" id="<?= esc_attr($uid) ?>" data-mandato="<?= (int) $mandateId ?>">
    <style>
      .skc-coprop{margin:12px 0;border:1px solid #e2e8f0;border-radius:10px;padding:12px;font-size:13px;background:#fff;color:#0f172a}
      .skc-coprop table{width:100%;border-collapse:collapse;margin-top:8px}
      .skc-coprop th,.skc-coprop td{padding:6px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:middle}
      .skc-coprop-num{text-align:right;white-space:nowrap}
      .skc-coprop-num input{width:80px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;text-align:right}
      .skc-coprop-bad{color:#be123c;font-weight:700}
      .skc-coprop-warn,.skc-coprop-msg{margin:8px 0 0;padding:8px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e}
      .skc-coprop-msg.is-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
      .skc-coprop-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}
      .skc-coprop-actions button{padding:6px 12px;border-radius:8px;border:1px solid #0f2a52;background:#0f2a52;color:#fff;cursor:pointer}
      .skc-coprop-actions button.is-secondary{background:#fff;color:#0f2a52}
      @media(max-width:640px){.skc-coprop th:nth-child(3),.skc-coprop td:nth-child(3){display:none}}
    </style>
    <strong>Propietarios del mandato #<?= (int) $mandateId ?></strong>
    <span data-suma style="margin-left:8px"></span>
    <table><thead><tr><th>#</th><th>Nombre</th><th>Documento</th><th>Propietarios</th><th class="skc-coprop-num">Participación</th></tr></thead><tbody><?= $rows ?></tbody></table>
    <?php if (!$hasColumn): ?><p class="skc-coprop-warn">Falta crear las columnas porcentaje_1 … porcentaje_6 en el contrato de mandato.</p><?php endif; ?>
    <?php if ($editable): ?>
      <div class="skc-coprop-actions">
        <?php if (count($owners) > 1): ?><button type="button" class="is-secondary" data-repartir>Repartir en partes iguales</button><?php endif; ?>
        <button type="button" data-guardar>Guardar participación</button>
        <small>Se guarda en el mandato, sus hojas de cierre y contratos, y registra en Propietarios a quien no tenga ficha.</small>
      </div>
      <p class="skc-coprop-msg" data-msg hidden></p>
    <?php endif; ?>
  </div>
  <?php if ($editable): ?>
  <script>
  (function () {
    var box = document.getElementById(<?= wp_json_encode($uid) ?>);
    if (!box) return;
    var inputs = function () { return Array.prototype.slice.call(box.querySelectorAll('input[name^="porcentaje["]')); };
    var num = function (t) { t = String(t || '').replace(/[%\s]/g, '').replace(',', '.'); return t === '' ? null : Number(t); };
    var fmt = function (v) { return String(Math.round(v * 100) / 100).replace('.', ','); };
    function suma() {
      var el = box.querySelector('[data-suma]'); var list = inputs(); if (!list.length) { el.textContent = ''; return; }
      var vals = list.map(function (i) { return num(i.value); });
      var s = vals.reduce(function (a, v) { return a + (v || 0); }, 0);
      var ok = vals.every(function (v) { return v !== null && v > 0; }) && Math.abs(s - 100) < 0.001;
      el.textContent = vals.every(function (v) { return v === null; }) ? 'Sin porcentaje registrado' : 'Suma ' + fmt(s) + ' %' + (ok ? ' ✓' : ' (debe sumar 100 %)');
      el.style.color = ok ? '#047857' : '#be123c'; el.style.fontWeight = '700';
    }
    inputs().forEach(function (i) { i.addEventListener('input', suma); });
    var rep = box.querySelector('[data-repartir]');
    if (rep) rep.addEventListener('click', function () {
      var list = inputs(), n = list.length, base = Math.floor(10000 / n) / 100;
      list.forEach(function (i, k) { i.value = fmt(k === 0 ? Math.round((100 - base * (n - 1)) * 100) / 100 : base); }); suma();
    });
    box.querySelector('[data-guardar]').addEventListener('click', function () {
      var btn = this, msg = box.querySelector('[data-msg]');
      var body = new FormData();
      body.append('action', 'skc_copropietarios_guardar');
      body.append('_ajax_nonce', <?= wp_json_encode(wp_create_nonce('skc_copropietarios')) ?>);
      body.append('mandato', box.getAttribute('data-mandato'));
      inputs().forEach(function (i) { body.append(i.name, i.value); });
      btn.disabled = true; msg.hidden = false; msg.className = 'skc-coprop-msg'; msg.textContent = 'Guardando…';
      fetch(<?= wp_json_encode(admin_url('admin-ajax.php')) ?>, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          btn.disabled = false;
          msg.className = 'skc-coprop-msg' + (r && r.success ? ' is-ok' : '');
          msg.textContent = (r && r.data && r.data.message) || 'No se pudo guardar.';
          if (r && r.success && r.data.reload) setTimeout(function () { location.reload(); }, 900);
        })
        .catch(function () { btn.disabled = false; msg.textContent = 'No se pudo guardar. Intenta de nuevo.'; });
    });
    suma();
  })();
  </script>
  <?php endif;
  return (string) ob_get_clean();
});

/* ============================ Guardar (AJAX) ============================ */

add_action('wp_ajax_skc_copropietarios_guardar', function () {
  global $wpdb;
  check_ajax_referer('skc_copropietarios');
  if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'No tienes permiso para editar propietarios.'], 403);
  $mandateId = absint($_POST['mandato'] ?? 0);
  $mandate = skc_coprop_mandate($mandateId);
  if (!$mandate) wp_send_json_error(['message' => 'No se encontró el contrato de mandato.'], 404);
  $owners = skc_coprop_owners($mandate);
  $mandateTable = skc_coprop_table('contrato_mandato');
  if (!isset(skc_coprop_columns($mandateTable)['porcentaje_1'])) wp_send_json_error(['message' => 'Falta crear las columnas porcentaje_1 … porcentaje_6 en el contrato de mandato.'], 400);
  $input = array_map('sanitize_text_field', (array) wp_unslash($_POST['porcentaje'] ?? []));
  $synced = 0;
  $registered = [];
  try {
    $percents = skc_coprop_validate($owners, $input);
    $wpdb->query('START TRANSACTION');
    $author = skc_coprop_employee_id();

    $update = ['cct_modified' => current_time('mysql')];
    for ($n = 1; $n <= SKC_COPROP_MAX; $n++) $update['porcentaje_' . $n] = '';
    foreach ($owners as $owner) {
      $update['porcentaje_' . $owner['index']] = $percents[$owner['index']];
      $id = skc_coprop_ensure_profile($owner, $mandate, $author);
      if ($id !== '' && $id !== $owner['id']) {
        $update['id_propietario_nuevo_' . $owner['index']] = $id;
        if ($owner['index'] === 1 && trim((string) ($mandate['id_propietario'] ?? '')) === '') $update['id_propietario'] = $id;
        $registered[] = $owner['name'];
      }
    }
    $update = skc_coprop_filter($mandateTable, $update);
    if ($wpdb->update($mandateTable, $update, ['_ID' => $mandateId]) === false) throw new RuntimeException('No se pudo actualizar el mandato: ' . $wpdb->last_error);
    $synced = skc_coprop_sync(skc_coprop_mandate($mandateId) ?? $mandate);
    // Trazabilidad en el historial del inmueble.
    $history = skc_coprop_table('historial_del_inmueble');
    $detail = 'Participación de los propietarios del mandato #' . $mandateId . ': ' . implode(', ', array_map(fn($o) => ($o['name'] ?: '#' . $o['index']) . ' ' . str_replace('.', ',', $percents[$o['index']]) . ' %', $owners)) . '.';
    $user = wp_get_current_user();
    $wpdb->insert($history, skc_coprop_filter($history, [
      'cct_status' => 'publish', 'cct_author_id' => $author, 'cct_created' => current_time('mysql'), 'cct_modified' => current_time('mysql'),
      'id_inmueble' => (string) ($mandate['id_inmueble'] ?? ''), 'id_empleado' => $author, 'funcionario' => $user->display_name ?? '', 'nombre_empleado' => $user->display_name ?? '',
      'fecha' => (string) time(), 'tipo_reporte' => 'Contractual', 'observacion' => $detail,
    ]));
    $wpdb->query('COMMIT');
  } catch (InvalidArgumentException $e) {
    $wpdb->query('ROLLBACK');
    wp_send_json_error(['message' => $e->getMessage()], 422);
  } catch (Throwable $e) {
    $wpdb->query('ROLLBACK');
    wp_send_json_error(['message' => $e->getMessage()], 500);
  }
  wp_send_json_success([
    'message' => 'Participación guardada' . ($synced ? ' (actualizadas ' . $synced . ' hojas de cierre / contratos)' : '') . '.' . ($registered ? ' Registrados en Propietarios: ' . implode(', ', $registered) . '.' : ''),
    'reload' => $registered !== [],
  ]);
});

/* ================= Copia automática al crear cierre / contrato ================= */

// JetEngine avisa al crear o editar un registro de un CCT. Si el registro está enlazado a un mandato, se le copian
// los copropietarios. Si esta versión de JetEngine no dispara el aviso, basta con guardar en el shortcode.
foreach (['cierres' => 'id_contrato', 'contratos_arrendamiento' => 'id_contrato_mandato'] as $skcCoPropType => $skcCoPropLink) {
  $skcCoPropHandler = function ($item, $itemId = 0) use ($skcCoPropType, $skcCoPropLink) {
    global $wpdb;
    $itemId = absint($itemId ?: (is_array($item) ? ($item['_ID'] ?? 0) : 0));
    if (!$itemId) return;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . skc_coprop_table($skcCoPropType) . '` WHERE _ID = %d', $itemId), ARRAY_A);
    $mandate = is_array($row) ? skc_coprop_mandate(absint($row[$skcCoPropLink] ?? 0)) : null;
    // Solo si el registro aún no tiene copropietarios: no se pisa lo que ya se guardó.
    if (!$mandate || trim((string) ($row['propietarios'] ?? '')) !== '') return;
    skc_coprop_sync($mandate, $skcCoPropType, $itemId);
  };
  add_action('jet-engine/custom-content-types/created-item/' . $skcCoPropType, $skcCoPropHandler, 20, 2);
  add_action('jet-engine/custom-content-types/updated-item/' . $skcCoPropType, $skcCoPropHandler, 20, 2);
}
unset($skcCoPropType, $skcCoPropLink, $skcCoPropHandler);
