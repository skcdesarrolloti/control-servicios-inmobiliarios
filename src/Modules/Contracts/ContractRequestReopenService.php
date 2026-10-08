<?php

declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;
use SCM\Modules\TicketCompletion\CompletionRepository;

final class ContractRequestReopenService
{
  public const ANSWERED_STATES = ['respondida', 'respondido', 'dentro de término', 'dentro de termino', 'fuera de término', 'fuera de termino', 'cerrada', 'cerrado', 'finalizada', 'finalizado'];

  public function __construct(private Database $db) {}

  public static function answered(array $row, bool $includeAdmin = false): bool
  {
    if (in_array(mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8'), ['anulado', 'anulada'], true)) return false;
    foreach ($includeAdmin ? ['estado', 'estado_administrativo'] : ['estado'] as $key) {
      if (in_array(mb_strtolower(trim((string) ($row[$key] ?? '')), 'UTF-8'), self::ANSWERED_STATES, true)) return true;
    }
    return false;
  }

  public function reopen(string $kind, int $ticketPk, int $requestId, array $actor, callable $matchesKind): array
  {
    if (!in_array($kind, ['termination', 'non-renewal'], true) || $ticketPk <= 0 || ($kind === 'termination' && $requestId <= 0)) throw new \DomainException('Solicitud o caso inválido.');
    if (trim((string) ($actor['employee_id'] ?? '')) === '') throw new \DomainException('No se pudo identificar al funcionario.');
    $tickets = $this->db->table('jet_cct_tickets');
    $requests = $this->db->table('jet_cct_solicitudes_terminacion_contrato');
    $schema = new SchemaInspector($this->db);
    // Share the existing lock with ticket activation, responses and act signing.
    return CompletionRepository::locked($this->db, $ticketPk, function () use ($kind, $ticketPk, $requestId, $actor, $matchesKind, $tickets, $requests, $schema): array {
      $pdo = $this->db->pdo();
      $pdo->beginTransaction();
      try {
        $ticket = $this->db->getRow("SELECT * FROM `{$tickets}` WHERE `_ID` = ? FOR UPDATE", [$ticketPk]) ?? [];
        if (!$ticket || !$matchesKind($ticket)) throw new \DomainException('El caso no corresponde al tipo de solicitud indicado.');
        $request = [];
        if ($kind === 'termination') {
          $request = $this->db->getRow("SELECT * FROM `{$requests}` WHERE `_ID` = ? FOR UPDATE", [$requestId]) ?? [];
          if (!$request || !self::answered($request)) throw new \DomainException('La solicitud ya no está contestada. Actualiza la bandeja.');
          $ref = trim((string) ($request['id_ticket'] ?? ''));
          $where = $schema->columnExists($tickets, 'id_ticket') ? 'TRIM(`id_ticket`) = ?' : '1 = 0';
          $args = $schema->columnExists($tickets, 'id_ticket') ? [$ref] : [];
          if (ctype_digit($ref)) { $where .= ' OR `_ID` = ?'; $args[] = (int) $ref; }
          $linked = $this->db->getResults("SELECT `_ID` FROM `{$tickets}` WHERE {$where} FOR UPDATE", $args);
          if (count($linked) !== 1 || (int) $linked[0]['_ID'] !== $ticketPk) throw new \DomainException('La solicitud no tiene una vinculación única con el caso indicado.');
        } elseif (!self::answered($ticket, true)) {
          throw new \DomainException('La solicitud ya no está contestada. Actualiza la bandeja.');
        }
        $error = CompletionRepository::workflowError($this->db, $schema, $ticketPk, false, 'Nuevo', true);
        if ($error !== '') throw new \DomainException($error);
        $now = time();
        $mysql = date('Y-m-d H:i:s', $now);
        $before = ['solicitud' => $request['estado'] ?? '', 'caso' => $ticket['estado'] ?? '', 'administrativo' => $ticket['estado_administrativo'] ?? ''];
        $message = 'Solicitud de ' . ($kind === 'termination' ? 'terminación' : 'no prórroga') . ($requestId > 0 ? ' #' . $requestId : '') . ' puesta en proceso desde Contestadas. Solicitud y estado administrativo: Nuevo. Caso: En proceso. Se conservan las respuestas y actas anteriores. Estados anteriores: ' . htmlspecialchars(json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        (new CompletionRepository($this->db, $schema))->audit($ticketPk, $message, (string) ($actor['name'] ?? ''), (string) $actor['employee_id']);
        if ($request) {
          $data = $schema->filterTableData($requests, ['estado' => 'Nuevo', 'cct_modified' => $mysql]);
          if (!$data || $this->db->update($requests, $data, ['_ID' => $requestId]) !== 1) throw new \RuntimeException('No se pudo actualizar la solicitud.');
        }
        $data = $schema->filterTableData($tickets, [
          'estado' => 'En proceso', 'estado_administrativo' => 'Nuevo', 'estado_admin_ticket' => 'Nuevo', 'estado_admin' => 'Nuevo',
          'fecha_actualizacion' => $now, 'cct_modified' => $mysql,
        ]);
        if (!$data) throw new \RuntimeException('No se pudo poner el caso en proceso.');
        $this->db->update($tickets, $data, ['_ID' => $ticketPk]);
        $pdo->commit();
        return ['ticket_pk' => $ticketPk, 'solicitud_id' => $requestId, 'message' => 'Solicitud puesta en proceso. Ya aparece en pendientes.'];
      } catch (\Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
      }
    });
  }
}
