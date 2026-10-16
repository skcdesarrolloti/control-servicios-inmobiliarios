<?php

declare(strict_types=1);

// Revisión preventiva nativa: crear, editar, eliminar, cobro administrativo e informe público
// sobre SQLite en memoria con las columnas reales de producción.

require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
foreach (['SCM_BASE_URL' => 'https://panel.test', 'SCM_APP_SECRET' => str_repeat('k', 40), 'SCM_UPLOAD_MAX_BYTES' => 10485760, 'SCM_VERSION' => 'test'] as $name => $value) {
  if (!defined($name)) define($name, $value);
}
if (!function_exists('esc_html')) { function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); } }

final class MysqlishPdo extends PDO
{
  public static function rewrite(string $query): string
  {
    if (str_contains($query, 'information_schema.TABLES')) {
      return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1";
    }
    if (preg_match('/^DESCRIBE `([^`]+)`$/', trim($query), $m)) {
      return "SELECT name FROM pragma_table_info('" . $m[1] . "')";
    }
    return $query;
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    return parent::prepare(self::rewrite($query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    return $fetchMode === null ? parent::query(self::rewrite($query)) : parent::query(self::rewrite($query), $fetchMode, ...$fetchModeArgs);
  }
}

$pdo = new MysqlishPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tables = [
  'jet_cct_revision_preventiva' => '_ID cct_status fecha evaluacion_de_danos tip_inm tiene_cotizacion id_empleado id_propietario id_arrendatario creador email_creador celular_creador coordinador email_coordinador celular_coordinador id_ticket id_inmueble id_contrato contrato inmueble sucursal cct_author_id cct_created cct_modified propietario arrendatario suelos escaleras enchufes tablero iluminacion cableado tuberias sanitarios termotanque aire_acondicionado ventilacion puertas sellos paredes_techos_pinturas cubiertas muebles electrodomesticos zonas_comunes cumplimiento finanzas ocupacion subarrendamiento modificaciones uso renovacion_contrato notificaciones encontro_danos direccion se_envio email_propietario celular_propietario email_arrendatario celular_arrendatario recomendaciones tipo_negocio destinacion fecha_envio cargo_coordinador',
  'jet_cct_reportes_administrativos' => '_ID cct_status id_ticket id_empleado creador cct_author_id cct_created cct_modified fecha fue_pagado categoria descripcion valor transporte valor_mantenimiento valor_otro sucursal valor_revision contrato id_acta_entrega id_revision_recibo id_acta_recibo id_revision_preventiva id_cotizacion_mantenimiento id_contrato id_rev_entrega id_inmueble id_estudio_aseguradora numero_solicitud exportado id_revision_correctiva fecha_revision fecha_ticket inmueble arrendatario',
  'jet_cct_tickets' => '_ID id_ticket tema_ayuda estado estado_administrativo id_revision_preventiva estado_rev_preventiva reportes tuvo_reporte se_encontraron_danos fecha_actualizacion estado_acta_cotizacion_mantenimiento estado_acta_satisfaccion estado_cotizacion_mantenimiento id_contrato contrato id_inmueble inmueble id_sucursal propietario arrendatario direccion fecha id_empleado',
  'jet_cct_contratos_arrendamiento' => '_ID contrato id_inmueble inmueble direccion propietario arrendatario correo_propietario celular_propietario correo_arrendatario celular_arrendatario id_propietario id_arrendatario sucursal id_inventario registro_fotografico id_revision_preventiva revisiones_preventivas ultima_revision_preventiva',
  'jet_cct_sucursales' => '_ID nombre_contractual celular_contractual correo_contractual',
  'jet_cct_confi_sistema' => '_ID funcion valor',
  'jet_cct_historial_del_ticket' => '_ID cct_status cct_author_id cct_created cct_modified id_ticket fecha nombre correo celular respuesta id_revision_preventiva id_empleado',
  'jet_cct_historial_del_inmueble' => '_ID cct_status cct_author_id cct_created cct_modified id_empleado id_inmueble fecha tipo_reporte observacion funcionario id_ticket',
  'jet_cct_funcionarios' => '_ID id_empleado nombre correo celular rol gestion id_cargo',
  'postmeta' => 'meta_id post_id meta_key meta_value',
];
foreach ($tables as $name => $columns) {
  $cols = array_map(static fn(string $c): string => in_array($c, ['_ID', 'meta_id'], true) ? "`{$c}` INTEGER PRIMARY KEY AUTOINCREMENT" : "`{$c}` TEXT", explode(' ', $columns));
  $pdo->exec('CREATE TABLE `wp_' . $name . '` (' . implode(', ', $cols) . ')');
}
$pdo->exec("INSERT INTO wp_jet_cct_tickets (_ID, id_ticket, tema_ayuda, estado, estado_administrativo, reportes, id_contrato, contrato, id_inmueble, inmueble, id_sucursal, fecha) VALUES (10883, '10883', 'Revision preventiva', 'Nuevo', 'Nuevo', '0', '540', '660', '77626', '10513', '1', '1791238614')");
$pdo->exec("INSERT INTO wp_jet_cct_contratos_arrendamiento (_ID, contrato, id_inmueble, inmueble, direccion, propietario, arrendatario, sucursal, id_inventario, registro_fotografico, revisiones_preventivas) VALUES (540, '660', '77626', '10513', 'DG 21 B', 'Maria Cortes', 'Yazlin Pacheco', '1', '396', 'https://drive.google.com/x', '2')");
$pdo->exec("INSERT INTO wp_jet_cct_sucursales (_ID, nombre_contractual, celular_contractual, correo_contractual) VALUES (1, 'Coordinadora Contractual', '3000000000', 'contractual@example.test')");
foreach (['salario' => '1423500', 'dias_trabajo' => '23', 'porcentaje_smlmv_co_pre' => '50', 'valor_transporte' => '4000'] as $f => $v) {
  $pdo->exec("INSERT INTO wp_jet_cct_confi_sistema (funcion, valor) VALUES ('{$f}', '{$v}')");
}
$pdo->exec("INSERT INTO wp_jet_cct_funcionarios (_ID, id_empleado, nombre, correo, celular) VALUES (7, '2706', 'Funcionario QA', 'qa@example.invalid', '3001112233')");
$pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (77626, 'revisiones-preventivas', '2')");

$db = new \SCM\Core\Database($pdo, 'wp_');
\SCM\Core\App::init($db, new \SCM\Core\Auth($db), new \SCM\Core\Csrf(SCM_APP_SECRET), new \SCM\Core\Settings($db, true));
$_SESSION = ['scm_logged_in' => true, 'scm_user' => 'Funcionario QA', 'scm_user_id' => 7, 'scm_employee_id' => '2706', 'scm_last_activity' => time()];
$app = new \SCM\App\SuCasaControlServiciosInmobiliarios($db);
$call = static function (string $method, ...$args) use ($app) {
  $ref = new ReflectionMethod($app, $method);
  return $ref->invoke($app, ...$args);
};
$assert = static function (bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message); }
};

$checklist = [];
foreach (\SCM\Modules\PreventiveReview\PreventiveReviewChecklist::blocks() as $key => $block) {
  foreach ($block['questions'] as $i => $question) {
    $checklist[$key]['pregunta_' . ($i + 1) . '_' . $key] = ($question['type'] ?? '') === 'text' ? 'Arrendataria y 2 hijos' : 'No';
  }
  $checklist[$key]['observaciones_' . $key] = $key === 'cumplimiento' ? 'Vivienda familiar' : '';
}
$checklist['suelos']['pregunta_2_suelos'] = 'Si';
$damage = ['indice' => 'Evaluacion de los daños en elementos arquitectonicos', 'area_afectada_1' => 'Cielos rasos y luminarias', 'descripcion_dano' => 'Filtración en cielo raso', 'consecuencia' => 'Daña el cielo raso', 'nivel_dano' => 'Leve', 'tiempo_atencion' => '8 dias', 'a_quien_corresponde' => 'Propietario'];

if (getenv('PREV_EMIT_FORM') === '1') {
  echo json_encode(['create' => $call('renderPreventiveReviewPanel', $call('preventiveReviewContext', 10883), 0)]);
  exit(0);
}

// Validación: sin uso principal no guarda.
$_POST = ['checklist' => array_replace_recursive($checklist, ['cumplimiento' => ['observaciones_cumplimiento' => '']]), 'encontro_danos' => 'No', 'transporte' => '8000'];
try { $call('preventiveReviewCreate', 10883); $assert(false, 'Debió exigir el uso principal del inmueble.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'uso principal'), $e->getMessage()); }
// Validación: "Sí" sin daños registrados.
$_POST = ['checklist' => $checklist, 'encontro_danos' => 'Si', 'items' => [], 'transporte' => '8000'];
try { $call('preventiveReviewCreate', 10883); $assert(false, 'Debió exigir al menos un daño.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'al menos un daño'), $e->getMessage()); }
// Validación: transporte fuera de opciones.
$_POST = ['checklist' => $checklist, 'encontro_danos' => 'No', 'transporte' => '99999'];
try { $call('preventiveReviewCreate', 10883); $assert(false, 'Debió rechazar el transporte.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'transporte'), $e->getMessage()); }

// Crear.
$_POST = ['checklist' => $checklist, 'encontro_danos' => 'Si', 'items' => [0 => $damage], 'transporte' => '8000', 'recomendaciones' => 'Revisar techo en invierno'];
$_FILES = [];
$created = $call('preventiveReviewCreate', 10883);
$reviewId = (int) $created['review_id'];
$review = $db->getRow('SELECT * FROM wp_jet_cct_revision_preventiva WHERE _ID = ?', [$reviewId]);
$assert($reviewId > 0 && $review['encontro_danos'] === 'Si' && $review['coordinador'] === 'Coordinadora Contractual', 'La revisión no guardó datos base.');
$assert(unserialize($review['suelos'])[0]['pregunta_2_suelos'] === 'Si', 'La checklist no se guardó con el formato de JetForm.');
$assert(unserialize($review['evaluacion_de_danos'])[0]['area_afectada_1'] === 'Cielos rasos y luminarias', 'Los daños no se guardaron.');
$assert($review['cct_author_id'] === '2706' && $review['id_empleado'] === '2706', 'cct_author_id debe ser el id_empleado real.');
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 10883');
$assert($ticket['id_revision_preventiva'] === (string) $reviewId && $ticket['estado_rev_preventiva'] === 'Si' && $ticket['estado_administrativo'] === 'Inspeccionado' && $ticket['reportes'] === '1', 'El caso no se actualizó como en JetForm.');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 540');
$assert($contract['id_revision_preventiva'] === (string) $reviewId && $contract['revisiones_preventivas'] === '3' && $contract['ultima_revision_preventiva'] !== '', 'El contrato no se actualizó.');
$assert($db->getVar("SELECT meta_value FROM wp_postmeta WHERE post_id = 77626 AND meta_key = 'revisiones-preventivas'") === '3', 'El contador del inmueble no se actualizó.');
$report = $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE id_revision_preventiva = ?', [(string) $reviewId]);
$fee = (int) round(1423500 / 23 * 0.5);
$assert($report && $report['categoria'] === 'Revision preventiva' && (int) $report['valor_revision'] === $fee && (int) $report['transporte'] === 8000 && (int) $report['valor'] === $fee + 8000 && $report['exportado'] === 'No', 'El cobro administrativo no se registró correctamente.');
$assert((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_revision_preventiva = ?", [(string) $reviewId]) === 1, 'Falta el historial del caso.');
$assert((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Revision preventiva'") === 1, 'Falta el historial del inmueble.');
try { $call('preventiveReviewCreate', 10883); $assert(false, 'No debe permitir una segunda revisión en el caso.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'ya tiene'), $e->getMessage()); }

// Panel: lista y formulario de edición.
$context = $call('preventiveReviewContext', 10883);
$panel = $call('renderPreventiveReviewPanel', $context, 0);
$assert(str_contains($panel, 'data-preventive-edit-review="' . $reviewId . '"') && str_contains($panel, 'revision-preventiva.php?numero=' . $reviewId), 'El panel no lista la revisión.');
$editForm = $call('renderPreventiveReviewPanel', $context, $reviewId);
$assert(str_contains($editForm, 'data-preventive-review-edit') && str_contains($editForm, 'value="Si" checked') && str_contains($editForm, 'Filtración en cielo raso') && substr_count($editForm, 'data-prev-step=') === 7, 'El formulario de edición no carga los datos.');

// Editar: sin daños y sin transporte ajusta el cobro.
$_POST = ['checklist' => $checklist, 'encontro_danos' => 'No', 'transporte' => '0', 'review_id' => (string) $reviewId];
$call('preventiveReviewUpdate', 10883, $reviewId);
$review = $db->getRow('SELECT * FROM wp_jet_cct_revision_preventiva WHERE _ID = ?', [$reviewId]);
$report = $db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE id_revision_preventiva = ?', [(string) $reviewId]);
$assert($review['encontro_danos'] === 'No' && unserialize($review['evaluacion_de_danos']) === [] && (int) $report['valor'] === $fee && (int) $report['transporte'] === 0, 'La edición no actualizó daños o cobro.');

// Informe público nativo.
$public = (new \SCM\Modules\PreventiveReview\PreventiveReviewPublicView($db))->render($reviewId);
$assert($public['status'] === 200 && str_contains($public['content'], 'Revisión preventiva #' . $reviewId) && str_contains($public['content'], 'is-alert') && str_contains($public['content'], 'Vivienda familiar') && !str_contains($public['content'], 'Sucursal'), 'El informe público no se generó bien.');
$url = \SCM\App\SuCasaControlServiciosInmobiliarios::signedPreventiveReviewPublicUrl($reviewId);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$assert(\SCM\App\SuCasaControlServiciosInmobiliarios::preventiveReviewPublicSignatureValid($reviewId, (int) $q['expires'], (string) $q['sig']), 'La firma pública no valida.');
$assert(!\SCM\App\SuCasaControlServiciosInmobiliarios::preventiveReviewPublicSignatureValid($reviewId + 1, (int) $q['expires'], (string) $q['sig']), 'La firma pública no debe servir para otro número.');
$assert(!\SCM\App\SuCasaControlServiciosInmobiliarios::correctiveReviewPublicSignatureValid($reviewId, (int) $q['expires'], (string) $q['sig']), 'La firma de la preventiva no debe abrir la correctiva.');

// Enviar (formulario JetForm "Enviar revisión preventiva").
$panel = $call('renderPreventiveReviewPanel', $call('preventiveReviewContext', 10883), 0);
$assert(str_contains($panel, 'data-preventive-send-review="' . $reviewId . '"') && str_contains($panel, 'Pendiente de envío'), 'El panel debe ofrecer enviar la revisión pendiente.');
$sendForm = $call('renderPreventiveReviewSendForm', $call('preventiveReviewContext', 10883), $call('preventiveReviewSendTarget', $call('preventiveReviewContext', 10883), 0));
$assert(str_contains($sendForm, 'checklist[finanzas][pregunta_1_finanzas]') && str_contains($sendForm, 'name="cerrar_ticket"') && str_contains($sendForm, 'name="notify_whatsapp[]"'), 'El formulario de envío no tiene los campos de JetForm.');
$_POST = ['review_id' => (string) $reviewId, 'checklist' => ['finanzas' => ['pregunta_1_finanzas' => 'No']]];
try { $call('preventiveReviewSend', 10883, $reviewId); $assert(false, 'Debió exigir la respuesta de cerrar caso.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'cierra el caso'), $e->getMessage()); }
$_POST = ['review_id' => (string) $reviewId, 'cerrar_ticket' => 'No', 'notify' => ['propietario', 'arrendatario'], 'notify_whatsapp' => ['propietario', 'arrendatario'], 'recomendaciones' => 'Pintar fachada', 'checklist' => ['finanzas' => ['pregunta_1_finanzas' => 'No', 'pregunta_2_finanzas' => 'Si', 'observaciones_finanzas' => 'Debe un mes'], 'uso' => ['pregunta_1_uso' => 'Si'], 'renovacion_contrato' => ['pregunta_1_renovacion_contrato' => 'No']]];
$historyBefore = (int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_revision_preventiva = ?', [(string) $reviewId]);
$call('preventiveReviewSend', 10883, $reviewId);
$review = $db->getRow('SELECT * FROM wp_jet_cct_revision_preventiva WHERE _ID = ?', [$reviewId]);
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 10883');
$assert($review['se_envio'] === 'Si' && (int) $review['fecha_envio'] > 0 && $review['recomendaciones'] === 'Pintar fachada' && $review['coordinador'] === 'Funcionario QA', 'El envío no marcó la revisión.');
$assert(unserialize($review['finanzas'])[0]['observaciones_finanzas'] === 'Debe un mes' && unserialize($review['uso'])[0]['pregunta_1_uso'] === 'Si', 'El envío no guardó pagos/uso con el formato de JetForm.');
$assert(unserialize($review['suelos'])[0]['pregunta_2_suelos'] === 'Si', 'El envío no debe borrar la checklist de la revisión.');
$assert($ticket['estado'] !== 'Cerrado' && $ticket['estado_administrativo'] === 'Inspeccionado', 'Sin cerrar, el envío no debe cambiar el estado del caso.');
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_revision_preventiva = ?', [(string) $reviewId]) === $historyBefore + 1, 'El envío debe dejar historial con el botón de la revisión.');
try { $call('preventiveReviewSend', 10883, $reviewId); $assert(false, 'No debe reenviar una revisión ya enviada.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'ya fue enviada'), $e->getMessage()); }
$panel = $call('renderPreventiveReviewPanel', $call('preventiveReviewContext', 10883), 0);
$assert(!str_contains($panel, 'data-preventive-send-review') && str_contains($panel, 'Enviada al propietario'), 'Una revisión enviada no debe ofrecer enviar de nuevo.');
$public = (new \SCM\Modules\PreventiveReview\PreventiveReviewPublicView($db))->render($reviewId);
$assert(str_contains($public['content'], 'Pagos y finanzas') && str_contains($public['content'], 'Debe un mes'), 'El informe público debe mostrar lo diligenciado al enviar.');
// Cerrar con el envío, como el JetForm.
$db->update('wp_jet_cct_revision_preventiva', ['se_envio' => 'No'], ['_ID' => $reviewId]);
$_POST = ['review_id' => (string) $reviewId, 'cerrar_ticket' => 'Si', 'checklist' => []];
$call('preventiveReviewSend', 10883, 0);
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 10883');
$assert($ticket['estado'] === 'Cerrado' && $ticket['estado_administrativo'] === 'Finalizado', 'Cerrar con el envío no cerró el caso.');
$db->update('wp_jet_cct_tickets', ['estado' => 'En proceso', 'estado_administrativo' => 'Inspeccionado'], ['_ID' => 10883]);

// Eliminar bloqueado si el cobro ya fue exportado.
$db->update('wp_jet_cct_reportes_administrativos', ['exportado' => 'Si'], ['_ID' => (int) $report['_ID']]);
try { $call('preventiveReviewDelete', 10883, $reviewId); $assert(false, 'No debe eliminar con cobro exportado.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'exportado'), $e->getMessage()); }
$db->update('wp_jet_cct_reportes_administrativos', ['exportado' => 'No'], ['_ID' => (int) $report['_ID']]);
$call('preventiveReviewDelete', 10883, $reviewId);
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 10883');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 540');
$assert(!$db->getRow('SELECT * FROM wp_jet_cct_revision_preventiva WHERE _ID = ?', [$reviewId]) && !$db->getRow('SELECT * FROM wp_jet_cct_reportes_administrativos WHERE _ID = ?', [(int) $report['_ID']]), 'No se eliminó la revisión o su cobro.');
$assert($ticket['id_revision_preventiva'] === '' && $ticket['estado_rev_preventiva'] === 'No' && $ticket['reportes'] === '0' && $contract['revisiones_preventivas'] === '2' && $contract['id_revision_preventiva'] === '', 'Eliminar no revirtió caso y contrato.');

// Permiso propio: hereda la correctiva solo hasta que se guarden los permisos con la nueva opción.
$settingsData = new ReflectionProperty(\SCM\Core\Settings::class, 'data');
$settingsData->setValue(\SCM\Core\App::settings(), ['contract_history_permissions_configured' => true, 'dashboard_action_permissions' => ['3' => ['corrective_review_manage'], '4' => ['case_note']]]);
$perms = $call('dashboardActionPermissionsConfig');
$assert(in_array('preventive_review_manage', $perms['3'], true) && !in_array('preventive_review_manage', $perms['4'], true), 'La preventiva debe heredar la correctiva antes de configurarse.');
$settingsData->setValue(\SCM\Core\App::settings(), ['contract_history_permissions_configured' => true, 'preventive_review_permissions_configured' => true, 'dashboard_action_permissions' => ['3' => ['corrective_review_manage'], '5' => ['preventive_review_manage']]]);
$perms = $call('dashboardActionPermissionsConfig');
$assert(!in_array('preventive_review_manage', $perms['3'], true) && $perms['5'] === ['preventive_review_manage'], 'Una vez configurados, los permisos deben ser independientes.');

echo "PASS: preventive review create/edit/delete, JetForm storage format, admin charge, contract/property counters, history, native public view, signed links, send to owner/tenant and separate permission.\n";
