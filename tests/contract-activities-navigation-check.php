<?php

declare(strict_types=1);

define('SCM_VERSION', 'navigation-test');

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

foreach (['mine', 'team', 'due', 'property_history', 'contracts_ending', 'contract_termination', 'contract_non_renewal'] as $route) {
  $_GET = ['tab' => $route];
  $current_page = '';
  ob_start();
  include dirname(__DIR__) . '/includes/header.php';
  ob_end_clean();
  $check($current_page === $route, 'Reload selected the wrong header item for ' . $route);
  $check(array_keys($rawNavItems) === ['inicio', 'tickets', 'administrativas', 'contractuales', 'dashboard'], 'Contract activities must follow administrative activities.');
  $check(array_keys($rawNavItems['contractuales']['children']) === ['contracts_ending', 'contract_termination', 'contract_non_renewal'], 'Contract group must contain exactly the three requested views.');
  foreach ($rawNavItems['contractuales']['children'] as $key => $item) {
    $check(!isset($rawNavItems['inicio']['children'][$key]), 'Contract view is still in Inicio.');
    $check($item['panel_id'] === 'scm-panel-actividades-contractuales', 'Contract item points to the wrong main panel.');
    $check(str_ends_with($item['url'], '?tab=' . $key), 'Contract route must use tab without subtab.');
  }
  foreach (['mine', 'team', 'due', 'property_history'] as $key) {
    $check(str_ends_with($rawNavItems['inicio']['children'][$key]['url'], '?tab=' . $key), 'Inicio route must use tab without subtab.');
  }
}

echo "Contract activities header navigation checks passed.\n";
