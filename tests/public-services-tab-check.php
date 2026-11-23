<?php
declare(strict_types=1);
// Pestaña "Servicios públicos" en casos abiertos: temas propios, sin mezclarse con Contractual ni Mantenimiento.
// Configuración en memoria; no toca producción.
require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\App\SuCasaControlServiciosInmobiliarios as AppMain;

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
  echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
  if (!$ok) { $failures++; }
};
$lower = static fn(array $topics): array => array_map(static fn($t): string => mb_strtolower((string) $t, 'UTF-8'), $topics);

$withSettings = static function (?array $topicTabs): void {
  $pdo = new PDO('sqlite::memory:');
  $settings = new SCM\Core\Settings(new SCM\Core\Database($pdo, 'wp_'), true);
  (new ReflectionProperty(SCM\Core\Settings::class, 'data'))->setValue($settings, $topicTabs === null ? [] : ['ticket_topic_tabs' => $topicTabs]);
  (new ReflectionProperty(SCM\Core\App::class, 'settings'))->setValue(null, $settings);
};

$defaults = AppMain::defaultGenericTicketTabDefinitions();
$check(isset($defaults['servicios_publicos']), 'la pestaña servicios_publicos existe');
$check(array_search('servicios_publicos', array_keys($defaults), true) === array_search('contractual', array_keys($defaults), true) + 1, 'va justo después de Contractual');
$check(!in_array('solicitud de servicios publicos', $lower($defaults['contractual']['temas']), true), 'Contractual ya no trae solicitudes de servicios públicos');
$check(in_array('servicio publico critico', $lower($defaults['servicios_publicos']['temas']), true), 'los casos críticos de 72 h entran en Servicios públicos');

$withSettings(null);
$configured = AppMain::configuredGenericTicketTabDefinitions();
$check($configured['servicios_publicos']['temas'] === AppMain::PUBLIC_SERVICES_TICKET_TOPICS, 'sin configuración guardada usa los temas por defecto');

// Configuración guardada antes de la pestaña: los temas de servicios públicos seguían en Contractual.
$legacy = [];
foreach ($defaults as $key => $definition) {
  if ($key !== 'servicios_publicos') { $legacy[$key] = $definition['temas']; }
}
$legacy['contractual'][] = 'Solicitud de servicios publicos';
$legacy['contractual'][] = 'Solicitudes de servicios publicos';
$withSettings($legacy);
$configured = AppMain::configuredGenericTicketTabDefinitions();
$check(!in_array('solicitud de servicios publicos', $lower($configured['contractual']['temas']), true), 'configuración vieja: el tema sale de Contractual');
$check(in_array('Procesos juridicos', $configured['contractual']['temas'], true), 'configuración vieja: Contractual conserva sus demás temas');
$check(in_array('Solicitud de servicios publicos', $configured['servicios_publicos']['temas'], true), 'configuración vieja: el tema queda en Servicios públicos');

// Una vez guardada la pestaña, se respeta lo configurado.
$saved = $legacy;
$saved['servicios_publicos'] = ['Servicio publico critico'];
$withSettings($saved);
$configured = AppMain::configuredGenericTicketTabDefinitions();
$check(in_array('Solicitud de servicios publicos', $configured['contractual']['temas'], true), 'configuración nueva guardada: no se reacomoda');

$withSettings(null);
$exclusions = AppMain::publicServicesTicketTopicsLower();
$check(in_array('solicitud de servicios públicos', $exclusions, true) && in_array('servicio público crítico', $exclusions, true), 'la exclusión de Mantenimiento cubre las variantes con tilde');

$root = dirname(__DIR__);
$scmAdmin = (string) file_get_contents($root . '/public/assets/js/scm-admin.js');
$check((bool) preg_match('/\["entrega", "recibo", "contractual", "servicios_publicos", "contable", "certificaciones"\]\.indexOf/', $scmAdmin), 'el popup de Servicios públicos no ofrece acta de solución');
$runtime = (string) file_get_contents($root . '/public/assets/js/admin-dashboard-runtime.js');
$check(str_contains($runtime, '{ key: "servicios_publicos", action: actions.servicios_publicos || "" }'), 'la pestaña se carga por AJAX');
$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$check(str_contains($dashboard, "'servicios_publicos' => self::AJAX_SERVICIOS_PUBLICOS_CASOS"), 'el runtime recibe la acción AJAX de la pestaña');
$workflow = (string) file_get_contents($root . '/src/App/Concerns/HandlesTicketWorkflowActions.php');
$check(str_contains($workflow, "\$case['tab_key'] = 'servicios_publicos'"), 'el crítico abierto desde Alertas usa las reglas de Servicios públicos');

// Filtro "Tipo de caso": solicitudes normales vs. críticos de 72 h.
$parser = new class {
  use SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
};
$parse = static fn(string $value): string => (string) ($parser->parse_params_generic(['scmsp_tipo_servicio_publico' => $value], 'scmsp_')['fTipoServicioPublico'] ?? '');
$check($parse('critico') === 'critico' && $parse('normal') === 'normal', 'el filtro acepta normales y críticos');
$check($parse('cualquier cosa') === '', 'el filtro ignora valores desconocidos');
$query = (string) file_get_contents($root . '/src/Modules/GenericTickets/Concerns/GenericQueryConcern.php');
$check(str_contains($query, "(\$tipoServicioPublico === 'critico' ? 'IN' : 'NOT IN')"), 'normales excluye los críticos y críticos solo trae esos');
$ui = (string) file_get_contents($root . '/src/Views/GenericTicketsUiView.php');
$check(str_contains($ui, "=== 'servicios_publicos'): \$tipoSp") && str_contains($ui, 'Críticos (72 h)'), 'el filtro solo se pinta en Servicios públicos');

if ($failures > 0) {
  fwrite(STDERR, $failures . " check(s) failed\n");
  exit(1);
}
echo "OK: pestaña Servicios públicos\n";
