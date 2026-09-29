<?php

declare(strict_types=1);

if (!function_exists('esc_html')) {
  function esc_html(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

require_once dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';

final class ContractDueCalendarProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;

  public function items(array $settings, int $fromTs, int $toTs): array
  {
    return $this->adminDueContractRequestItems($settings, $fromTs, $toTs);
  }

  private function contractTerminationRequestItems(int $limit = 150, bool $includeCase = true): array
  {
    return [[
      'solicitud_id' => '41', 'ticket_pk' => '18', 'id_ticket' => '180',
      'asunto' => 'Terminación', 'creado' => date('d/m/Y', strtotime('-10 days')),
      'fecha_solicitud' => date('Y-m-d', strtotime('-10 days')),
    ]];
  }

  private function contractNonRenewalRequestItems(int $limit = 150, bool $includeCase = true): array
  {
    return [[
      'solicitud_id' => '19', 'ticket_pk' => '19', 'id_ticket' => '190',
      'asunto' => 'No prórroga', 'creado' => date('d/m/Y', strtotime('-2 days')),
      'fecha_solicitud' => date('Y-m-d', strtotime('-2 days')),
    ]];
  }
}

$probe = new ContractDueCalendarProbe();
$fromTs = strtotime('today -5 days');
$toTs = strtotime('today +15 days');
$items = $probe->items([
  'terminacion_contrato_dias' => 3,
  'no_prorroga_contrato_dias' => 5,
], $fromTs, $toTs);

if (count($items) !== 2) {
  throw new RuntimeException('Both pending contract request types must appear in the due calendar.');
}
$byType = array_column($items, null, 'tipo_vencimiento');
$termination = $byType['terminacion_contrato_pendiente'] ?? null;
$nonRenewal = $byType['no_prorroga_contrato_pendiente'] ?? null;
if (!is_array($termination) || !is_array($nonRenewal)) {
  throw new RuntimeException('Contract request due types are missing.');
}
if ($termination['fecha_vencimiento'] !== date('Y-m-d', strtotime('today -7 days')) || $termination['estado'] !== 'Vencido') {
  throw new RuntimeException('Termination due date or overdue state is incorrect.');
}
if ($termination['fecha_calendario'] !== date('Y-m-d')) {
  throw new RuntimeException('An overdue termination must remain visible in the current calendar range.');
}
if ($nonRenewal['fecha_vencimiento'] !== date('Y-m-d', strtotime('today +3 days')) || $nonRenewal['estado'] !== 'Pendiente') {
  throw new RuntimeException('Non-renewal due date or pending state is incorrect.');
}
if (($nonRenewal['case']['ticket_pk'] ?? '') !== '19') {
  throw new RuntimeException('Non-renewal due entry must open the matching ticket.');
}

echo "Contract due calendar checks passed\n";
