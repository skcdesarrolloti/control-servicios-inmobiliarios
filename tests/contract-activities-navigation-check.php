<?php

declare(strict_types=1);

define('SCM_VERSION', 'navigation-test');

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

foreach (['mine', 'team', 'due', 'property_history', 'contracts_ending', 'contract_termination', 'contract_non_renewal', 'contract_owners', 'casos_juridicos', 'gestor_organizacional'] as $route) {
  $_GET = ['tab' => $route];
  $current_page = '';
  ob_start();
  include dirname(__DIR__) . '/includes/header.php';
  ob_end_clean();
  $check($current_page === $route, 'Reload selected the wrong header item for ' . $route);
  $keys = array_keys($rawNavItems);
  $check(array_search('contractuales', $keys, true) > array_search('administrativas', $keys, true), 'Contract activities must follow administrative activities.');
  $check(array_keys($rawNavItems['contractuales']['children']) === ['contratos_arrendamiento', 'contracts_ending', 'contract_termination', 'contract_non_renewal', 'contract_owners', 'casos_juridicos', 'gestor_organizacional'], 'Contract group must keep its views in order: Casos jurídicos and then Gestor organizacional.');
  foreach (array_intersect_key($rawNavItems['contractuales']['children'], array_flip(['contracts_ending', 'contract_termination', 'contract_non_renewal'])) as $key => $item) {
    $check(!isset($rawNavItems['inicio']['children'][$key]), 'Contract view is still in Inicio.');
    $check($item['panel_id'] === 'scm-panel-actividades-contractuales', 'Contract item points to the wrong main panel.');
    $check(str_ends_with($item['url'], '?tab=' . $key), 'Contract route must use tab without subtab.');
  }
  foreach (['mine', 'team', 'due', 'property_history'] as $key) {
    $check(str_ends_with($rawNavItems['inicio']['children'][$key]['url'], '?tab=' . $key), 'Inicio route must use tab without subtab.');
  }
}

$owners = $rawNavItems['contractuales']['children']['contract_owners'];
$check($owners['panel_id'] === 'scm-panel-funcionario-contratos' && $owners['perms'] === ['funcionario_contratos'] && str_ends_with($owners['url'], '?tab=contract_owners'), 'Funcionario de contratos is wired to its own panel.');
$legal = $rawNavItems['contractuales']['children']['casos_juridicos'];
$check($legal['panel_id'] === 'scm-panel-casos-juridicos' && $legal['perms'] === ['casos_juridicos'] && str_ends_with($legal['url'], '?tab=casos_juridicos'), 'Casos jurídicos is wired to its own panel.');

// Menú del usuario: consultas personales según las pestañas permitidas del cargo.
$_GET = ['tab' => 'mine'];
$allowed_tabs = ['mine', 'gestor_organizacional', 'mis_presupuestos'];
ob_start();
include dirname(__DIR__) . '/includes/header.php';
$html = (string) ob_get_clean();
$menu = substr($html, (int) strpos($html, 'id="user-dropdown-menu"'), 6000);
$check(str_contains($menu, 'Mis políticas') && str_contains($menu, 'data-org-focus="politicas"') && str_contains($menu, 'Mis funciones') && str_contains($menu, 'Mis presupuestos'), 'The user menu offers my policies, functions and budgets.');
$check(!str_contains($menu, 'Mis actividades'), 'Mis actividades needs the Vencimientos tab.');
$allowed_tabs = ['mine', 'vencimientos'];
ob_start();
include dirname(__DIR__) . '/includes/header.php';
$menu = substr((string) ob_get_clean(), 0);
$menu = substr($menu, (int) strpos($menu, 'id="user-dropdown-menu"'), 6000);
$check(str_contains($menu, 'Mis actividades') && str_contains($menu, 'data-subtab-target="due"') && !str_contains($menu, 'Mis presupuestos') && !str_contains($menu, 'Mis políticas'), 'Each personal link follows its tab permission.');
unset($allowed_tabs);

echo "Contract activities header navigation checks passed.\n";
