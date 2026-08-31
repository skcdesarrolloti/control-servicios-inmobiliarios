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
    if ($act['status'] === 'pending' && ($close || !in_array($adminState, ['', '__keep__', CompletionPolicy::WAITING], true))) {
      return 'Este ticket tiene un acta pendiente de firma. Solo se cerrará cuando firme el destinatario. Para cambiar el proceso, anula primero el acta.';
    }
    if (!$allowReopen && $act['status'] === 'signed' && strcasecmp((string) $repo->ticket($ticketId)['estado'], 'Cerrado') === 0) {
      return 'El ticket ya fue cerrado con un acta firmada. Actívalo expresamente antes de registrar nuevas respuestas.';
    }
    return '';
  }
}
