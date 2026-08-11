<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait ResponsibleResolutionConcern
{
  private function resolveResponsible(?array $contract, string $temaAyuda, string $departamento, string $actor = ''): array
  {
    $themeResponsibleIds = self::getCorresponsableForType($temaAyuda, $actor);
    foreach ($themeResponsibleIds as $themeResponsibleId) {
      $themeResponsibleId = trim((string) $themeResponsibleId);
      if ($themeResponsibleId === '') {
        continue;
      }

      $func = $this->findFuncionarioByEmployeeId($themeResponsibleId);
      if (!empty($func)) {
        return $func;
      }

      return [
        'id_empleado' => $themeResponsibleId,
        'nombre' => '',
        'correo' => '',
        'celular' => '',
      ];
    }

    $fromContractId = is_array($contract) ? trim((string) ($contract['id_empleado'] ?? '')) : '';
    if ($fromContractId !== '') {
      $func = $this->findFuncionarioByEmployeeId($fromContractId);
      if (!empty($func)) {
        return $func;
      }
      return [
        'id_empleado' => $fromContractId,
        'nombre' => trim((string) ($contract['funcionario_nombre'] ?? '')),
        'correo' => trim((string) ($contract['funcionario_correo'] ?? '')),
        'celular' => trim((string) ($contract['funcionario_celular'] ?? '')),
      ];
    }

    if (is_array($contract)) {
      $fromLastTicket = $this->findResponsibleFromLastTicket($contract);
      if (!empty($fromLastTicket['id_empleado'])) {
        return $fromLastTicket;
      }
    }

    $fallbackId = $this->findMostFrequentEmployeeId($temaAyuda, $departamento);
    if ($fallbackId === '') {
      $fallbackId = $this->findMostFrequentEmployeeId('', $departamento);
    }
    if ($fallbackId === '') {
      $fallbackId = $this->findMostFrequentEmployeeId('', '');
    }

    if ($fallbackId !== '') {
      $func = $this->findFuncionarioByEmployeeId($fallbackId);
      if (!empty($func)) {
        return $func;
      }
      return ['id_empleado' => $fallbackId, 'nombre' => '', 'correo' => '', 'celular' => ''];
    }

    return ['id_empleado' => '', 'nombre' => '', 'correo' => '', 'celular' => ''];
  }

  /** @return array<string,string> */
  private function findResponsibleFromLastTicket(array $contract): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return ['id_empleado' => '', 'nombre' => '', 'correo' => '', 'celular' => ''];
    }

    $contractPk = trim((string) ($contract['_ID'] ?? ''));
    $contractCode = trim((string) ($contract['contrato'] ?? ''));
    $where = [];
    $args = [];
    if ($contractPk !== '') {
      $where[] = "TRIM(COALESCE(`id_contrato`, '')) = ?";
      $args[] = $contractPk;
    }
    if ($contractCode !== '') {
      $where[] = "TRIM(COALESCE(`id_contrato`, '')) = ?";
      $args[] = $contractCode;
    }
    if (empty($where)) {
      return ['id_empleado' => '', 'nombre' => '', 'correo' => '', 'celular' => ''];
    }

    $sql = "SELECT `id_empleado`, `empleado`, `nombre_empleado`, `correo_empleado`, `celular_empleado`
              FROM `{$ticketsTable}`
             WHERE (" . implode(' OR ', $where) . ")
               AND TRIM(COALESCE(`id_empleado`, '')) <> ''
             ORDER BY `_ID` DESC
             LIMIT 1";
    $row = $this->db->getRow($sql, $args);
    if (!is_array($row)) {
      return ['id_empleado' => '', 'nombre' => '', 'correo' => '', 'celular' => ''];
    }

    $id = trim((string) ($row['id_empleado'] ?? ''));
    if ($id === '') {
      return ['id_empleado' => '', 'nombre' => '', 'correo' => '', 'celular' => ''];
    }

    $func = $this->findFuncionarioByEmployeeId($id);
    if (!empty($func)) {
      return $func;
    }

    return [
      'id_empleado' => $id,
      'nombre' => trim((string) ($row['nombre_empleado'] ?? $row['empleado'] ?? '')),
      'correo' => trim((string) ($row['correo_empleado'] ?? '')),
      'celular' => trim((string) ($row['celular_empleado'] ?? '')),
    ];
  }

  private function findMostFrequentEmployeeId(string $temaAyuda, string $departamento): string
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return '';
    }

    $where = ["TRIM(COALESCE(`id_empleado`, '')) <> ''"];
    $args = [];
    if ($temaAyuda !== '') {
      $where[] = "LOWER(TRIM(COALESCE(`tema_ayuda`, ''))) = ?";
      $args[] = mb_strtolower($temaAyuda, 'UTF-8');
    }
    if ($departamento !== '') {
      $where[] = "LOWER(TRIM(COALESCE(`departamento`, ''))) = ?";
      $args[] = mb_strtolower($departamento, 'UTF-8');
    }

    $sql = "SELECT TRIM(COALESCE(`id_empleado`, '')) AS id_empleado, COUNT(*) AS total
              FROM `{$ticketsTable}`
             WHERE " . implode(' AND ', $where) . "
             GROUP BY `id_empleado`
             ORDER BY total DESC
             LIMIT 1";
    $id = $this->db->getVar($sql, $args);
    return trim((string) $id);
  }

  /** @return array<string,string> */
  private function findFuncionarioByEmployeeId(string $employeeId): array
  {
    $employeeId = trim($employeeId);
    if ($employeeId === '') {
      return [];
    }

    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $row = $this->db->getRow(
      "SELECT `id_empleado`, `nombre`, `correo`, `celular`
         FROM `{$table}`
        WHERE TRIM(COALESCE(`id_empleado`, '')) = ?
        LIMIT 1",
      [$employeeId]
    );
    if (!is_array($row)) {
      return [];
    }

    return [
      'id_empleado' => trim((string) ($row['id_empleado'] ?? '')),
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'correo' => trim((string) ($row['correo'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
    ];
  }

  private function resolveNotificationEmployeeId(string $email, string $phone, string $name): string
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if (!$this->schema->tableExists($table)) {
      return '';
    }

    $email = mb_strtolower(trim($email), 'UTF-8');
    if ($email !== '') {
      $idByEmail = $this->db->getVar(
        "SELECT TRIM(COALESCE(`id_empleado`, ''))
           FROM `{$table}`
          WHERE LOWER(TRIM(COALESCE(`correo`, ''))) = ?
          LIMIT 1",
        [$email]
      );
      $idByEmail = trim((string) $idByEmail);
      if ($idByEmail !== '') {
        return $idByEmail;
      }
    }

    $phoneDigits = $this->normalizeDigits($phone);
    if ($phoneDigits !== '') {
      $idByPhone = $this->db->getVar(
        "SELECT TRIM(COALESCE(`id_empleado`, ''))
           FROM `{$table}`
          WHERE REGEXP_REPLACE(COALESCE(`celular`, ''), '[^0-9]', '') = ?
          LIMIT 1",
        [$phoneDigits]
      );
      $idByPhone = trim((string) $idByPhone);
      if ($idByPhone !== '') {
        return $idByPhone;
      }
    }

    $name = mb_strtolower(trim($name), 'UTF-8');
    if ($name !== '') {
      $idByName = $this->db->getVar(
        "SELECT TRIM(COALESCE(`id_empleado`, ''))
           FROM `{$table}`
          WHERE LOWER(TRIM(COALESCE(`nombre`, ''))) = ?
          LIMIT 1",
        [$name]
      );
      $idByName = trim((string) $idByName);
      if ($idByName !== '') {
        return $idByName;
      }
    }

    return '';
  }

  /**
   * @param array<string,mixed> $config
   * @param array{name:string,email:string,phone:string,indicativo:string,actor_id:string} $contact
   * @param array<string,string> $responsable
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @return array<string,mixed>
   */
}
