<?php

/**
 * API AJAX — Control de Servicios Inmobiliarios
 * Recibe todas las peticiones AJAX del panel.
 */

// Capturar todo output (warnings, notices de bootstrap, etc.) para que no contaminen el JSON
ob_start();

require_once __DIR__ . '/bootstrap.php';

// Los errores PHP no deben contaminar la respuesta JSON (override bootstrap)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/data/php-error.log');
set_time_limit(60);
ini_set('memory_limit', '256M');

// Descartar cualquier output espurio de bootstrap/helpers antes de responder
ob_end_clean();

// Capturar errores fatales y devolverlos como JSON en vez de cuerpo vacío (500)
register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
      'success' => false,
      'data' => ['message' => 'Error interno: ' . $err['message'] . ' en ' . basename($err['file']) . ':' . $err['line']],
    ]);
  }
});

// AJAX requiere autenticación — responder con JSON en vez de redirigir
if (!\SCM\Core\Auth::isLoggedIn()) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'data' => ['message' => 'No autenticado.']]);
  exit;
}

// Liberar el lock de sesión: los endpoints AJAX no necesitan escribir en sesión
// Esto evita bloqueos cuando múltiples peticiones AJAX corren en paralelo
session_write_close();

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json');
  echo json_encode(['success' => false, 'data' => ['message' => 'Método no permitido.']]);
  exit;
}

$action = sanitize_key($_POST['action'] ?? '');

$app = new \SCM\App\SuCasaControlServiciosInmobiliarios($scmDb);

$mainPanelActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_ACTION,
];
$seguimientoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_SEGUIMIENTO,
];
$notaActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_NOTA,
];
$ticketResponseActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_TICKET_RESPONSE,
];
$postponeTicketActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_POSTPONE_TICKET,
];
$statusTicketsActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_STATUS_TICKETS,
];
$activateTicketActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_ACTIVATE_TICKET,
];
$cotizacionResponseActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_COTIZACION_RESPONSE,
];
$closeTicketActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CLOSE_TICKET,
];
$contactsUpdateActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CONTACTS_UPDATE,
];
$damageActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_DAMAGE_MAGNITUDE,
];
$classifyMagnitudeActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CLASSIFY_MAGNITUDE,
];
$saveCaseMagnitudeActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_SAVE_CASE_MAGNITUDE,
];
$savePropertyLocationActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_SAVE_PROPERTY_LOCATION,
];
$trasladarCasoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_TRASLADAR_CASO,
];
$asignarPqrPublicoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_ASIGNAR_PQR_PUBLICO,
];
$filtrarPqrPublicoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_FILTER_PQR_PUBLICO,
];
$guardarCorresponsablePqrPublicoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUARDAR_CORRESPONSABLE_PQR_PUBLICO,
];
$guardarNotifResponsablePqrActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUARDAR_NOTIF_RESPONSABLE_PQR,
];
$genericActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_ENTREGA,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_PREVENTIVA,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_RECIBO,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CONTABLE,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CERTIFICACIONES,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CONTRACTUAL,
];
$pendingPreventivasActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_PREVENTIVAS_PENDIENTES,
];
$pendingServiciosPublicosActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_SERVICIOS_PUBLICOS_PENDIENTES,
];
$pendingReportesAdministrativosActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_REPORTES_ADMINISTRATIVOS_PENDIENTES,
];
$contratosArrendamientoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CONTRATOS_ARRENDAMIENTO,
];
$crearTicketAdministrativoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_CREAR_TICKET_ADMINISTRATIVO,
];
$approveReporteAdministrativoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_APROBAR_REPORTE_ADMINISTRATIVO,
];
$rejectReporteAdministrativoActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_DESAPROBAR_REPORTE_ADMINISTRATIVO,
];
$guideGcdActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_READ,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_SAVE,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_DEL,
];
$guideGrtActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_READ,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_SAVE,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_DEL,
];
$guideGacActions = [
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_READ,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_SAVE,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_DEL,
  \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_CATS,
];

try {
  if (in_array($action, $mainPanelActions, true)) {
    $app->ajax_handler();
  } elseif (in_array($action, $seguimientoActions, true)) {
    $app->ajax_handler_seguimiento();
  } elseif (in_array($action, $notaActions, true)) {
    $app->ajax_handler_nota();
  } elseif (in_array($action, $ticketResponseActions, true)) {
    $app->ajax_handler_ticket_response();
  } elseif (in_array($action, $postponeTicketActions, true)) {
    $app->ajax_handler_postpone_ticket();
  } elseif (in_array($action, $statusTicketsActions, true)) {
    $app->ajax_handler_status_tickets();
  } elseif (in_array($action, $activateTicketActions, true)) {
    $app->ajax_handler_activate_ticket();
  } elseif (in_array($action, $cotizacionResponseActions, true)) {
    $app->ajax_handler_cotizacion_response();
  } elseif (in_array($action, $closeTicketActions, true)) {
    $app->ajax_handler_close_ticket();
  } elseif (in_array($action, $contactsUpdateActions, true)) {
    $app->ajax_handler_contacts_update();
  } elseif (in_array($action, $genericActions, true)) {
    $app->ajax_handler_generic();
  } elseif (in_array($action, $pendingPreventivasActions, true)) {
    $app->ajax_handler_preventivas_pendientes();
  } elseif (in_array($action, $pendingServiciosPublicosActions, true)) {
    $app->ajax_handler_servicios_publicos_pendientes();
  } elseif (in_array($action, $pendingReportesAdministrativosActions, true)) {
    $app->ajax_handler_reportes_administrativos_pendientes();
  } elseif (in_array($action, $contratosArrendamientoActions, true)) {
    $app->ajax_handler_contratos_arrendamiento();
  } elseif (in_array($action, $crearTicketAdministrativoActions, true)) {
    $app->ajax_handler_crear_ticket_administrativo();
  } elseif (in_array($action, $approveReporteAdministrativoActions, true)) {
    $app->ajax_handler_aprobar_reporte_administrativo();
  } elseif (in_array($action, $rejectReporteAdministrativoActions, true)) {
    $app->ajax_handler_desaprobar_reporte_administrativo();
  } elseif (in_array($action, $guideGcdActions, true)) {
    if ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_READ) {
      $app->ajax_handler_guide_gcd_read();
    } elseif ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GCD_SAVE) {
      $app->ajax_handler_guide_gcd_save();
    } else {
      $app->ajax_handler_guide_gcd_del();
    }
  } elseif (in_array($action, $guideGrtActions, true)) {
    if ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_READ) {
      $app->ajax_handler_guide_grt_read();
    } elseif ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GRT_SAVE) {
      $app->ajax_handler_guide_grt_save();
    } else {
      $app->ajax_handler_guide_grt_del();
    }
  } elseif (in_array($action, $guideGacActions, true)) {
    if ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_READ) {
      $app->ajax_handler_guide_gac_read();
    } elseif ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_SAVE) {
      $app->ajax_handler_guide_gac_save();
    } elseif ($action === \SCM\App\SuCasaControlServiciosInmobiliarios::AJAX_GUIDE_GAC_CATS) {
      $app->ajax_handler_guide_gac_cats();
    } else {
      $app->ajax_handler_guide_gac_del();
    }
  } elseif (in_array($action, $damageActions, true)) {
    $app->ajax_handler_damage_magnitude();
  } elseif (in_array($action, $classifyMagnitudeActions, true)) {
    $app->ajax_handler_classify_magnitude();
  } elseif (in_array($action, $saveCaseMagnitudeActions, true)) {
    $app->ajax_handler_save_case_magnitude();
  } elseif (in_array($action, $savePropertyLocationActions, true)) {
    $app->ajax_handler_save_property_location();
  } elseif (in_array($action, $trasladarCasoActions, true)) {
    $app->ajax_handler_trasladar_caso();
  } elseif (in_array($action, $asignarPqrPublicoActions, true)) {
    $app->ajax_handler_asignar_pqr_publico();
  } elseif (in_array($action, $filtrarPqrPublicoActions, true)) {
    $app->ajax_handler_filtrar_pqr_publico();
  } elseif (in_array($action, $guardarCorresponsablePqrPublicoActions, true)) {
    $app->ajax_handler_guardar_corresponsable_pqr_publico();
  } elseif (in_array($action, $guardarNotifResponsablePqrActions, true)) {
    $app->ajax_handler_guardar_notif_responsable_pqr();
  } else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'data' => ['message' => 'Acción desconocida: ' . $action]]);
    exit;
  }
} catch (\Throwable $e) {
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
  }
  echo json_encode([
    'success' => false,
    'data'    => ['message' => 'Excepcion: ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine()],
  ]);
  exit;
}
