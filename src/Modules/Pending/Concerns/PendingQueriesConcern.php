<?php

declare(strict_types=1);

namespace SCM\Modules\Pending\Concerns;

use SCM\Core\Auth;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

trait PendingQueriesConcern
{
  private function normalizeYesNo(string $value): string
  {
    $v = strtolower(trim($value));
    if (in_array($v, ['si', 'sí', 'yes', '1', 'true', 'activo'], true)) {
      return 'si';
    }
    if (in_array($v, ['no', '0', 'false', 'inactivo'], true)) {
      return 'no';
    }
    return '';
  }

  /** @return array<string,mixed> */
  public function buildPreventivas(array $filters): array
  {
    $funcionarios = $this->repo->getFuncionarios();
    $rows = $this->repo->getContratosEntregados($filters);

    $year = (int) date('Y');
    $fMes = max(0, min(12, (int) ($filters['mes'] ?? 0)));
    $fTuvo = $this->normalizeYesNo((string) ($filters['tuvo'] ?? ''));
    $fEmpleado = trim((string) ($filters['empleado'] ?? ''));

    $items = [];
    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      if ($contractId === '') {
        $contractId = trim((string) ($row['contrato'] ?? ''));
      }

      $ultTs = $this->parseTs($row['ultima_revision_preventiva'] ?? null);
      $fechaEntregaTs = $this->parseTs($row['fecha_entrega'] ?? null);
      $fechaEntregaYear = $fechaEntregaTs > 0 ? (int) date('Y', $fechaEntregaTs) : 0;
      $ultYear = $ultTs > 0 ? (int) date('Y', $ultTs) : 0;

      if ($fechaEntregaYear === $year) {
        continue;
      }
      if ($ultYear === $year) {
        continue;
      }

      $baseTs = $this->firstPositiveTs([
        $row['fecha_entrega'] ?? null,
        $row['inicio_contrato'] ?? null,
        $row['fecha'] ?? null,
      ]);

      if ($ultTs > 0) {
        $dueTs = $this->addMonths($ultTs, 12);
      } else {
        $baseRef = $baseTs > 0 ? $baseTs : time();
        $dueTs = mktime(0, 0, 0, (int) date('n', $baseRef), (int) date('j', $baseRef), $year);
      }

      $dueMonth = $dueTs > 0 ? (int) date('n', $dueTs) : 0;
      if ($fMes > 0 && $fMes !== $dueMonth) {
        continue;
      }

      $tuvo = $this->normalizeYesNo((string) ($row['tuvo_preventiva'] ?? ''));
      if ($fTuvo !== '' && $tuvo !== $fTuvo) {
        continue;
      }

      $idSucursal = trim((string) ($row['id_sucursal'] ?? $row['sucursal'] ?? ''));
      if ($idSucursal === '') {
        $idSucursal = '1';
      }
      $link = 'https://sucasainmobiliaria.com.co/mi-cuenta/anadir-ticket-para-revision-preventiva/?id_contrato=' . rawurlencode($contractId)
        . '&id_inmueble=' . rawurlencode(trim((string) ($row['id_inmueble'] ?? '')))
        . '&id_arrendatario=' . rawurlencode(trim((string) ($row['id_arrendatario'] ?? '')))
        . '&id_propietario=' . rawurlencode(trim((string) ($row['id_propietario'] ?? '')))
        . '&id_sucursal=' . rawurlencode($idSucursal);
      if ($fEmpleado !== '') {
        $link .= '&id_empleado=' . rawurlencode($fEmpleado);
      }

      $items[] = [
        'row' => $row,
        'ultima' => $ultTs,
        'due' => $dueTs,
        'link' => $link,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      $ta = (int) ($a['ultima'] ?? 0);
      $tb = (int) ($b['ultima'] ?? 0);
      if ($ta === $tb) {
        return (int) ((int) (($a['row']['_ID'] ?? 0)) <=> (int) (($b['row']['_ID'] ?? 0)));
      }
      return $ta <=> $tb;
    });

    // Enrich items with existing ticket status (most recent preventiva ticket per contract)
    $contractRows = [];
    foreach ($items as $item) {
      $contractRows[] = $item['row'];
    }
    $tickets = !empty($contractRows)
      ? $this->repo->getTicketsRevisionPreventiva($contractRows)
      : [];
    $allTickets = !empty($contractRows)
      ? $this->repo->getAllTicketsRevisionPreventiva($contractRows)
      : [];
    foreach ($items as &$item) {
      $cid = trim((string) ($item['row']['_ID'] ?? ''));
      $item['ticket'] = $tickets[$cid] ?? null;
      $item['tickets'] = $allTickets[$cid] ?? [];
    }
    unset($item);

    $fCaso = trim((string) ($filters['id_ticket'] ?? ''));
    if ($fCaso !== '') {
      $items = array_values(array_filter($items, static function (array $item) use ($fCaso): bool {
        $ticket = is_array($item['ticket'] ?? null) ? $item['ticket'] : [];
        $haystack = [
          (string) ($ticket['ticket_id'] ?? ''),
          (string) ($ticket['_ID'] ?? ''),
          (string) ($ticket['id_ticket'] ?? ''),
        ];
        foreach ($haystack as $value) {
          if ($value !== '' && stripos($value, $fCaso) !== false) {
            return true;
          }
        }
        return false;
      }));
    }

    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $corte = (string) $year . ($fMes > 0 ? (' — ' . ($months[$fMes] ?? (string) $fMes)) : '');

    return [
      'items' => $items,
      'funcionarios' => $funcionarios,
      'year' => $year,
      'corte' => $corte,
    ];
  }

  /** @return array<string,mixed> */
  public function buildServiciosPublicos(array $filters): array
  {
    $funcionarios = $this->repo->getFuncionarios();
    $rows = $this->repo->getContratosEntregados($filters);

    $nowTs = time();
    $year = (int) date('Y', $nowTs);
    $fMes = max(0, min(12, (int) ($filters['mes'] ?? 0)));

    $items = [];
    foreach ($rows as $row) {
      $contractId = trim((string) ($row['_ID'] ?? ''));
      if ($contractId === '') {
        $contractId = trim((string) ($row['contrato'] ?? ''));
      }

      $ultTs = $this->parseTs($row['ultima_revision_servicios'] ?? null);
      $mesRevisionServicios = (int) ($row['mes_revision_servicios'] ?? 0);

      if ($ultTs > 0 && $mesRevisionServicios >= 1 && $mesRevisionServicios <= 12) {
        $dueTs = $this->replaceMonthPreservingDate($ultTs, $mesRevisionServicios, true);
      } elseif ($ultTs > 0) {
        $dueTs = $ultTs;
      } else {
        $baseTs = $this->firstPositiveTs([
          $row['fecha_entrega'] ?? null,
          $row['inicio_contrato'] ?? null,
          $row['fecha'] ?? null,
        ]);
        if ($mesRevisionServicios >= 1 && $mesRevisionServicios <= 12) {
          $referenceTs = $baseTs > 0 ? $baseTs : $nowTs;
          $dueTs = $this->replaceMonthPreservingDate($referenceTs, $mesRevisionServicios, false, $year);
        } else {
          $dueTs = $baseTs > 0 ? $baseTs : $nowTs;
        }
      }

      $dueMonth = ($mesRevisionServicios >= 1 && $mesRevisionServicios <= 12)
        ? $mesRevisionServicios
        : ($dueTs > 0 ? (int) date('n', $dueTs) : 0);

      if ($fMes > 0 && $fMes !== $dueMonth) {
        continue;
      }

      $items[] = [
        'row' => $row,
        'ultima' => $ultTs,
        'due' => $dueTs,
      ];
    }

    usort($items, static function (array $a, array $b): int {
      $ta = (int) ($a['due'] ?? 0);
      $tb = (int) ($b['due'] ?? 0);
      if ($ta === $tb) {
        return (int) ((int) (($a['row']['_ID'] ?? 0)) <=> (int) (($b['row']['_ID'] ?? 0)));
      }
      return $ta <=> $tb;
    });

    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $corte = (string) $year . ($fMes > 0 ? (' — ' . ($months[$fMes] ?? (string) $fMes)) : '');

    return [
      'items' => $items,
      'funcionarios' => $funcionarios,
      'corte' => $corte,
    ];
  }

  /** @return array<string,mixed> */
  public function buildReportesAdministrativos(array $filters = []): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');
    $funcionarios = $this->repo->getFuncionarios();
    if (!$schema->tableExists($preTable)) {
      return [
        'filters' => $filters,
        'funcionarios' => $funcionarios,
        'items' => [],
        'count' => 0,
      ];
    }

    $items = [];
    foreach ($this->repo->getReportesAdministrativosPorVerificar($filters) as $row) {
      $row = (array) $row;
      $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
      $ticketUrl = $ticketRef !== ''
        ? 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($ticketRef)
        : '';

      $items[] = [
        'row' => $row,
        'ticket_url' => $ticketUrl,
      ];
    }

    return [
      'filters' => $filters,
      'funcionarios' => $funcionarios,
      'items' => $items,
      'count' => count($items),
    ];
  }

  /** @return array<string,string> */
  public function approveReporteAdministrativo(int $preId): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');
    $finalTable = $db->table('jet_cct_reportes_administrativos');

    if (!$schema->tableExists($preTable) || !$schema->tableExists($finalTable)) {
      return ['ok' => '0', 'message' => 'No se encontraron las tablas de reportes administrativos.'];
    }

    $row = $this->repo->getPreReporteAdministrativoById($preId);
    if (!is_array($row)) {
      return ['ok' => '0', 'message' => 'Reporte administrativo no encontrado.'];
    }

    $estado = strtolower(trim((string) ($row['estado'] ?? '')));
    if ($estado !== 'pendiente') {
      return ['ok' => '0', 'message' => 'Este reporte ya no está pendiente.'];
    }

    $payload = $row;
    unset($payload['_ID']);
    $payload['fue_pagado'] = 'No';
    $payload['exportado'] = 'No';
    $payload['cct_status'] = trim((string) ($payload['cct_status'] ?? '')) ?: 'publish';

    $payload = $schema->filterTableData($finalTable, $payload);
    if (empty($payload)) {
      return ['ok' => '0', 'message' => 'No se pudo preparar el reporte para aprobación.'];
    }

    $pdo = $db->pdo();
    $shouldCloseTicket = strtolower(trim((string) ($row['cerrar_ticket'] ?? ''))) === 'si';

    try {
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
      }

      if ($shouldCloseTicket) {
        $closeCandidate = $this->repo->getTicketByReference(trim((string) ($row['id_ticket'] ?? '')));
        if (is_array($closeCandidate) && isset($closeCandidate['_ID'])) {
          // Serialize with signing/creation before approving a report that requests closure.
          $db->getRow('SELECT _ID FROM `' . $db->table('jet_cct_tickets') . '` WHERE _ID = ? FOR UPDATE', [(int) $closeCandidate['_ID']]);
          $actError = \SCM\Modules\TicketCompletion\CompletionRepository::workflowError($db, $schema, (int) $closeCandidate['_ID'], true);
          if ($actError !== '') {
            throw new \DomainException($actError);
          }
        }
      }

      $this->repo->insertReporteAdministrativo($payload);

      $preUpdate = $schema->filterTableData($preTable, [
        'estado' => 'Aprobado',
        'cct_modified' => date('Y-m-d H:i:s'),
      ]);
      if (!empty($preUpdate)) {
        $this->repo->updatePreReporteAdministrativo($preId, $preUpdate);
      }

      if ($shouldCloseTicket) {
        $ticketRef = trim((string) ($row['id_ticket'] ?? ''));
        $ticket = $this->repo->getTicketByReference($ticketRef);
        if (is_array($ticket) && isset($ticket['_ID'])) {
          $ticketsTable = $db->table('jet_cct_tickets');
          $ticketUpdate = $schema->filterTableData($ticketsTable, [
            'estado' => 'Cerrado',
            'estado_administrativo' => 'Finalizado',
            'fecha_actualizacion' => time(),
            'cct_modified' => date('Y-m-d H:i:s'),
          ]);
          if (!empty($ticketUpdate)) {
            $db->update($ticketsTable, $ticketUpdate, ['_ID' => (int) $ticket['_ID']]);
          }
        }
      }

      if ($pdo->inTransaction()) {
        $pdo->commit();
      }
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      return ['ok' => '0', 'message' => 'No se pudo aprobar el reporte: ' . $e->getMessage()];
    }

    return ['ok' => '1', 'message' => 'Reporte administrativo aprobado.'];
  }

  /** @return array<string,string> */
  public function rejectReporteAdministrativo(int $preId): array
  {
    $db = $this->repo->getDb();
    $schema = new SchemaInspector($db);
    $preTable = $db->table('jet_cct_pre_reportes_administrativos');

    if (!$schema->tableExists($preTable)) {
      return ['ok' => '0', 'message' => 'No existe la tabla de pre reportes administrativos.'];
    }

    $row = $this->repo->getPreReporteAdministrativoById($preId);
    if (!is_array($row)) {
      return ['ok' => '0', 'message' => 'Reporte administrativo no encontrado.'];
    }

    $update = $schema->filterTableData($preTable, [
      'estado' => 'Desaprobado',
      'cct_modified' => date('Y-m-d H:i:s'),
    ]);
    if (empty($update)) {
      return ['ok' => '0', 'message' => 'No se pudo actualizar el estado del reporte.'];
    }

    $this->repo->updatePreReporteAdministrativo($preId, $update);
    return ['ok' => '1', 'message' => 'Reporte administrativo desaprobado.'];
  }

  /** @return array<string,mixed> */
  public function buildContratosArrendamiento(array $filters, string $bucket): array
  {
    $data = $this->repo->getContratosArrendamiento($filters, $bucket);
    return [
      'filters' => $filters,
      'bucket' => $bucket,
      'items' => (array) ($data['rows'] ?? []),
      'count' => (int) ($data['total'] ?? 0),
      'pagination' => [
        'page' => (int) ($data['page'] ?? 1),
        'per_page' => (int) ($data['per_page'] ?? 30),
        'total' => (int) ($data['total'] ?? 0),
        'total_pages' => (int) ($data['total_pages'] ?? 1),
      ],
    ];
  }

  /**
   * @param array<string,string> $input
   * @param array<int,string> $imagenes
   * @param array<int,array{nombre_archivo:string,archivo:string,media_archivo?:string}> $documentos
   * @param array<int,string> $notifyRecipients
   * @return array<string,mixed>
   */
}
