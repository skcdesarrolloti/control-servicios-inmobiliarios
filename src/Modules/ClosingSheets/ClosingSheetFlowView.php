<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Modules\Pending\PublicServicesDocument;

/**
 * Páginas del flujo de la hoja de cierre: revisión de servicios antes de la ocupación
 * (revision-servicios-cierre.php), la hoja en solo lectura para aprobarla o no (hoja-cierre-ver.php), el listado
 * (hojas-cierre.php) y la configuración de avisos (Configuración › Hoja de cierre).
 */
final class ClosingSheetFlowView
{
  private const STATE_TONES = [
    ClosingSheetService::STATUS_NEW => 'warn', ClosingSheetWorkflow::STATUS_REVIEWED => 'info',
    ClosingSheetWorkflow::APPROVED => 'good', ClosingSheetWorkflow::REJECTED => 'bad', 'Realizada' => 'done',
  ];

  /**
   * Estructura común (barra, encabezado y contenido).
   * @param array{base_url:string,logo:string,favicon:string,css:list<string>,js:list<string>,user:string,session:bool} $page
   * @param array<string,string> $facts
   */
  public static function page(array $page, string $title, string $eyebrow, string $icon, array $facts, string $body, string $state = '', bool $hero = true): string
  {
    $h = [self::class, 'e'];
    $firstName = trim((string) strtok(trim($page['user']), ' ')) ?: $page['user'];
    $facts = array_filter($facts, static fn($value): bool => trim((string) $value, ' #') !== '');
    $css = implode('', array_map(static fn(string $href): string => '<link rel="stylesheet" href="' . $h($href) . '">', $page['css']));
    $js = implode('', array_map(static fn(string $src): string => '<script src="' . $h($src) . '" defer></script>', $page['js']));
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<title>' . $h($title) . ' · SKC SuCasa Inmobiliaria</title>'
      . ($page['favicon'] !== '' ? '<link rel="icon" href="' . $h($page['favicon']) . '" sizes="32x32">' : '')
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">'
      . $css . $js . '</head><body class="scm-review-page cs-page csf-page' . (!empty($page['embed']) ? ' is-embed' : '') . '">'
      . '<header class="rp-topbar"><a class="rp-brand" href="' . $h($page['session'] ? $page['base_url'] . '/index.php' : '#') . '">'
      . ($page['logo'] !== '' ? '<img src="' . $h($page['logo']) . '" alt="SKC SuCasa Inmobiliaria">' : '') . '<span>SKC SuCasa Inmobiliaria</span></a>'
      . ($firstName !== '' ? '<div class="rp-user"><span class="rp-avatar" aria-hidden="true">' . $h(mb_strtoupper(mb_substr($firstName, 0, 1, 'UTF-8'), 'UTF-8')) . '</span><span class="rp-user-name">Hola, <strong>' . $h($firstName) . '</strong></span></div>' : '')
      . '</header><main class="scm-review-page-shell cs-shell">'
      . ($hero ? '<section class="rp-hero"><span class="rp-hero-icon material-symbols-outlined" aria-hidden="true">' . $h($icon) . '</span><div class="rp-hero-text">'
        . '<p class="rp-eyebrow">' . $h($eyebrow) . '</p><h1>' . $h($title) . ($state !== '' ? ' ' . self::badge($state) : '') . '</h1>'
        . ($facts !== [] ? '<dl class="rp-facts">' . implode('', array_map(static fn($term, $value): string => '<div><dt>' . $h($term) . '</dt><dd>' . $h($value) . '</dd></div>', array_keys($facts), $facts)) . '</dl>' : '')
        . '</div></section>' : '')
      . $body . '</main></body></html>';
  }

  public static function badge(string $state): string
  {
    return '<span class="csf-badge is-' . (self::STATE_TONES[$state] ?? 'info') . '">' . self::e($state !== '' ? $state : 'Sin estado') . '</span>';
  }

  public static function message(string $title, string $text, string $icon = 'info', array $links = []): string
  {
    return '<section class="rp-card cs-done csf-message"><span class="material-symbols-outlined cs-done-icon is-' . self::e($icon) . '" aria-hidden="true">' . self::e($icon) . '</span>'
      . '<h2>' . self::e($title) . '</h2><p>' . self::e($text) . '</p>'
      . ($links !== [] ? '<div class="cs-actions">' . implode('', array_map(static fn(array $link): string => '<a class="' . ($link['primary'] ?? false ? 'cs-primary' : 'cs-secondary') . '" href="' . self::e($link['url']) . '">' . self::e($link['label']) . '</a>', $links)) . '</div>' : '')
      . '</section>';
  }

  /** Datos fijos de la hoja que se muestran arriba. @return array<string,string> */
  public static function facts(array $closing): array
  {
    $date = (int) ($closing['fecha'] ?? 0);
    return [
      'Inmueble' => '#' . trim((string) ($closing['id_inmueble'] ?? '')),
      'Dirección' => trim((string) ($closing['direccion'] ?? '')),
      'Arrendatario' => trim((string) ($closing['arrendatario'] ?? '')),
      'Hizo la hoja' => trim((string) ($closing['creador'] ?? '')),
      'Fecha' => $date > 0 ? self::date($date) : '',
    ];
  }

  // ---------------------------------------------------------------- revisión

  /**
   * @param array{closing:array<string,mixed>,services:array<string,array<string,mixed>>,results:list<string>,last_review:array<string,mixed>|null} $form
   * @param array<string,string> $hidden firma o nonce @param array<string,mixed> $actor
   */
  public static function reviewBody(array $form, array $hidden, array $actor, string $viewUrl): string
  {
    $closing = $form['closing'];
    $resultOptions = static fn(string $selected) => '<option value="">Selecciona…</option>' . implode('', array_map(
      static fn(string $value): string => '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . self::e(ClosingSheetWorkflow::statusLabel($value)) . '</option>', $form['results']));
    $blocks = '';
    foreach ($form['services'] as $key => $service) {
      $id = 'csf_' . $key;
      $blocks .= '<fieldset class="cs-section csf-service" data-csf-service' . ($service['checked'] ? '' : ' data-off') . '><legend>' . self::e($service['label']) . '</legend>'
        . '<label class="cs-check csf-toggle"><input type="checkbox" name="revisar[' . self::e($key) . ']" value="1" data-csf-toggle' . ($service['checked'] ? ' checked' : '') . '> <span>Revisar este servicio</span></label>'
        . '<div class="cs-grid" data-csf-fields>'
        . self::input($id . '_cuenta', 'cuenta_' . $key, $service['account_label'], $service['account'], 'text', 'Se actualiza en la hoja y en el mandato.')
        . self::input($id . '_medidor', 'medidor_' . $key, '# Medidor', $service['meter'])
        . self::input($id . '_corte', 'corte_' . $key, 'Fecha de corte', $service['cutoff'], 'date')
        . '<div class="cs-field"><label class="cs-label" for="' . $id . '_resultado">Resultado en tiempo <span class="cs-req" aria-hidden="true">*</span></label><select class="cs-input" id="' . $id . '_resultado" name="resultado_' . self::e($key) . '" data-csf-required data-csf-status>' . $resultOptions((string) $service['status']) . '</select></div>'
        . self::input($id . '_valor', 'valor_' . $key, 'Resultado en valores', $service['value'], 'text', 'Al día: 0. En mora: el valor adeudado en pesos.')
        . '</div></fieldset>';
    }
    $last = $form['last_review'];
    $previous = $last !== null
      ? '<p class="cs-note csf-note"><span class="material-symbols-outlined" aria-hidden="true">history</span> Ya hay una revisión de esta hoja (#' . (int) $last['_ID'] . ', ' . self::e(self::date((int) ($last['fecha'] ?? 0))) . ', ' . self::e($last['realizado_por'] ?? '') . '). Si registras otra, la hoja toma los nuevos resultados.</p>'
      : '';
    return '<form class="cs-form" data-csf-form data-csf-draft="cierre:revision:' . (int) $closing['_ID'] . '" method="post" novalidate>'
      . self::hidden($hidden + ['accion' => 'revision'])
      . '<section class="cs-section csf-intro"><p>Revisa cada servicio del inmueble antes de entregarlo. Al guardar, la hoja de cierre pasa a <strong>' . self::e(ClosingSheetWorkflow::STATUS_REVIEWED) . '</strong>, se avisa a quienes la aprueban y se envía al propietario el resultado (según la configuración).</p>'
      . '<p class="csf-actor"><span class="material-symbols-outlined" aria-hidden="true">badge</span> Queda registrada a nombre de <strong>' . self::e($actor['name']) . '</strong> (ID empleado ' . self::e($actor['employee_id']) . ').</p>' . $previous . '</section>'
      . $blocks
      . '<p class="cs-error" data-csf-error role="alert" hidden></p>'
      . '<div class="cs-actions"><a class="cs-secondary" href="' . self::e($viewUrl) . '"><span class="material-symbols-outlined" aria-hidden="true">visibility</span> Ver hoja de cierre</a>'
      . '<button type="submit" class="cs-primary"><span class="material-symbols-outlined" aria-hidden="true">fact_check</span> Registrar revisión</button></div>'
      . '</form>' . self::done();
  }

  // ---------------------------------------------------------------- hoja en solo lectura

  /** Datos del negocio que van en el resumen de arriba (no se repiten en su sección): columna => [etiqueta, en pesos]. */
  private const SUMMARY = [
    'valor_canon' => ['Canon', true], 'valor_administracion' => ['Administración', true], 'incluye_iva' => ['¿Canon con IVA?', false],
    'iva_total_precio' => ['IVA del canon', true], 'meses_contrato' => ['Meses de contrato', false], 'tasa_administracion' => ['Comisión (%)', false],
    'porcentaje_incremento' => ['Incremento pactado', false], 'derechos_inmobiliarios' => ['Derechos inmobiliarios', false], 'dias_gracia' => ['Días de gracia', false],
  ];

  /**
   * La hoja como documento (membrete, resumen, secciones, revisión, lista de chequeo y firmas), con las acciones afuera.
   * @param array{closing:array<string,mixed>,values:array<string,mixed>,owners:list<array<string,mixed>>,options:array<string,mixed>,review:array<string,mixed>|null,results:list<array<string,mixed>>,signers:list<array<string,mixed>>,company:array<string,string>} $sheet
   * @param array{view:bool,review:bool,respond:bool,edit:bool} $can @param array<string,string> $hidden @param array<string,string> $links review, edit y back (listado, solo con sesión)
   */
  public static function sheetBody(array $sheet, array $can, array $hidden, array $links, string $logo = ''): string
  {
    $closing = $sheet['closing'];
    $state = ClosingSheetWorkflow::state($closing);
    $actions = '';
    if (($links['back'] ?? '') !== '') {
      $actions .= '<a class="cs-secondary" href="' . self::e($links['back']) . '"><span class="material-symbols-outlined" aria-hidden="true">list</span> Hojas de cierre</a>';
    }
    if ($can['review'] && ClosingSheetWorkflow::canReview($closing)) {
      $actions .= '<a class="cs-primary" href="' . self::e($links['review']) . '"><span class="material-symbols-outlined" aria-hidden="true">fact_check</span> ' . ($sheet['review'] !== null ? 'Repetir revisión de servicios' : 'Hacer revisión de servicios') . '</a>';
    }
    if ($can['edit'] && !in_array($state, [ClosingSheetWorkflow::APPROVED, 'Realizada'], true)) {
      $actions .= '<a class="cs-secondary" href="' . self::e($links['edit']) . '"><span class="material-symbols-outlined" aria-hidden="true">edit</span> Editar hoja</a>';
    }
    $actions .= '<button type="button" class="cs-secondary" data-csf-print><span class="material-symbols-outlined" aria-hidden="true">print</span> Imprimir</button>';

    $html = '<div class="cs-actions csf-toolbar">' . $actions . '</div>';
    if ($can['respond'] && ClosingSheetWorkflow::canRespond($closing)) {
      $html .= self::responseForm($hidden, $state);
    } elseif ($can['respond'] && $state === ClosingSheetService::STATUS_NEW) {
      $html .= '<p class="cs-note csf-note csf-pending"><span class="material-symbols-outlined" aria-hidden="true">schedule</span> Podrás aprobarla o no cuando se registre la revisión de servicios públicos.</p>';
    }

    $values = $sheet['values'];
    $summary = [];
    foreach (self::SUMMARY as $column => [$label, $money]) {
      $value = $values[$column] ?? '';
      $summary[] = [$label, self::amount(is_array($value) ? '' : (string) $value, $money)];
    }
    $doc = self::docHeader($closing, $sheet['company'], $logo)
      . self::responseBlock($closing)
      . self::keyValues('Resumen del negocio', $summary, 'csf-summary')
      . self::keyValues('Datos del estudio y entrega', [
        ['Fecha posible de entrega', self::anyDate($closing['fecha_posible_entrega'] ?? '')], ['Aseguradora', (string) ($closing['aseguradora'] ?? '')],
        ['Número de solicitud', (string) ($closing['numero_solicitud'] ?? '')], ['Medio', (string) ($closing['medio'] ?? '')],
        ['Ubicación de llaves', (string) ($closing['ubicacion_llaves'] ?? '')],
        ['Encargado de llaves', trim((string) ($closing['persona_llaves'] ?? '') . ' ' . (string) ($closing['contacto_llaves'] ?? ''))],
      ]);
    foreach (ClosingSheetForm::SECTIONS as [$title, $fields]) {
      if ($title === 'Datos del negocio') {
        $fields = array_values(array_filter($fields, static fn(array $field): bool => !isset(self::SUMMARY[$field['name']])));
        $title = 'Inmueble y negocio';
      }
      $doc .= self::readSection($title, $fields, $values, $sheet['options']);
      if ($title === 'Propietario') {
        $doc .= self::ownersBlock($sheet['owners']);
      }
    }
    $doc .= self::reviewBlock($sheet) . self::checklistBlock($values) . self::signatures($sheet['signers']) . self::docFooter($sheet['company']);
    return $html . '<article class="csf-doc">' . $doc . '</article>'
      . '<dialog class="csf-dialog" data-csf-dialog><header><strong>Revisión de servicios públicos</strong><button type="button" class="cs-secondary" data-csf-dialog-close>Cerrar</button></header><iframe title="Revisión de servicios públicos" referrerpolicy="no-referrer"></iframe></dialog>'
      . self::done();
  }

  /** @param array<string,string> $company */
  private static function docHeader(array $closing, array $company, string $logo): string
  {
    $date = (int) ($closing['fecha'] ?? 0);
    $facts = [
      'Fecha' => $date > 0 ? self::date($date) : '', 'Inmueble' => '#' . trim((string) ($closing['id_inmueble'] ?? '')),
      'Dirección' => trim((string) ($closing['direccion'] ?? '')), 'Propietario' => trim((string) ($closing['propietario'] ?? '')),
      'Arrendatario' => trim((string) ($closing['arrendatario'] ?? '')), 'Mandato' => '#' . trim((string) ($closing['id_contrato'] ?? '')),
    ];
    $items = '';
    foreach ($facts as $term => $value) {
      if (trim($value, ' #') !== '') {
        $items .= '<div><dt>' . self::e($term) . '</dt><dd>' . self::e($value) . '</dd></div>';
      }
    }
    return '<header class="csf-doc-head"><div class="csf-doc-brand">' . ($logo !== '' ? '<img src="' . self::e($logo) . '" alt="SKC SuCasa Inmobiliaria">' : '')
      . '<div><strong>SKC SuCasa Inmobiliaria</strong><span>NIT ' . self::e($company['nit']) . '</span></div></div>'
      . '<div class="csf-doc-title"><p>Hoja de cierre de negocio</p><h1>#' . (int) $closing['_ID'] . '</h1>' . self::badge(ClosingSheetWorkflow::state($closing)) . '</div></header>'
      . '<dl class="csf-doc-facts">' . $items . '</dl>';
  }

  /** @param list<array{0:string,1:string}> $pairs */
  private static function keyValues(string $title, array $pairs, string $class = ''): string
  {
    $items = '';
    foreach ($pairs as [$label, $value]) {
      if (trim($value) !== '') {
        $items .= '<div class="csf-item"><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
      }
    }
    return $items === '' ? '' : '<section class="cs-section ' . $class . '"><h2>' . self::e($title) . '</h2><dl class="csf-grid">' . $items . '</dl></section>';
  }

  /** Cierre del documento: quién elaboró, verificó y autorizó, con su firma si la tiene en Funcionarios. @param list<array<string,mixed>> $signers */
  private static function signatures(array $signers): string
  {
    $html = '';
    foreach ($signers as $signer) {
      $done = trim((string) $signer['name']) !== '';
      $html .= '<div class="csf-sign' . ($done ? '' : ' is-pending') . '"><div class="csf-sign-line">'
        . ($done && $signer['signature'] !== '' ? '<img src="' . self::e($signer['signature']) . '" alt="Firma de ' . self::e($signer['name']) . '">' : '') . '</div>'
        . '<strong>' . self::e($done ? $signer['name'] : 'Pendiente') . '</strong>'
        . '<span>' . self::e($signer['action'] . ' · ' . ($done && $signer['cargo'] !== '' ? $signer['cargo'] : $signer['role'])) . '</span>'
        . ($done && (int) $signer['date'] > 0 ? '<small>' . self::e(self::date((int) $signer['date'])) . '</small>' : '') . '</div>';
    }
    return '<section class="cs-section csf-signatures"><h2>Realizado por</h2><div class="csf-sign-grid">' . $html . '</div></section>';
  }

  /** @param array<string,string> $company */
  private static function docFooter(array $company): string
  {
    $parts = array_filter(['SKC SuCasa Inmobiliaria', 'NIT ' . $company['nit'], $company['address'], $company['phone'] !== '' ? 'Tel. ' . $company['phone'] : '', $company['email']]);
    return '<footer class="csf-doc-foot">' . self::e(implode(' · ', $parts)) . '</footer>';
  }

  /** Valores en pesos con $ (los que traen texto, como "No aplica", quedan igual). */
  private static function amount(string $value, bool $money): string
  {
    $value = trim($value);
    if (!$money || $value === '' || preg_match('/^[\d.]+$/', $value) !== 1) {
      return $value;
    }
    return '$' . number_format((int) str_replace('.', '', $value), 0, ',', '.');
  }

  /** Fecha guardada como timestamp o como texto. */
  private static function anyDate(mixed $value): string
  {
    $value = trim((string) $value);
    if ($value === '' || $value === '0') {
      return '';
    }
    return ctype_digit($value) ? self::date((int) $value) : $value;
  }

  private static function responseBlock(array $closing): string
  {
    $state = ClosingSheetWorkflow::state($closing);
    if (!in_array($state, [ClosingSheetWorkflow::APPROVED, ClosingSheetWorkflow::REJECTED, 'Realizada'], true) || trim((string) ($closing['aprobador'] ?? '')) === '') {
      return '';
    }
    $note = trim((string) ($closing['observacion_respuesta'] ?? ''));
    $date = (int) ($closing['fecha_repuesta'] ?? 0);
    return '<section class="cs-section csf-response is-' . (self::STATE_TONES[$state] ?? 'info') . '"><h2>Respuesta</h2>'
      . '<p><strong>' . self::e($state === 'Realizada' ? ClosingSheetWorkflow::APPROVED : $state) . '</strong> por ' . self::e($closing['aprobador']) . ($date > 0 ? ' el ' . self::e(self::date($date)) : '') . '.</p>'
      . ($note !== '' ? '<p class="csf-quote">' . nl2br(self::e($note)) . '</p>' : '') . '</section>';
  }

  /** @param array<string,string> $hidden */
  private static function responseForm(array $hidden, string $state): string
  {
    return '<form class="cs-section csf-respond" id="responder" data-csf-form data-csf-confirm method="post" novalidate><h2>Responder la hoja de cierre</h2>'
      . self::hidden($hidden + ['accion' => 'respuesta'])
      . ($state === ClosingSheetWorkflow::REJECTED ? '<p class="cs-note">No se había aprobado; quien la hizo la corrigió o vuelve a ponerla a consideración.</p>' : '')
      . '<label class="cs-label" for="csf_observacion">Observación <span class="cs-help">(obligatoria si no se aprueba; la recibe quien hizo la hoja)</span></label>'
      . '<textarea class="cs-input" id="csf_observacion" name="observacion" rows="3" maxlength="2000"></textarea>'
      . '<p class="cs-error" data-csf-error role="alert" hidden></p>'
      . '<div class="cs-actions"><button type="submit" name="estado" value="' . self::e(ClosingSheetWorkflow::REJECTED) . '" class="cs-secondary csf-reject"><span class="material-symbols-outlined" aria-hidden="true">block</span> No aprobar</button>'
      . '<button type="submit" name="estado" value="' . self::e(ClosingSheetWorkflow::APPROVED) . '" class="cs-primary csf-approve"><span class="material-symbols-outlined" aria-hidden="true">verified</span> Aprobar</button></div></form>';
  }

  private static function reviewBlock(array $sheet): string
  {
    $review = $sheet['review'];
    $results = $sheet['results'];
    if ($review === null && $results === []) {
      return '<section class="cs-section"><h2>Revisión de servicios públicos</h2><p class="cs-note">Todavía no se ha hecho la revisión antes de la ocupación.</p></section>';
    }
    $rows = '';
    foreach ($results as $result) {
      $rows .= '<tr><td><strong>' . self::e($result['label']) . '</strong><small>' . self::e(trim($result['account_label'] . ' ' . $result['account'])) . ($result['meter'] !== '' ? ' · Medidor ' . self::e($result['meter']) : '') . '</small></td>'
        . '<td>' . self::e($result['reviewed'] > 0 ? self::date($result['reviewed']) : '—') . '</td>'
        . '<td>' . self::e($result['cutoff'] > 0 ? self::date($result['cutoff']) : '—') . '</td>'
        . '<td><span class="csf-result' . ($result['status'] === 'Al dia' ? ' is-good' : ' is-bad') . '">' . self::e(ClosingSheetWorkflow::statusLabel($result['status'])) . '</span></td>'
        . '<td>' . self::e(ClosingSheetWorkflow::money($result['value'])) . '</td></tr>';
    }
    $who = $review !== null ? 'Revisión #' . (int) $review['_ID'] . ' · ' . trim((string) ($review['realizado_por'] ?? '')) . ' · ' . self::date((int) ($review['fecha'] ?? 0)) : 'Verificó: ' . trim((string) ($sheet['closing']['verificador'] ?? ''));
    return '<section class="cs-section"><h2>Revisión de servicios públicos</h2><p class="cs-note">' . self::e($who) . '</p>'
      . '<div class="cs-table-wrap"><table class="cs-table"><thead><tr><th>Servicio</th><th>Revisado</th><th>Fecha de corte</th><th>Resultado</th><th>Valor</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
      . ($review !== null ? '<div class="cs-actions csf-left"><button type="button" class="cs-secondary" data-csf-preview="' . self::e(PublicServicesDocument::url((int) $review['_ID'])) . '"><span class="material-symbols-outlined" aria-hidden="true">description</span> Ver documento de la revisión</button></div>' : '')
      . '</section>';
  }

  /** @param list<array<string,mixed>> $fields @param array<string,mixed> $values @param array<string,mixed> $options */
  private static function readSection(string $title, array $fields, array $values, array $options): string
  {
    $items = '';
    foreach ($fields as $field) {
      if (!ClosingSheetService::visible($field, $values)) {
        continue;
      }
      $value = $values[$field['name']] ?? '';
      if (is_string($value) && preg_match('/^a:\d+:\{/', $value) === 1) {
        $value = @unserialize($value, ['allowed_classes' => false]) ?: [];
      }
      $shown = self::shown($field, $value, $options);
      if ($shown === '') {
        continue;
      }
      $wide = in_array($field['type'], ['textarea', 'repeater'], true);
      $items .= '<div class="csf-item' . ($wide ? ' is-wide' : '') . '"><dt>' . self::e($field['label']) . '</dt><dd>' . $shown . '</dd></div>';
    }
    return $items === '' ? '' : '<section class="cs-section"><h2>' . self::e($title) . '</h2><dl class="csf-grid">' . $items . '</dl></section>';
  }

  /** Valor listo para mostrar (HTML escapado). @param array<string,mixed> $field @param array<string,mixed> $options */
  private static function shown(array $field, mixed $value, array $options): string
  {
    if ($field['type'] === 'repeater') {
      $rows = array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
      $columns = ClosingSheetForm::REPEATERS[$field['name']] ?? [];
      if ($rows === [] || $columns === []) {
        return '';
      }
      return '<div class="cs-table-wrap"><table class="cs-table csf-mini"><thead><tr>' . implode('', array_map(static fn(array $c): string => '<th>' . self::e($c['label']) . '</th>', $columns)) . '</tr></thead><tbody>'
        . implode('', array_map(static fn(array $row): string => '<tr>' . implode('', array_map(static fn(array $c): string => '<td>' . self::e($row[$c['name']] ?? '') . '</td>', $columns)) . '</tr>', $rows)) . '</tbody></table></div>';
    }
    $labels = self::labels($field, $options);
    if (is_array($value)) {
      $parts = array_filter(array_map(static fn($one): string => trim((string) ($labels[(string) $one] ?? $one)), $value), static fn(string $one): bool => $one !== '');
      return self::e(implode(', ', $parts));
    }
    $text = trim((string) $value);
    if ($text === '') {
      return '';
    }
    $text = (string) ($labels[$text] ?? $text);
    return $field['type'] === 'textarea' ? nl2br(self::e($text)) : self::e($field['type'] === 'time' ? substr($text, 0, 5) : $text);
  }

  /** @param array<string,mixed> $field @param array<string,mixed> $options @return array<string,string> */
  private static function labels(array $field, array $options): array
  {
    $source = $field['options'] ?? null;
    $list = is_array($source) ? $source : (is_string($source) ? ($options[$source] ?? []) : []);
    return is_array($list) && !array_is_list($list) ? array_map('strval', $list) : [];
  }

  /** @param list<array<string,mixed>> $owners */
  private static function ownersBlock(array $owners): string
  {
    if (count($owners) < 2) {
      return '';
    }
    $rows = '';
    foreach ($owners as $owner) {
      $rows .= '<tr><td>' . (int) $owner['index'] . '</td><td><strong>' . self::e($owner['name']) . '</strong><small>' . self::e(trim(($owner['document_type'] ?? '') . ' ' . ($owner['document'] ?? ''))) . '</small></td><td>' . self::e(($owner['percent'] ?? '') !== '' ? $owner['percent'] . ' %' : 'Sin registrar') . '</td></tr>';
    }
    return '<section class="cs-section"><h2>Copropietarios y participación</h2><div class="cs-table-wrap"><table class="cs-table"><thead><tr><th>#</th><th>Propietario</th><th>Participación</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
  }

  /** @param array<string,mixed> $values */
  private static function checklistBlock(array $values): string
  {
    $html = '';
    $counts = ['good' => 0, 'bad' => 0, 'muted' => 0];
    foreach (ClosingSheetForm::CHECKLIST as [$group, $when, $items]) {
      if ($when !== null && !ClosingSheetService::visible(['when' => $when], $values)) {
        continue;
      }
      $rows = '';
      foreach ($items as [$column, $text]) {
        $state = trim((string) ($values[$column] ?? ''));
        $note = trim((string) ($values['__notes'][$column] ?? ''));
        if ($state === '' && $note === '') {
          continue;
        }
        $tone = match (mb_strtolower($state, 'UTF-8')) { 'cumple' => 'good', 'no cumple' => 'bad', default => 'muted' };
        $counts[$tone]++;
        $rows .= '<li><span class="csf-result is-' . $tone . '">' . self::e($state !== '' ? $state : 'Sin estado') . '</span><div><p>' . self::e($text) . '</p>' . ($note !== '' ? '<small>' . nl2br(self::e($note)) . '</small>' : '') . '</div></li>';
      }
      if ($rows !== '') {
        $html .= '<div class="csf-check-group"><h3>' . self::e($group) . '</h3><ul class="csf-checklist">' . $rows . '</ul></div>';
      }
    }
    $totals = $html === '' ? '' : '<p class="csf-check-totals"><span class="csf-result is-good">Cumple: ' . $counts['good'] . '</span>'
      . '<span class="csf-result is-bad">No cumple: ' . $counts['bad'] . '</span><span class="csf-result is-muted">No aplica u otro: ' . $counts['muted'] . '</span></p>';
    return '<section class="cs-section csf-checks"><h2>Lista de chequeo</h2>' . $totals . ($html !== '' ? $html : '<p class="cs-note">La hoja no tiene lista de chequeo (hojas anteriores a la lista).</p>') . '</section>';
  }

  // ---------------------------------------------------------------- listado

  /**
   * @param array<string,int> $counts estado => cantidad @param list<array<string,mixed>> $rows
   * @param array{review:bool,respond:bool,edit:bool} $can
   */
  public static function listBody(array $counts, array $rows, string $state, string $query, array $can, string $baseUrl): string
  {
    $tabs = '';
    foreach (ClosingSheetWorkflow::LIST_STATES as $option) {
      $tabs .= '<a class="csf-tab' . ($option === $state ? ' is-active' : '') . '" href="?' . self::e(http_build_query(['estado' => $option, 'q' => $query])) . '">' . self::e($option) . ' <span>' . (int) ($counts[$option] ?? 0) . '</span></a>';
    }
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $actions = '<a class="cs-secondary" href="hoja-cierre-ver.php?numero=' . $id . '">Ver</a>';
      if ($can['review'] && ClosingSheetWorkflow::canReview($row)) {
        $actions .= '<a class="cs-secondary" href="revision-servicios-cierre.php?numero=' . $id . '">Revisar servicios</a>';
      }
      if ($can['respond'] && ClosingSheetWorkflow::canRespond($row)) {
        $actions .= '<a class="cs-primary" href="hoja-cierre-ver.php?numero=' . $id . '#responder">Responder</a>';
      }
      $body .= '<tr><td><strong>#' . $id . '</strong><small>' . self::e(self::date((int) ($row['fecha'] ?? 0))) . '</small></td>'
        . '<td>' . self::e($row['direccion'] ?? '') . '<small>Inmueble #' . self::e($row['id_inmueble'] ?? '') . '</small></td>'
        . '<td>' . self::e($row['arrendatario'] ?? '') . '</td>'
        . '<td>' . self::e($row['creador'] ?? '') . (trim((string) ($row['verificador'] ?? '')) !== '' ? '<small>Revisó: ' . self::e($row['verificador']) . '</small>' : '') . (trim((string) ($row['aprobador'] ?? '')) !== '' ? '<small>Respondió: ' . self::e($row['aprobador']) . '</small>' : '') . '</td>'
        . '<td>' . self::badge(ClosingSheetWorkflow::state($row)) . '</td><td><div class="csf-row-actions">' . $actions . '</div></td></tr>';
    }
    return '<section class="cs-section"><form class="csf-search" method="get"><input type="hidden" name="estado" value="' . self::e($state) . '">'
      . '<input class="cs-input" type="search" name="q" value="' . self::e($query) . '" placeholder="Buscar por número, inmueble, dirección, arrendatario o propietario">'
      . '<button class="cs-primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">search</span> Buscar</button></form>'
      . '<nav class="csf-tabs" aria-label="Estados">' . $tabs . '</nav>'
      . ($body !== '' ? '<div class="cs-table-wrap"><table class="cs-table csf-list"><thead><tr><th>Hoja</th><th>Inmueble</th><th>Arrendatario</th><th>Funcionarios</th><th>Estado</th><th></th></tr></thead><tbody>' . $body . '</tbody></table></div>'
        : '<p class="cs-note">No hay hojas de cierre en este estado' . ($query !== '' ? ' con esa búsqueda' : '') . '.</p>')
      . '<p class="cs-note">Se muestran las 100 más recientes. <a href="' . self::e($baseUrl . '/index.php') . '">Volver al panel</a></p></section>';
  }

  // ---------------------------------------------------------------- configuración

  /** @param array<string,bool> $settings */
  public static function settingsForm(array $settings): string
  {
    $rows = '';
    foreach (ClosingSheetSettings::OPTIONS as $key => [$title, $help]) {
      $rows .= '<label class="!sp-flex !sp-items-start !sp-gap-3 !sp-p-3 !sp-rounded-xl !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-cursor-pointer">'
        . '<input type="checkbox" class="!sp-mt-1 !sp-w-4 !sp-h-4" name="settings[' . self::e($key) . ']" value="1"' . (!empty($settings[$key]) ? ' checked' : '') . '>'
        . '<span><strong class="!sp-block !sp-text-sm !sp-text-service-navy">' . self::e($title) . '</strong><small class="!sp-block !sp-text-xs !sp-text-service-muted !sp-mt-1">' . self::e($help) . '</small></span></label>';
    }
    return '<form class="!sp-font-sans !sp-text-service-navy !sp-flex-1 !sp-min-h-0 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-4" data-closing-sheet-settings-form autocomplete="off">'
      . '<p class="!sp-m-0 !sp-text-sm">Los correos internos siempre salen a los funcionarios que elijas en <strong>Notificaciones internas</strong>: <em>Hoja de cierre creada</em> (hacen la revisión de servicios), <em>Hoja de cierre por responder</em> (aprueban o no) y <em>Hoja de cierre respondida</em>. Cada uno recibe su enlace para actuar sin iniciar sesión.</p>'
      . '<div class="!sp-grid !sp-gap-3">' . $rows . '</div>'
      . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Activa los WhatsApp solo cuando Meta haya aprobado las plantillas (docs/crear-plantilla-whatsapp-hoja-cierre.md).</p>'
      . '<div class="!sp-flex !sp-items-center !sp-justify-end !sp-gap-3"><p class="!sp-m-0 !sp-text-[11px]" data-closing-sheet-settings-status role="status" aria-live="polite"></p>'
      . '<button type="submit" class="!sp-inline-flex !sp-items-center !sp-gap-2 !sp-px-4 !sp-py-2 !sp-rounded-xl !sp-bg-service-navy !sp-text-white !sp-text-sm !sp-font-bold !sp-border-0 !sp-cursor-pointer">Guardar configuración</button></div></form>';
  }

  // ---------------------------------------------------------------- piezas

  private static function done(): string
  {
    return '<section class="rp-card cs-done" data-csf-done hidden aria-live="polite"><span class="material-symbols-outlined cs-done-icon" aria-hidden="true">task_alt</span>'
      . '<h2 data-csf-done-title></h2><p data-csf-done-text></p><div class="cs-actions" data-csf-done-links></div></section>';
  }

  private static function input(string $id, string $name, string $label, string $value, string $type = 'text', string $help = ''): string
  {
    return '<div class="cs-field"><label class="cs-label" for="' . self::e($id) . '">' . self::e($label) . ' <span class="cs-req" aria-hidden="true">*</span></label>'
      . '<input class="cs-input" type="' . self::e($type) . '" id="' . self::e($id) . '" name="' . self::e($name) . '" value="' . self::e($value) . '" data-csf-required' . ($type === 'text' ? ' maxlength="180"' : '') . '>'
      . ($help !== '' ? '<small class="cs-help">' . self::e($help) . '</small>' : '') . '</div>';
  }

  /** @param array<string,string> $fields */
  private static function hidden(array $fields): string
  {
    return implode('', array_map(static fn($name, $value): string => '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">', array_keys($fields), $fields));
  }

  public static function date(int $timestamp): string
  {
    return $timestamp > 0 ? (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Bogota'))->format('d/m/Y') : '';
  }

  public static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
