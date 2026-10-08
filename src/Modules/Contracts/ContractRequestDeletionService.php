<?php

declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/** Deletes the case and its request atomically; keeps an independent audit. */
final class ContractRequestDeletionService
{
  public function __construct(private Database $db) {}

  public function delete(string $kind, int $ticketPk, int $requestId, array $actor, callable $matchesKind): array
  {
    if (!in_array($kind, ['termination', 'non-renewal'], true) || ($kind === 'termination' ? $requestId <= 0 : $ticketPk <= 0)) {
      throw new \DomainException('Solicitud inválida.');
    }
    if (trim((string) ($actor['employee_id'] ?? '')) === '') throw new \DomainException('No se pudo identificar al funcionario.');
    $pdo = $this->db->pdo();
    $tickets = $this->db->table('jet_cct_tickets');
    $requests = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    $audit = $this->db->table('scm_contract_request_deletions');
    // DDL must precede the transaction in MySQL.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$audit}` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      request_kind VARCHAR(30) NOT NULL, ticket_pk BIGINT NOT NULL,
      actor_employee_id VARCHAR(100) NOT NULL, actor_name VARCHAR(255) NOT NULL,
      snapshot_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->beginTransaction();
    try {
      $request = [];
      if ($kind === 'termination') {
        $request = $this->db->getRow("SELECT * FROM `{$requests}` WHERE `_ID` = ? FOR UPDATE", [$requestId]) ?? [];
        if (!$request) throw new \DomainException('La solicitud ya no existe. Actualiza la bandeja.');
        if (ContractRequestReopenService::answered($request)) throw new \DomainException('Las solicitudes contestadas deben ponerse en proceso antes de eliminarlas.');
        $ref = trim((string) ($request['id_ticket'] ?? ''));
        $linked = $ref === '' ? [] : $this->ticketsForReference($tickets, $ref);
        if (count($linked) > 1) throw new \DomainException('La referencia del caso es ambigua. Revisa la vinculación antes de eliminar.');
        $resolvedPk = (int) ($linked[0]['_ID'] ?? 0);
        if ($ticketPk > 0 && $ticketPk !== $resolvedPk) throw new \DomainException('La solicitud no corresponde al caso indicado.');
        $ticketPk = $resolvedPk;
      }
      $ticket = $ticketPk > 0 ? ($this->db->getRow("SELECT * FROM `{$tickets}` WHERE `_ID` = ? FOR UPDATE", [$ticketPk]) ?? []) : [];
      if ($ticketPk > 0 && !$ticket) throw new \DomainException('El caso ya no existe. Actualiza la bandeja.');
      if ($ticket && !$matchesKind($ticket)) throw new \DomainException('El caso no corresponde a este tipo de solicitud.');
      if ($ticket && ContractRequestReopenService::answered($ticket, true)) throw new \DomainException('Las solicitudes contestadas deben ponerse en proceso antes de eliminarlas.');
      $linkedRequests = $request ? [$request] : [];
      if ($kind === 'termination' && $ticket) {
        $refs = array_values(array_unique(array_filter([(string) $ticketPk, trim((string) ($ticket['id_ticket'] ?? ''))])));
        $linkedRequests = $this->db->getResults("SELECT * FROM `{$requests}` WHERE TRIM(`id_ticket`) IN (" . implode(',', array_fill(0, count($refs), '?')) . ') FOR UPDATE', $refs);
        foreach ($linkedRequests as $linkedRequest) {
          if (ContractRequestReopenService::answered($linkedRequest)) throw new \DomainException('El caso conserva solicitudes contestadas. Deben ponerse en proceso antes de eliminarlo.');
          $matches = $this->ticketsForReference($tickets, trim((string) $linkedRequest['id_ticket']));
          if (count($matches) !== 1 || (int) $matches[0]['_ID'] !== $ticketPk) throw new \DomainException('Hay solicitudes con referencias ambiguas. Revisa la vinculación antes de eliminar.');
        }
      }
      $this->db->insert($audit, [
        'request_kind' => $kind, 'ticket_pk' => $ticketPk,
        'actor_employee_id' => (string) $actor['employee_id'], 'actor_name' => (string) ($actor['name'] ?? ''),
        'snapshot_json' => json_encode(['ticket' => $ticket, 'requests' => $linkedRequests], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'created_at' => time(),
      ]);
      foreach ($linkedRequests as $linkedRequest) {
        if ($this->db->delete($requests, ['_ID' => (int) $linkedRequest['_ID']]) !== 1) throw new \RuntimeException('No se pudo eliminar la solicitud.');
      }
      if ($ticket && $this->db->delete($tickets, ['_ID' => $ticketPk]) !== 1) throw new \RuntimeException('No se pudo eliminar el caso.');
      $pdo->commit();
      return ['ticket_pk' => $ticketPk, 'solicitud_id' => $requestId, 'message' => 'Caso y solicitud eliminados.'];
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }

  private function ticketsForReference(string $table, string $ref): array
  {
    $hasLogicalId = (new SchemaInspector($this->db))->columnExists($table, 'id_ticket');
    $where = $hasLogicalId ? 'TRIM(`id_ticket`) = ?' : '1 = 0';
    $args = $hasLogicalId ? [$ref] : [];
    if (ctype_digit($ref)) { $where .= ' OR `_ID` = ?'; $args[] = (int) $ref; }
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE {$where} FOR UPDATE", $args);
  }
}
