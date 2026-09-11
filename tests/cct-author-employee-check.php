<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = file_get_contents($root . '/src/Core/Auth.php');
$guide = file_get_contents($root . '/src/Http/Controller/GuideApiController.php');
$administrativeTickets = file_get_contents($root . '/src/Modules/Pending/Concerns/AdministrativeTicketCreationConcern.php');
$administrativeNotifications = file_get_contents($root . '/src/Modules/AdministrativeNotifications/AdministrativeNotificationsService.php');
$corrective = file_get_contents($root . '/src/App/Concerns/HandlesCorrectiveReviewActions.php');
$agents = file_get_contents($root . '/AGENTS.md');

$checks = [
  'auth stores employee id in session' => is_string($auth) && str_contains($auth, 'scm_employee_id') && str_contains($auth, 'public static function employeeId()'),
  'guide CCT helpers use employee id as author' => is_string($guide) && str_contains($guide, "'cct_author_id' => Auth::employeeId() ?: Auth::userId()"),
  'administrative ticket CCT author uses creator employee id' => is_string($administrativeTickets) && str_contains($administrativeTickets, '$creatorEmployeeId = Auth::employeeId()') && str_contains($administrativeTickets, "'cct_author_id' => \$creatorEmployeeId"),
  'collection management uses employee id for author and employee fields' => is_string($administrativeNotifications) && str_contains($administrativeNotifications, '$employeeId = Auth::employeeId()') && str_contains($administrativeNotifications, "'cct_author_id' => \$employeeId") && str_contains($administrativeNotifications, "'id_empleado' => \$employeeId"),
  'corrective review uses employee id as author' => is_string($corrective) && str_contains($corrective, '$actorEmployeeId = $this->correctiveReviewFirstText') && str_contains($corrective, "'cct_author_id' => \$actorEmployeeId"),
  'project instructions preserve CCT author rule' => is_string($agents) && str_contains($agents, 'cct_author_id') && str_contains($agents, '`id_empleado` real'),
];

$failed = [];
foreach ($checks as $label => $ok) {
  if (!$ok) {
    $failed[] = $label;
  }
}

if ($failed) {
  fwrite(STDERR, "CCT author employee checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}

echo 'CCT author employee checks passed: ' . count($checks) . PHP_EOL;
