<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Modules\LeaseContracts\LeaseAnnexesService;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;

/**
 * Negocio desistido en la hoja de cierre.
 *
 * - desist(): replica el JetForm de WordPress "Desistir negocio" desde la hoja. El contrato "No asignado" queda
 *   Desistido, el inmueble (post y CCT) vuelve a "En borrador", el estudio de aseguradora queda Aprobado con el contrato
 *   desistido (o vuelve a "Esperando respuesta" si es cambio de inmueble) y el caso de entrega se cierra como
 *   Desistido. Además, la hoja queda "Desistida". En lugar de los correos fijos del formulario se avisa a Notificaciones
 *   internas › Negocio desistido.
 * - sync(): las hojas cuyo negocio se desistió por fuera (formulario de WordPress o "Marcar desistido" en Contratos)
 *   pasan a "Desistida". Si la hoja tiene contrato manda el estado del contrato; si no, el estado_contrato del estudio.
 */
final class ClosingSheetDesist
{
  use ClosingSheetRecords;

  public const INTERNAL_ACTION = 'negocio_desistido';
  public const CHANGE_PROPERTY = 'Cambio de inmueble';
  /** Estados desde los que se desiste en la hoja. Con el contrato ya creado ("Realizada") se desiste en Contratos. */
  public const DESISTABLE_STATES = [ClosingSheetService::STATUS_NEW, ClosingSheetWorkflow::STATUS_REVIEWED, ClosingSheetWorkflow::REJECTED, ClosingSheetWorkflow::APPROVED];
  public const AUTO_BY_CONTRACT = 'Automático: contrato desistido';
  public const AUTO_BY_STUDY = 'Automático: estudio desistido';

  private const PROPERTY_URL = 'https://sucasainmobiliaria.com.co/inmueble/?id_inmueble=';

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  public static function canDesist(array $closing): bool
  {
    return in_array(ClosingSheetWorkflow::state($closing), self::DESISTABLE_STATES, true);
  }

  /** Motivos del glosario JetEngine "Razón de desistir" (1463), los mismos del formulario de WordPress. @return list<string> */
  public function reasons(): array
  {
    return (new LeaseAnnexesService($this->db))->desistReasons();
  }

  /**
   * @param array{employee_id:string,name:string,email:string} $actor
   * @return array{message:string,notified:int,contract_id:int,study_id:int,ticket_id:int}
   */
  public function desist(int $closingId, string $reason, string $note, array $actor): array
  {
    $reason = trim($reason);
    $note = trim(mb_substr(trim(strip_tags($note)), 0, 2000, 'UTF-8'));
    if (!in_array($reason, $this->reasons(), true)) {
      throw new \DomainException('Selecciona el motivo por el que se desiste el negocio.');
    }
    $changeProperty = $reason === self::CHANGE_PROPERTY;
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $closing = (new ClosingSheetWorkflow($this->db))->closing($closingId, true);
      $state = ClosingSheetWorkflow::state($closing);
      if (!self::canDesist($closing)) {
        throw new \DomainException(match ($state) {
          ClosingSheetWorkflow::DESISTED => 'La hoja de cierre #' . $closingId . ' ya está desistida.',
          'Realizada' => 'La hoja de cierre #' . $closingId . ' ya tiene contrato: desístelo desde Contratos de arrendamiento con "Marcar desistido".',
          default => 'La hoja de cierre #' . $closingId . ' está "' . $state . '": no se puede desistir.',
        });
      }
      $study = $this->row('jet_cct_estudios_aseguradoras', $closing['id_estudio_aseguradora'] ?? '');
      $contractId = self::id($closing['id_contrato_arrendamiento'] ?? '') ?: self::id($study['id_contrato_mandato_arrendamiento'] ?? '');
      $contract = $this->row('jet_cct_contratos_arrendamiento', $contractId);
      $number = strtolower(trim((string) ($contract['contrato'] ?? '')));
      if ($contract !== [] && $number !== '' && $number !== 'no asignado' && strtolower(trim((string) ($contract['estado'] ?? ''))) !== 'desistido') {
        throw new \DomainException('El contrato #' . trim((string) $contract['contrato']) . ' ya se creó: desístelo desde Contratos de arrendamiento con "Marcar desistido".');
      }
      $summary = 'Negocio desistido desde la hoja de cierre #' . $closingId . '. Motivo: ' . $reason . '.' . ($note !== '' ? ' Observación: ' . $note : '');

      // 1. Contrato "No asignado" del estudio.
      if ($contract !== [] && strtolower(trim((string) ($contract['estado'] ?? ''))) !== 'desistido') {
        $this->update('jet_cct_contratos_arrendamiento', $contractId, ['estado' => 'Desistido', 'razon_desistido' => $reason, 'cct_modified' => $nowSql]);
        $this->insert('jet_cct_historial_contrato_arrendamiento', $actor, $nowSql, [
          'fecha' => (string) $now, 'id_empleado' => $actor['employee_id'], 'funcionario' => $actor['name'], 'tipo_reporte' => 'Contrato desistido',
          'observacion' => $summary . ' Estado anterior: ' . (trim((string) ($contract['estado'] ?? '')) ?: 'sin estado') . '.',
          'id_inmueble' => (string) ($contract['id_inmueble'] ?? $closing['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? $closing['id_inmueble_data'] ?? ''),
          'inmueble' => (string) ($contract['inmueble'] ?? ''), 'contrato' => (string) ($contract['contrato'] ?? ''), 'id_contrato' => (string) $contractId,
        ]);
      }
      // 2 y 3. El inmueble vuelve a borrador (post de WordPress y CCT).
      $this->propertyMeta(self::id($closing['id_inmueble'] ?? ''), ['estado' => 'En borrador']);
      $this->update('jet_cct_inmuebles', self::id($closing['id_inmueble_data'] ?? ''), ['estado' => 'En borrador', 'cct_modified' => $nowSql]);
      // 4. Estudio de aseguradora: aprobado con el contrato desistido, o de nuevo por responder si cambia de inmueble.
      $studyId = self::id($study['_ID'] ?? '');
      if ($studyId > 0) {
        $this->update('jet_cct_estudios_aseguradoras', $studyId, [
          'estado' => $changeProperty ? 'Esperando respuesta' : 'Aprobado', 'estado_contrato' => $changeProperty ? '' : 'Desistido',
          'razon_desistido' => $reason, 'cct_modified' => $nowSql,
        ]);
      }
      // 5. Caso de entrega: cerrado como desistido (el formulario lo hace así), con su historial.
      $ticketId = self::id($study['id_ticket_entrega'] ?? '');
      if ($ticketId > 0 && $this->row('jet_cct_tickets', $ticketId) !== []) {
        $this->update('jet_cct_tickets', $ticketId, ['estado' => 'Cerrado', 'estado_administrativo' => 'Desistido', 'fecha_actualizacion' => (string) $now, 'cct_modified' => $nowSql]);
        $this->insert('jet_cct_historial_del_ticket', $actor, $nowSql, [
          'id_ticket' => (string) $ticketId, 'id_empleado' => $actor['employee_id'], 'nombre' => $actor['name'], 'correo' => $actor['email'],
          'fecha' => (string) $now, 'id_hoja_cierre' => (string) $closingId,
          'respuesta' => '<p>' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>',
        ]);
      }
      // La hoja y el mandato.
      $this->update('jet_cct_cierres', $closingId, [
        'estado' => ClosingSheetWorkflow::DESISTED, 'razon_desistido' => $reason . ($note !== '' ? ' · ' . $note : ''),
        'fecha_desistido' => (string) $now, 'desistido_por' => $actor['name'], 'cct_modified' => $nowSql,
      ]);
      $this->mirrorMandate($closing, $nowSql);
      $this->insert('jet_cct_historial_del_inmueble', $actor, $nowSql, [
        'id_inmueble' => (string) ($closing['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($closing['id_inmueble_data'] ?? ''),
        'id_empleado' => $actor['employee_id'], 'funcionario' => $actor['name'], 'fecha' => (string) $now, 'tipo_reporte' => 'Contractual',
        'observacion' => $summary . ' El inmueble volvió a "En borrador".',
      ]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }

    $notified = 0;
    try {
      $notified = $this->notify($closingId, $reason, $note, $actor, $changeProperty);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_desist_notify] #' . $closingId . ': ' . $error->getMessage());
    }
    return [
      'message' => 'El contrato' . ($contractId > 0 ? ' #' . $contractId : '') . ' quedó desistido, el inmueble volvió a "En borrador"'
        . ($studyId > 0 ? ', el estudio quedó ' . ($changeProperty ? '"Esperando respuesta" para el cambio de inmueble' : 'aprobado con el contrato desistido') : '')
        . ($ticketId > 0 ? ', el caso de entrega #' . $ticketId . ' se cerró' : '') . ' y la hoja quedó "Desistida". Avisos en cola: ' . $notified . '.',
      'notified' => $notified, 'contract_id' => $contractId, 'study_id' => $studyId, 'ticket_id' => $ticketId,
    ];
  }

  /**
   * Hojas cuyo negocio se desistió por fuera de la hoja: quedan "Desistida". Con $contractId solo las de ese contrato;
   * con $closingId solo esa hoja. Devuelve cuántas cambió.
   */
  public function sync(int $contractId = 0, int $closingId = 0): int
  {
    $closings = $this->db->table('jet_cct_cierres');
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $studies = $this->db->table('jet_cct_estudios_aseguradoras');
    if (!$this->schema->tableExists($closings) || !$this->schema->tableExists($contracts) || !$this->schema->tableExists($studies)) {
      return 0;
    }
    $where = '';
    $params = [ClosingSheetWorkflow::DESISTED];
    if ($contractId > 0) {
      $where .= ' AND k.`_ID` = ?';
      $params[] = $contractId;
    }
    if ($closingId > 0) {
      $where .= ' AND c.`_ID` = ?';
      $params[] = $closingId;
    }
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`id_contrato`, k.`_ID` AS contract_id, k.`razon_desistido` AS contract_reason, e.`razon_desistido` AS study_reason
        FROM `{$closings}` c
        LEFT JOIN `{$contracts}` k ON k.`_ID` = c.`id_contrato_arrendamiento`
        LEFT JOIN `{$studies}` e ON e.`_ID` = c.`id_estudio_aseguradora`
        WHERE COALESCE(c.`estado`, '') <> ?
          AND ((k.`_ID` IS NOT NULL AND LOWER(TRIM(COALESCE(k.`estado`, ''))) = 'desistido')
            OR (k.`_ID` IS NULL AND LOWER(TRIM(COALESCE(e.`estado_contrato`, ''))) = 'desistido'))" . $where,
      $params
    );
    $nowSql = date('Y-m-d H:i:s');
    foreach ($rows as $row) {
      $byContract = $row['contract_id'] !== null;
      $this->update('jet_cct_cierres', (int) $row['_ID'], [
        'estado' => ClosingSheetWorkflow::DESISTED, 'razon_desistido' => trim((string) ($byContract ? $row['contract_reason'] : $row['study_reason'])),
        'fecha_desistido' => (string) time(), 'desistido_por' => $byContract ? self::AUTO_BY_CONTRACT : self::AUTO_BY_STUDY, 'cct_modified' => $nowSql,
      ]);
      $this->mirrorMandate(['_ID' => $row['_ID'], 'id_contrato' => $row['id_contrato']], $nowSql);
    }
    return count($rows);
  }

  /** El mandato refleja la hoja solo si es su hoja actual (id_hoja_cierre), o si no tiene ninguna. @param array<string,mixed> $closing */
  private function mirrorMandate(array $closing, string $nowSql): void
  {
    $mandate = $this->row('jet_cct_contrato_mandato', $closing['id_contrato'] ?? '');
    if ($mandate === []) {
      return;
    }
    $current = trim((string) ($mandate['id_hoja_cierre'] ?? ''));
    if ($current === '' || $current === (string) (int) $closing['_ID']) {
      $this->update('jet_cct_contrato_mandato', (int) $mandate['_ID'], ['estado_hoja_cierre' => ClosingSheetWorkflow::DESISTED, 'cct_modified' => $nowSql]);
    }
  }

  /** Correo a Notificaciones internas › Negocio desistido y a quien hizo la hoja, cada uno con su enlace a la hoja. @param array{employee_id:string,name:string,email:string} $actor */
  private function notify(int $closingId, string $reason, string $note, array $actor, bool $changeProperty): int
  {
    $closing = (new ClosingSheetWorkflow($this->db))->closing($closingId);
    $propertyId = trim((string) ($closing['id_inmueble'] ?? ''));
    $subject = 'Negocio desistido · hoja de cierre #' . $closingId . ($propertyId !== '' ? ' · inmueble #' . $propertyId : '');
    $where = trim((string) ($closing['direccion'] ?? ''));
    $contacts = InternalNotificationRecipients::contactsForAction($this->db, self::INTERNAL_ACTION);
    $creatorId = trim((string) ($closing['id_empleado'] ?? ''));
    if ($creatorId !== '' && $creatorId !== $actor['employee_id'] && !in_array($creatorId, array_map(static fn(array $c): string => trim((string) ($c['employee_id'] ?? '')), $contacts), true)) {
      try {
        $creator = (new ClosingSheetWorkflow($this->db))->actor($creatorId);
        $contacts[] = ['employee_id' => $creator['employee_id'], 'name' => $creator['name'], 'email' => $creator['email']];
      } catch (\DomainException) {
        // Quien hizo la hoja ya no está activo: no se le avisa.
      }
    }
    $queue = new EmailQueue($this->db);
    $queued = 0;
    $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $p = static fn(string $html): string => '<p style="margin:0 0 14px;line-height:1.65;">' . $html . '</p>';
    foreach ($contacts as $contact) {
      $employeeId = trim((string) ($contact['employee_id'] ?? ''));
      $email = strtolower(trim((string) ($contact['email'] ?? '')));
      if ($employeeId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $name = trim((string) ($contact['name'] ?? '')) ?: 'Funcionario';
      $html = EmailTemplate::render($subject, $p('Hola, <b>' . $e($name) . '</b>.')
        . $p('<b>' . $e($actor['name']) . '</b> desistió el negocio de la hoja de cierre <b>#' . $closingId . '</b>' . ($where !== '' ? ' del inmueble <b>' . $e($where) . '</b>' : '') . ', con el arrendatario <b>' . $e($closing['arrendatario'] ?? '') . '</b>.')
        . $p('Motivo: <b>' . $e($reason) . '</b>.' . ($note !== '' ? '<br>Observación: ' . nl2br($e($note)) : ''))
        . $p('El inmueble quedó <b>En borrador</b>: revísalo, actualízalo y publícalo de nuevo si requiere promoción.'
          . ($changeProperty ? ' Como es un cambio de inmueble, el estudio volvió a <b>Esperando respuesta</b> para hacer el proceso desde el mismo estudio.' : '')), ['buttons' => array_values(array_filter([
          ['label' => 'Ver hoja de cierre', 'url' => ClosingSheetAccess::url(ClosingSheetAccess::VIEW, $closingId, $employeeId)],
          $propertyId !== '' ? ['label' => 'Ver inmueble', 'url' => self::PROPERTY_URL . rawurlencode($propertyId)] : null,
        ]))]);
      $queued += $queue->enqueue([$email], $subject, $html, [
        'source_module' => 'hoja_cierre', 'internal_action' => self::INTERNAL_ACTION, 'internal_cc' => [], 'destination_name' => $name,
        'dedupe_key' => 'hoja_cierre_' . $closingId . '_desistida_' . $employeeId, 'meta' => ['closing_id' => $closingId, 'internal_action' => self::INTERNAL_ACTION],
      ]);
    }
    return $queued;
  }

  private static function id(mixed $value): int
  {
    $value = trim((string) $value);
    return ctype_digit($value) ? (int) $value : 0;
  }
}
