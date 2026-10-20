<?php
declare(strict_types=1);
// Catálogo de acciones por cargo: herencia de acciones nuevas hasta guardar permisos, cobertura del
// catálogo y retiro de acciones AJAX del runtime. Sin base de datos ni escrituras.
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
require_once dirname(__DIR__) . '/src/Core/Helpers.php';

$class = \SCM\App\SuCasaControlServiciosInmobiliarios::class;
$app = (new ReflectionClass($class))->newInstanceWithoutConstructor();
$settings = (new ReflectionClass(\SCM\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\SCM\Core\App::class, 'settings'))->setValue(null, $settings);
$setSettings = static fn(array $data) => (new ReflectionProperty(\SCM\Core\Settings::class, 'data'))->setValue($settings, $data);
$invoke = static fn(string $method, ...$args) => (new ReflectionMethod($app, $method))->invoke($app, ...$args);
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
  if (!$ok) throw new RuntimeException('FAIL: ' . $label);
  $checks++;
  echo "PASS: {$label}\n";
};

$keys = $invoke('dashboardActionPermissionKeys');
$new = ['quote_financing', 'quote_order_edit', 'act_resend', 'act_cancel', 'case_contacts_edit', 'case_magnitude_calculate', 'case_export_excel',
  'admin_case_create', 'admin_report_review', 'public_services_review_save', 'public_services_liquidation_generate', 'rent_increase_letter_create',
  'collection_import', 'collection_action', 'canon_audit_import', 'canon_audit_edit',
  'notifications_send', 'notifications_import', 'notifications_queue_delete', 'notifications_community_edit'];
$check(!array_diff($new, $keys), 'catalog lists the new quote, act, administrative, collection and notification actions');
$check(!array_diff($class::LEGACY_DASHBOARD_ACTION_KEYS, $keys), 'catalog keeps every previous action');

// Toda acción que el código exige debe poder configurarse en el modal.
$used = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
foreach ($it as $file) {
  if (!$file->isFile() || $file->getExtension() !== 'php') continue;
  preg_match_all("/(?:canUseDashboardAction|requireDashboardAction)\\('([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m);
  foreach ($m[1] as $key) $used[$key] = true;
}
$check(!array_diff(array_keys($used), $keys), 'every action checked in the code is configurable: ' . implode(', ', array_diff(array_keys($used), $keys)));

// Permisos guardados antes del catálogo ampliado: las acciones nuevas conservan el comportamiento anterior.
$setSettings([
  'dashboard_action_permissions' => [
    '5' => ['quote_edit', 'case_completion_act', 'quote_order_create'],
    '6' => ['case_followup'],
    '11' => ['case_followup'],
  ],
]);
$config = $invoke('dashboardActionPermissionsConfig');
$check(!array_diff(['quote_financing', 'quote_order_edit', 'act_resend', 'act_cancel'], $config['5']), 'split actions inherit from the action they came from');
$check(!array_intersect(['quote_financing', 'quote_order_edit', 'act_resend', 'act_cancel'], $config['6']), 'split actions are not granted without the original action');
$check(in_array('notifications_send', $config['6'], true) && in_array('case_contacts_edit', $config['6'], true) && in_array('collection_import', $config['6'], true), 'actions that only required the tab stay allowed');
$check(!in_array('case_export_excel', $config['5'], true) && in_array('case_export_excel', $config['11'], true), 'Excel export stays limited to permission administrators');

// Tras guardar con el catálogo nuevo, lo guardado es la configuración real.
$setSettings([
  'dashboard_action_permissions' => ['5' => ['quote_edit']],
  'dashboard_action_permissions_known_keys' => $keys,
]);
$config = $invoke('dashboardActionPermissionsConfig');
$check(!in_array('quote_financing', $config['5'], true) && !in_array('notifications_send', $config['5'], true), 'saved permissions stop inheriting new actions');

// El runtime no entrega al navegador acciones AJAX que el cargo no puede usar.
$_SESSION['scm_user_cargo'] = '5';
$runtime = $invoke('filterRuntimeActionsByPermission', ['admin_notifications_send' => 'a', 'collection_portfolio_import' => 'b', 'mant' => 'c', 'export_cases_excel' => 'd']);
$check(!isset($runtime['admin_notifications_send']) && !isset($runtime['collection_portfolio_import']) && !isset($runtime['export_cases_excel']) && isset($runtime['mant']), 'runtime drops disallowed AJAX actions and keeps the rest');
$setSettings([
  'dashboard_action_permissions' => ['5' => ['quote_edit', 'notifications_send']],
  'dashboard_action_permissions_known_keys' => $keys,
]);
$runtime = $invoke('filterRuntimeActionsByPermission', ['admin_notifications_send' => 'a']);
$check(isset($runtime['admin_notifications_send']), 'runtime keeps allowed AJAX actions');

echo "{$checks} dashboard action permission checks passed.\n";
