<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

final class CompletionRepository
{
  public readonly SchemaInspector $schema;

  public function __construct(public readonly Database $db, ?SchemaInspector $schema = null)
  {
    $this->schema = $schema ?? new SchemaInspector($db);
  }

  public function table(): string
  {
    return $this->db->table('scm_ticket_completion_acts');
  }

  public function schemaSql(): string
  {
    return 'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      ticket_pk BIGINT UNSIGNED NOT NULL,
      legacy_act_id BIGINT UNSIGNED NULL,
      active_slot TINYINT UNSIGNED DEFAULT 1,
      status VARCHAR(16) NOT NULL DEFAULT \'pending\',
      payload_json MEDIUMTEXT NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      token_nonce CHAR(64) NOT NULL,
      expires_at BIGINT NOT NULL,
      created_at BIGINT NOT NULL,
      signed_at BIGINT NULL,
      signed_json TEXT NULL,
      otp_json TEXT NULL,
      delivery_json TEXT NULL,
      signed_pdf MEDIUMBLOB NULL,
      pdf_hash CHAR(64) NULL,
      pdf_hmac CHAR(64) NULL,
      report_id BIGINT UNSIGNED NULL,
      invitation_queued_at BIGINT NULL,
      cancelled_at BIGINT NULL,
      cancellation_reason TEXT NULL,
      UNIQUE KEY one_active_act (ticket_pk, active_slot),
      UNIQUE KEY one_legacy_act (legacy_act_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  }

  public function requireSchema(): void
  {
    $required = [
      $this->table() => ['payload_json', 'payload_hash', 'token_nonce', 'active_slot', 'report_id', 'otp_json', 'delivery_json', 'signed_pdf', 'pdf_hash', 'pdf_hmac'],
      $this->db->table('jet_cct_tickets') => ['_ID', 'estado', 'estado_administrativo', 'id_acta_satisfaccion', 'estado_acta_satisfaccion'],
      $this->db->table('jet_cct_actas_de_satisfaccion') => ['_ID', 'id_ticket', 'observaciones'],
      $this->db->table('jet_cct_reportes_administrativos') => ['_ID', 'id_ticket', 'descripcion', 'valor', 'exportado', 'fue_pagado'],
      $this->db->table('jet_cct_historial_del_ticket') => ['_ID', 'id_ticket', 'respuesta'],
    ];
    foreach ($required as $table => $columns) {
      if (array_diff($columns, $this->schema->getTableColumns($table))) {
        throw new \DomainException('El módulo de actas requiere preparar su esquema. Ejecuta bin/migrate-ticket-completion.php.');
      }
    }
    $engines = $this->db->getCol('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($required), '?')) . ')', array_keys($required));
    foreach ($engines as $engine) {
      if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
        throw new \DomainException('Las tablas del acta deben usar InnoDB para guardar firma, cierre y reporte de forma atómica.');
      }
    }
  }

  /** CLI-only additive migration; never alter historical payloads or signatures. */
  public function migrate(): void
  {
    $this->db->pdo()->exec($this->schemaSql());
    $columns = $this->db->getCol('DESCRIBE `' . $this->table() . '`');
    foreach (['otp_json' => 'TEXT', 'delivery_json' => 'TEXT', 'signed_pdf' => 'MEDIUMBLOB', 'pdf_hash' => 'CHAR(64)', 'pdf_hmac' => 'CHAR(64)'] as $name => $type) {
      if (!in_array($name, $columns, true)) {
        $this->db->pdo()->exec('ALTER TABLE `' . $this->table() . '` ADD COLUMN `' . $name . '` ' . $type . ' NULL');
      }
    }
  }

  public function ticket(int $id, bool $lock = false): array
  {
    $row = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_tickets') . '` WHERE _ID = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    if (!$row) {
      throw new \DomainException('Ticket no encontrado.');
    }
    return $row;
  }

  public function act(int $id): array
  {
    $row = $id > 0 ? $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = ?', [$id]) : null;
    if (!$row) {
      throw new \DomainException('Acta no disponible.');
    }
    return $row;
  }

  public function active(int $ticketId): ?array
  {
    // A current read is needed inside transactions that may have started before another act was committed.
    return $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE ticket_pk = ? AND active_slot = 1' . ($this->db->pdo()->inTransaction() ? ' FOR UPDATE' : ''), [$ticketId]);
  }

  public function history(int $ticketId): array
  {
    return $this->db->getResults('SELECT * FROM `' . $this->table() . '` WHERE ticket_pk = ? ORDER BY id DESC', [$ticketId]);
  }

  public function insertLegacy(string $table, array $data): int
  {
    $table = $this->db->table($table);
    $data = $this->schema->filterTableData($table, $data);
    if (!$data || !$this->db->insert($table, $data)) {
      throw new \RuntimeException('No se pudo guardar el registro del acta.');
    }
    return (int) $this->db->lastInsertId();
  }

  public function updateTicket(int $id, array $data): void
  {
    $table = $this->db->table('jet_cct_tickets');
    $data['fecha_actualizacion'] = time();
    $data['cct_modified'] = date('Y-m-d H:i:s');
    $this->db->update($table, $this->schema->filterTableData($table, $data), ['_ID' => $id]);
  }

  /**
   * La firma de esta acta confirma que la solucion se ejecuto sin aprobar la
   * cotizacion de mantenimiento. Actualiza todas las cotizaciones vinculadas
   * dentro de la misma transaccion que cierra el ticket.
   *
   * @return string[] Identificadores de las cotizaciones encontradas.
   */
  public function disapproveMaintenanceQuotes(array $ticket, int $actId, int $signedAt): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $idColumn = $this->schema->detectFirstExistingColumn($table, ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id']);
    if ($idColumn === '') {
      return [];
    }

    $quoteIds = $this->numericIds((string) ($ticket['id_cotizacion_mantenimiento'] ?? $ticket['id_cotizacion'] ?? ''));
    $rows = $quoteIds === [] ? [] : $this->lockedQuotesByIds($table, $idColumn, $quoteIds);

    // Some historical tickets did not persist the quote ID on the ticket. In
    // that case use the quote's ticket relation, preferring the public ticket
    // number because that is the convention used by the maintenance module.
    if ($rows === [] && $this->schema->columnExists($table, 'id_ticket')) {
      $logicalTicket = trim((string) ($ticket['id_ticket'] ?? ''));
      if ($logicalTicket !== '') {
        $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? FOR UPDATE", [$logicalTicket]);
      }
      if ($rows === [] && (int) ($ticket['_ID'] ?? 0) > 0 && $logicalTicket !== (string) $ticket['_ID']) {
        $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? FOR UPDATE", [(int) $ticket['_ID']]);
      }
    }

    if ($rows === []) {
      return [];
    }

    $update = $this->schema->filterTableData($table, [
      'estado' => 'Desaprobada',
      'estado_cotizacion_mantenimiento' => 'Desaprobada',
      'estado_respuesta_cotizacion_mantenimiento' => 'Desaprobada',
      'estado_respuesta' => 'Desaprobada',
      'respuesta' => 'Desaprobada',
      // Preserve the existing business value (including its historical spelling)
      // because filters and notification templates already depend on it.
      'motivo' => 'Ejecucción por cuenta propia',
      'observacion_respuesta' => 'Cotización desaprobada automáticamente al firmarse el acta de satisfacción #' . $actId . '. La solución se realizó sin aprobar esta cotización.',
      'fecha_respuesta' => $signedAt,
      'fecha_respuesta_cotizacion_mantenimiento' => $signedAt,
      'tuvo_seguimiento' => 'Si',
      'fecha_seguimiento' => $signedAt,
      'fecha_actualizacion' => $signedAt,
      'cct_modified' => date('Y-m-d H:i:s', $signedAt),
    ]);

    $updatedIds = [];
    foreach ($rows as $row) {
      $quoteId = trim((string) ($row[$idColumn] ?? ''));
      if ($quoteId === '' || isset($updatedIds[$quoteId])) {
        continue;
      }
      $this->db->update($table, $update, [$idColumn => $quoteId]);
      $updatedIds[$quoteId] = true;
    }
    return array_keys($updatedIds);
  }

  /**
   * La firma de un acta originada en una cotización ya aprobada confirma el
   * trabajo realizado. No cambia la aprobación de la cotización: solo la deja
   * cerrada como trabajo finalizado y vinculada al acta legacy firmada.
   *
   * @return string[] Identificadores de las cotizaciones encontradas.
   */
  public function finalizeApprovedMaintenanceQuotes(array $ticket, string $quoteId, int $legacyActId, int $signedAt): array
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $idColumn = $this->schema->detectFirstExistingColumn($table, ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id']);
    if ($idColumn === '') {
      return [];
    }

    $quoteIds = $this->numericIds($quoteId);
    if ($quoteIds === []) {
      $quoteIds = $this->numericIds((string) ($ticket['id_cotizacion_mantenimiento'] ?? $ticket['id_cotizacion'] ?? ''));
    }
    $rows = $quoteIds === [] ? [] : $this->lockedQuotesByIds($table, $idColumn, $quoteIds);

    if ($rows === [] && $this->schema->columnExists($table, 'id_ticket')) {
      $logicalTicket = trim((string) ($ticket['id_ticket'] ?? ''));
      if ($logicalTicket !== '') {
        $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? FOR UPDATE", [$logicalTicket]);
      }
      if ($rows === [] && (int) ($ticket['_ID'] ?? 0) > 0 && $logicalTicket !== (string) $ticket['_ID']) {
        $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE `id_ticket` = ? FOR UPDATE", [(int) $ticket['_ID']]);
      }
    }

    if ($rows === []) {
      return [];
    }

    $update = $this->schema->filterTableData($table, [
      'estado_trabajo' => 'Trabajo finalizado',
      'estado' => 'Finalizado',
      'id_acta_satisfaccion' => $legacyActId,
      'final_trabajo' => $signedAt,
      'fecha_actualizacion' => $signedAt,
      'cct_modified' => date('Y-m-d H:i:s', $signedAt),
    ]);

    $updatedIds = [];
    foreach ($rows as $row) {
      $rowId = trim((string) ($row[$idColumn] ?? ''));
      if ($rowId === '' || isset($updatedIds[$rowId])) {
        continue;
      }
      $this->db->update($table, $update, [$idColumn => $rowId]);
      $updatedIds[$rowId] = true;
    }
    return array_keys($updatedIds);
  }

  public function isApprovedMaintenanceQuoteForTicket(array $ticket, string $quoteId): bool
  {
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->schema->tableExists($table)) {
      return false;
    }

    $idColumn = $this->schema->detectFirstExistingColumn($table, ['_ID', 'id_cotizacion_mantenimiento', 'id_cotizacion', 'id']);
    if ($idColumn === '') {
      return false;
    }

    $ids = $this->numericIds($quoteId);
    if ($ids === []) {
      return false;
    }
    $rows = $this->lockedQuotesByIds($table, $idColumn, $ids);
    if ($rows === []) {
      return false;
    }

    $ticketKeys = array_values(array_unique(array_filter([
      trim((string) ($ticket['_ID'] ?? '')),
      trim((string) ($ticket['id_ticket'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));
    foreach ($rows as $row) {
      $rowTicket = trim((string) ($row['id_ticket'] ?? ''));
      if ($rowTicket !== '' && $ticketKeys !== [] && !in_array($rowTicket, $ticketKeys, true)) {
        continue;
      }
      foreach (['estado', 'estado_cotizacion_mantenimiento', 'estado_respuesta_cotizacion_mantenimiento', 'estado_respuesta', 'respuesta'] as $column) {
        $value = mb_strtolower(trim((string) ($row[$column] ?? '')), 'UTF-8');
        if (in_array($value, ['aprobada', 'aprobado', 'finalizado'], true)) {
          return true;
        }
      }
    }
    return false;
  }

  /** @return string[] */
  private function numericIds(string $value): array
  {
    preg_match_all('/\d+/', $value, $matches);
    return array_values(array_unique(array_filter($matches[0] ?? [], static fn(string $id): bool => (int) $id > 0)));
  }

  /** @param string[] $ids */
  private function lockedQuotesByIds(string $table, string $idColumn, array $ids): array
  {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return $this->db->getResults("SELECT * FROM `{$table}` WHERE `{$idColumn}` IN ({$placeholders}) FOR UPDATE", $ids);
  }

  public function audit(int $ticketId, string $message, string $name, string $employeeId): void
  {
    $this->insertLegacy('jet_cct_historial_del_ticket', [
      'cct_status' => 'publish', 'id_ticket' => $ticketId, 'fecha' => time(),
      'nombre' => $name, 'respuesta' => $message, 'id_empleado' => $employeeId,
      'cct_author_id' => $employeeId, 'fue_editada' => 'No',
      'cct_created' => date('Y-m-d H:i:s'), 'cct_modified' => date('Y-m-d H:i:s'),
    ]);
  }

  /** Shared by public signing and authenticated ticket actions, including legacy closure paths. */
  public static function locked(Database $db, int $ticketId, callable $callback): mixed
  {
    $key = 'scm-acta-' . substr(hash('sha256', $db->prefix() . ':' . $ticketId), 0, 45);
    if ((int) $db->getVar('SELECT GET_LOCK(?, 10)', [$key]) !== 1) {
      throw new \DomainException('El ticket está siendo actualizado. Intenta nuevamente.');
    }
    try {
      return $callback();
    } finally {
      $db->getVar('SELECT RELEASE_LOCK(?)', [$key]);
    }
  }

  public function transaction(int $ticketId, callable $callback): mixed
  {
    return self::locked($this->db, $ticketId, function () use ($ticketId, $callback) {
      $pdo = $this->db->pdo();
      $pdo->beginTransaction();
      try {
        $ticket = $this->ticket($ticketId, true);
        $result = $callback($ticket);
        $pdo->commit();
        return $result;
      } catch (\Throwable $error) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $error;
      }
    });
  }

  public static function workflowError(Database $db, SchemaInspector $schema, int $ticketId, bool $close, string $adminState = '__keep__', bool $allowReopen = false): string
  {
    $repo = new self($db, $schema);
    if (!$schema->tableExists($repo->table())) {
      return '';
    }
    $act = $repo->active($ticketId);
    if (!$act) {
      return '';
    }
    $currentAdminState = (string) ($repo->ticket($ticketId)['estado_administrativo'] ?? '');
    if ($act['status'] === 'pending' && ($close || !in_array($adminState, ['', '__keep__', $currentAdminState], true))) {
      return 'Este ticket tiene un acta pendiente de firma. Solo se cerrará cuando firme el destinatario. Para cambiar el proceso, anula primero el acta.';
    }
    if (!$allowReopen && $act['status'] === 'signed' && strcasecmp((string) $repo->ticket($ticketId)['estado'], 'Cerrado') === 0) {
      return 'El ticket ya fue cerrado con un acta firmada. Actívalo expresamente antes de registrar nuevas respuestas.';
    }
    return '';
  }
}
