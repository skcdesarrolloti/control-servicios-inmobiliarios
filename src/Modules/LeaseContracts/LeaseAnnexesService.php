<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\HtmlPdfRenderer;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;
use SCM\Support\StoredFileService;

/**
 * Envío de anexos del contrato de arrendamiento (antes formulario JetForm "Enviar contrato de arrendamiento"):
 * genera un acta de bienvenida por actor, actualiza el contrato y encola correo y WhatsApp.
 */
final class LeaseAnnexesService
{
  public const INTERNAL_ACTION = 'anexos_contrato_arrendamiento';
  public const WHATSAPP_TEMPLATE = 'scm_anexos_contrato_arrendamiento_v1';
  private const CODEBTOR_OPTIONS = ['Ninguno', '1', '2', '3', '4'];

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,mixed> */
  public function contract(int $contractId): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $row = $contractId > 0 && $this->schema->tableExists($table)
      ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$contractId])
      : null;
    if (!is_array($row)) {
      throw new \DomainException('Contrato no encontrado.');
    }
    return $row;
  }

  /** @param array<string,mixed> $contract @return array<string,mixed> */
  public function branch(array $contract): array
  {
    $table = $this->db->table('jet_cct_sucursales');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $id = trim((string) ($contract['sucursal'] ?? ''));
    $row = $id !== '' ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) : null;
    return is_array($row) ? $row : ($this->db->getRow("SELECT * FROM `{$table}` ORDER BY `_ID` ASC LIMIT 1") ?? []);
  }

  /**
   * El acta de entrega y el inventario son obligatorios en el envío: deben existir y estar publicados.
   *
   * @param array<string,mixed> $contract
   * @return array{acta_entrega:array{id:string,exists:bool,url:string},inventario:array{id:string,exists:bool,url:string}}
   */
  public function annexRecords(array $contract): array
  {
    $sources = [
      'acta_entrega' => ['id_acta_entrega', 'jet_cct_actas_de_entrega', 'https://sucasainmobiliaria.com.co/acta-de-entrega/?numero='],
      'inventario' => ['id_inventario', 'jet_cct_inventario', 'https://sucasainmobiliaria.com.co/inventario-inmueble/?numero='],
    ];
    $out = [];
    foreach ($sources as $key => [$field, $tableName, $base]) {
      $id = trim((string) ($contract[$field] ?? ''));
      $table = $this->db->table($tableName);
      $exists = ctype_digit($id) && (int) $id > 0 && $this->schema->tableExists($table)
        && (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE `_ID` = ? AND COALESCE(`cct_status`, 'publish') = 'publish'", [(int) $id]) > 0;
      $out[$key] = ['id' => $id, 'exists' => $exists, 'url' => $exists ? $base . rawurlencode($id) : ''];
    }
    return $out;
  }

  /** Glosario JetEngine "Razón de desistir" que usa el campo razon_desistido. */
  public const DESIST_GLOSSARY_ID = 1463;
  public const DESIST_INTERNAL_ACTION = 'contrato_desistido';

  /** @return string[] */
  public function desistReasons(): array
  {
    $fallback = ['Arrendatario desistio', 'Propietario desistio', 'No aprobado por aseguradora', 'Documentacion incompleta', 'No pago de valores iniciales', 'No hubo acuerdo económico', 'No hubo acuerdo en condiciones del contrato', 'Inmueble no apto para entrega', 'Falta de respuesta del cliente', 'Falta de acuerdo en reparaciones', 'Demora operativa', 'Error operativo / registro duplicado', 'Motivo personal del cliente', 'Cambio de inmueble'];
    $table = $this->db->table('jet_post_types');
    if (!$this->schema->tableExists($table)) {
      return $fallback;
    }
    $raw = (string) ($this->db->getVar("SELECT `meta_fields` FROM `{$table}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [self::DESIST_GLOSSARY_ID]) ?? '');
    $items = @unserialize($raw, ['allowed_classes' => false]);
    $options = [];
    foreach (is_array($items) ? $items : [] as $item) {
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $options[$value] = $value;
      }
    }
    return $options !== [] ? array_values($options) : $fallback;
  }

  /**
   * Marca el contrato como desistido con un motivo del glosario y deja trazabilidad.
   *
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   * @return array{message:string,internal:int}
   */
  public function desist(int $contractId, string $reason, string $note, array $sender): array
  {
    if (!in_array($reason, $this->desistReasons(), true)) {
      throw new \DomainException('Selecciona el motivo del desistimiento.');
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $contract = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [$contractId]);
      if (!is_array($contract)) {
        throw new \DomainException('Contrato no encontrado.');
      }
      $previous = trim((string) ($contract['estado'] ?? ''));
      if (in_array(strtolower($previous), ['desistido', 'recibido'], true)) {
        throw new \DomainException('El contrato ya está ' . strtolower($previous) . '; no se puede marcar como desistido.');
      }
      $now = time();
      $this->db->update($table, $this->schema->filterTableData($table, [
        'estado' => 'Desistido',
        'razon_desistido' => $reason,
        'cct_modified' => date('Y-m-d H:i:s', $now),
      ]), ['_ID' => $contractId]);
      $summary = 'Contrato #' . (string) ($contract['contrato'] ?? $contractId) . ' marcado como desistido. Motivo: ' . $reason . '. Estado anterior: ' . ($previous !== '' ? $previous : 'sin estado') . '.' . ($note !== '' ? ' Observación: ' . $note : '');
      $this->insertTrace($contract, 'Contrato desistido', 'Contractual', $summary, $sender, $now);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }

    $internal = 0;
    $recipients = InternalNotificationRecipients::emailsForAction($this->db, self::DESIST_INTERNAL_ACTION);
    if ($recipients !== []) {
      $subject = 'Contrato de arrendamiento #' . (string) ($contract['contrato'] ?? $contractId) . ' desistido';
      $body = '<p style="margin:0 0 14px;line-height:1.65;">' . EmailTemplate::e($summary) . '</p>'
        . '<p style="margin:0;line-height:1.65;">Inmueble <b>' . EmailTemplate::e((string) ($contract['inmueble'] ?? '')) . '</b> · ' . EmailTemplate::e((string) ($contract['direccion'] ?? ''))
        . '<br>Propietario: ' . EmailTemplate::e((string) ($contract['propietario'] ?? '-')) . ' · Arrendatario: ' . EmailTemplate::e((string) ($contract['arrendatario'] ?? '-'))
        . '<br>Registrado por <b>' . EmailTemplate::e($sender['name']) . '</b>.</p>';
      $internal = (new EmailQueue($this->db))->enqueue($recipients, $subject, EmailTemplate::render($subject, $body), [
        'source_module' => 'contratos_arrendamiento',
        'internal_cc' => [],
        'destination_name' => 'Equipo interno',
        'dedupe_key' => 'contrato_desistido_' . $contractId . '_' . $now,
        'meta' => ['contract_id' => $contractId, 'internal_action' => self::DESIST_INTERNAL_ACTION],
      ]);
    }
    return ['message' => 'Contrato #' . (string) ($contract['contrato'] ?? $contractId) . ' marcado como desistido.' . ($internal > 0 ? ' Avisos internos en cola: ' . $internal . '.' : ''), 'internal' => $internal];
  }

  /**
   * Marca los anexos como al día sin enviar nada (contratos recibidos cuyos anexos se gestionaron por fuera del panel).
   *
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   */
  public function markAnnexesUpToDate(int $contractId, string $note, array $sender): string
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $contract = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? FOR UPDATE", [$contractId]);
      if (!is_array($contract)) {
        throw new \DomainException('Contrato no encontrado.');
      }
      if (strtolower(trim((string) ($contract['estado'] ?? ''))) !== 'recibido') {
        throw new \DomainException('Solo se marcan al día los anexos de contratos recibidos.');
      }
      if (strtolower(trim((string) ($contract['se_envio'] ?? ''))) === 'si') {
        throw new \DomainException('Los anexos de este contrato ya estaban al día.');
      }
      $now = time();
      $this->db->update($table, $this->schema->filterTableData($table, ['se_envio' => 'Si', 'cct_modified' => date('Y-m-d H:i:s', $now)]), ['_ID' => $contractId]);
      $summary = 'Anexos del contrato #' . (string) ($contract['contrato'] ?? $contractId) . ' marcados al día sin envío (contrato recibido). Valor anterior de envío: ' . (trim((string) ($contract['se_envio'] ?? '')) ?: 'vacío') . '.' . ($note !== '' ? ' Observación: ' . $note : '');
      $this->insertTrace($contract, 'Anexos al día', 'Contractual', $summary, $sender, $now);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return 'Anexos del contrato #' . (string) ($contract['contrato'] ?? $contractId) . ' marcados al día.';
  }

  /** @param array<string,mixed> $contract @param array<string,string> $sender */
  private function insertTrace(array $contract, string $contractType, string $propertyType, string $summary, array $sender, int $now): void
  {
    $nowSql = date('Y-m-d H:i:s', $now);
    $common = [
      'cct_status' => 'publish', 'cct_author_id' => $sender['employee_id'], 'cct_created' => $nowSql, 'cct_modified' => $nowSql,
      'fecha' => $now, 'id_empleado' => $sender['employee_id'], 'funcionario' => $sender['name'],
      'id_inmueble' => trim((string) ($contract['id_inmueble'] ?? '')), 'id_inmueble_data' => trim((string) ($contract['id_inmueble_data'] ?? '')),
    ];
    $contractHistory = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if ($this->schema->tableExists($contractHistory)) {
      $this->db->insert($contractHistory, $this->schema->filterTableData($contractHistory, $common + [
        'tipo_reporte' => $contractType, 'observacion' => $summary, 'inmueble' => trim((string) ($contract['inmueble'] ?? '')),
        'contrato' => trim((string) ($contract['contrato'] ?? '')), 'id_contrato' => (string) ($contract['_ID'] ?? ''),
      ]));
    }
    $propertyHistory = $this->db->table('jet_cct_historial_del_inmueble');
    if ($this->schema->tableExists($propertyHistory)) {
      $this->db->insert($propertyHistory, $this->schema->filterTableData($propertyHistory, $common + ['tipo_reporte' => $propertyType, 'observacion' => $summary]));
    }
  }

  /** Cargos de jet_cct_funcionarios que aparecen en las actas de bienvenida. */
  public const STAFF_CARGOS = ['contractual' => '7', 'propietarios' => '5', 'arrendatarios' => '4', 'comercial' => '12', 'legal' => '14'];

  /** @return array<string,array{nombre:string,celular:string,correo:string,firma:string,cargo:string}> funcionario activo por cargo */
  public function staff(): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $cargos = $this->db->table('jet_cct_cargos');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $hasCargos = $this->schema->tableExists($cargos);
    $rows = $this->db->getResults(
      "SELECT f.`_ID`, TRIM(COALESCE(f.`id_cargo`, '')) AS id_cargo, f.`nombre`, f.`celular`, f.`correo`, f.`firma`"
        . ($hasCargos ? ', c.`nombre_cargo`' : ", '' AS nombre_cargo")
        . " FROM `{$table}` f" . ($hasCargos ? " LEFT JOIN `{$cargos}` c ON CAST(c.`_ID` AS CHAR) = TRIM(COALESCE(f.`id_cargo`, ''))" : '')
        . " WHERE LOWER(TRIM(COALESCE(f.`activo`, ''))) IN ('si', 'sí') AND TRIM(COALESCE(f.`id_cargo`, '')) IN ('" . implode("','", self::STAFF_CARGOS) . "')"
        . ' ORDER BY f.`_ID` ASC'
    );
    $out = [];
    foreach (self::STAFF_CARGOS as $key => $cargoId) {
      foreach ($rows as $row) {
        if ((string) $row['id_cargo'] === $cargoId && trim((string) ($row['nombre'] ?? '')) !== '') {
          $out[$key] = [
            'nombre' => trim((string) $row['nombre']),
            'celular' => trim((string) ($row['celular'] ?? '')),
            'correo' => trim((string) ($row['correo'] ?? '')),
            'firma' => trim((string) ($row['firma'] ?? '')),
            'cargo' => trim((string) ($row['nombre_cargo'] ?? '')),
          ];
          break;
        }
      }
    }
    return $out;
  }

  /** @return array<string,string> claves que usan las actas y los correos */
  private function staffContext(): array
  {
    $staff = $this->staff();
    $get = static fn(string $key, string $field): string => (string) ($staff[$key][$field] ?? '');
    if ($get('legal', 'nombre') === '') {
      throw new \DomainException('No hay un funcionario activo con el cargo 14 (Gerencia General / representante legal) para firmar las actas.');
    }
    return [
      'nombre_contractual' => $get('contractual', 'nombre'), 'celular_contractual' => $get('contractual', 'celular'), 'correo_contractual' => $get('contractual', 'correo'),
      'nombre_propietarios' => $get('propietarios', 'nombre'), 'celular_propietarios' => $get('propietarios', 'celular'), 'correo_propietarios' => $get('propietarios', 'correo'),
      'nombre_arrendatarios' => $get('arrendatarios', 'nombre'), 'celular_arrendatarios' => $get('arrendatarios', 'celular'), 'correo_arrendatarios' => $get('arrendatarios', 'correo'),
      'gerente_comercial' => $get('comercial', 'nombre'), 'celular_ge_comercial' => $get('comercial', 'celular'), 'correo_ge_comercial' => $get('comercial', 'correo'),
      'representante_legal' => $get('legal', 'nombre'), 'celular_legal' => $get('legal', 'celular'), 'correo_legal' => $get('legal', 'correo'),
      'cargo_legal' => $get('legal', 'cargo'), 'firma_legal' => $get('legal', 'firma'),
    ];
  }

  /** @param array<string,mixed> $contract @return array<string,mixed> */
  public function property(array $contract): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    $code = trim((string) ($contract['id_inmueble'] ?? ''));
    if ($code === '' || !$this->schema->tableExists($table)) {
      return [];
    }
    $property = $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`codigo`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [$code]) ?? [];
    if ($property !== []) {
      $property['_photos'] = $this->attachmentUrls(array_merge(
        [(string) ($property['foto_portada'] ?? '')],
        explode(',', (string) ($property['galeria'] ?? ''))
      ));
    }
    return $property;
  }

  /** Ficha por _ID del inmueble (Calculadora de mercado), con las mismas fotos que property(). @return array<string,mixed> */
  public function propertyById(int $id): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if ($id <= 0 || !$this->schema->tableExists($table)) {
      return [];
    }
    $property = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$id]) ?? [];
    if ($property !== []) {
      $property['_photos'] = $this->attachmentUrls(array_merge(
        [(string) ($property['foto_portada'] ?? '')],
        explode(',', (string) ($property['galeria'] ?? ''))
      ));
    }
    return $property;
  }

  /** La portada y la galería guardan IDs de adjuntos de WordPress. @param string[] $refs @return string[] */
  private function attachmentUrls(array $refs): array
  {
    $ids = [];
    $urls = [];
    foreach ($refs as $ref) {
      $ref = trim($ref);
      if (ctype_digit($ref) && (int) $ref > 0) {
        $ids[(int) $ref] = (int) $ref;
      } elseif (filter_var($ref, FILTER_VALIDATE_URL)) {
        $urls[$ref] = $ref;
      }
    }
    $posts = $this->db->table('posts');
    if ($ids !== [] && $this->schema->tableExists($posts)) {
      $rows = $this->db->getResults(
        "SELECT `ID`, `guid` FROM `{$posts}` WHERE `post_type` = 'attachment' AND `ID` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
        array_values($ids)
      );
      $byId = [];
      foreach ($rows as $row) {
        $byId[(int) $row['ID']] = trim((string) ($row['guid'] ?? ''));
      }
      foreach ($ids as $id) {
        if (filter_var($byId[$id] ?? '', FILTER_VALIDATE_URL)) {
          $urls[$byId[$id]] = $byId[$id];
        }
      }
    }
    return array_values($urls);
  }

  /** @return array<int,array<string,string>> */
  public function history(int $contractId): array
  {
    $table = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if ($contractId <= 0 || !$this->schema->tableExists($table)) {
      return [];
    }
    $rows = $this->db->getResults(
      "SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_contrato`, '')) = ? ORDER BY `fecha` DESC, `_ID` DESC LIMIT 60",
      [(string) $contractId]
    );
    return array_map(static fn(array $row): array => [
      'fecha' => (int) ($row['fecha'] ?? 0) > 0 ? date('d/m/Y g:i a', (int) $row['fecha']) : '',
      'tipo' => trim((string) ($row['tipo_reporte'] ?? '')),
      'observacion' => trim((string) ($row['observacion'] ?? '')),
      'funcionario' => trim((string) ($row['funcionario'] ?? '')),
      'imagen' => trim((string) ($row['imagen'] ?? '')),
      'archivo' => trim((string) ($row['archivo'] ?? '')),
    ], $rows);
  }

  /**
   * @param array<string,mixed> $input campos del formulario ya limpios
   * @param array{url:string,path:string}|null $leaseFile contrato de arrendamiento recién cargado
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   * @return array<string,mixed>
   */
  public function send(int $contractId, array $input, ?array $leaseFile, array $sender): array
  {
    $contract = $this->contract($contractId);
    if (strtolower(trim((string) ($contract['estado'] ?? ''))) === 'desistido') {
      throw new \DomainException('El contrato está desistido; no se pueden enviar anexos.');
    }
    $data = $this->validate($input, $contract, $leaseFile);
    $branch = $this->branch($contract);
    $now = time();
    $context = array_merge($contract, $data, [
      'fecha' => $now,
      'ciudad' => 'Cartagena de Indias',
      'realizado_por' => $sender['name'],
      'realizado_por_cargo' => $sender['cargo'],
      'realizado_por_telefono' => $sender['phone'],
      // Razón social de la sucursal: es la que firma el contrato y la que se nombra en la bienvenida.
      'razon_social' => mb_strtoupper(trim((string) ($branch['nombre'] ?? '')) ?: 'Soluciones Comerciales y Constructivas SAS', 'UTF-8'),
    ]);
    $context = array_merge($context, $this->staffContext());

    $expires = max($now + 400 * 86400, (int) $data['fin_contrato'] + 180 * 86400);
    $actors = $this->actors($data, $contract, $contractId, $expires);
    $documents = [];
    try {
      foreach ($actors as $key => $actor) {
        $documents[$key] = $this->generateAct($context, $actor);
      }
      $update = [
        'contrato_arrendamiento' => $data['contrato_arrendamiento'],
        'registro_fotografico' => $data['registro_fotografico'],
        'id_acta_entrega' => $data['id_acta_entrega'],
        'id_inventario' => $data['id_inventario'],
        'inicio_contrato' => $data['inicio_contrato'],
        'fin_contrato' => $data['fin_contrato'],
        'num_codeudores' => $data['num_codeudores'],
        'se_envio' => 'Si',
        'cct_modified' => date('Y-m-d H:i:s', $now),
      ];
      foreach (['propietario', 'arrendatario'] as $role) {
        foreach (['', 'documento_', 'correo_', 'celular_'] as $prefix) {
          $update[$prefix . $role] = $data[$prefix . $role];
        }
      }
      for ($i = 1; $i <= 4; $i++) {
        if ($i <= $data['codebtors']) {
          foreach (['', 'documento_', 'correo_', 'celular_'] as $prefix) {
            $update[$prefix . 'codeudor_' . $i] = $data[$prefix . 'codeudor_' . $i];
          }
        }
      }
      foreach ($documents as $key => $document) {
        $update['acta_' . $key] = $document['url'];
      }
      if (trim((string) ($contract['asegurado'] ?? '')) === '') {
        $update['asegurado'] = 'No';
      }

      $pdo = $this->db->pdo();
      $pdo->beginTransaction();
      try {
        $table = $this->db->table('jet_cct_contratos_arrendamiento');
        $this->db->update($table, $this->schema->filterTableData($table, $update), ['_ID' => $contractId]);
        $recipients = implode(', ', array_map(static fn(array $actor): string => $actor['label'], $actors));
        $summary = 'Se enviaron los anexos del contrato de arrendamiento #' . $data['contrato'] . ' a: ' . $recipients . '.';
        $this->insertHistories($contract, $data, $summary, $sender, $now);
        $pdo->commit();
      } catch (\Throwable $error) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $error;
      }
    } catch (\Throwable $error) {
      foreach ($documents as $document) {
        if (is_file($document['path'])) {
          @unlink($document['path']);
        }
      }
      throw $error;
    }

    $updated = array_merge($contract, $update);
    $queued = $this->queueNotifications($context, $updated, $actors, $documents, $leaseFile, $now);
    return [
      'contract_id' => $contractId,
      'queued' => $queued,
      'actors' => array_values(array_map(static fn(array $actor, string $key): array => [
        'label' => $actor['label'],
        'name' => $actor['name'],
        'act_url' => $documents[$key]['url'],
        'annexes_url' => $actor['annexes_url'],
      ], $actors, array_keys($actors))),
    ];
  }

  /**
   * @param array<string,mixed> $input
   * @param array<string,mixed> $contract
   * @param array{url:string,path:string}|null $leaseFile
   * @return array<string,mixed>
   */
  private function validate(array $input, array $contract, ?array $leaseFile): array
  {
    $text = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    $data = [
      'contrato' => trim((string) ($contract['contrato'] ?? '')),
      'direccion' => trim((string) ($contract['direccion'] ?? '')),
      'registro_fotografico' => $text('registro_fotografico'),
      'id_acta_entrega' => trim((string) ($contract['id_acta_entrega'] ?? '')),
      'id_inventario' => trim((string) ($contract['id_inventario'] ?? '')),
      'inicio_contrato' => $this->date($text('inicio_contrato')),
      'fin_contrato' => $this->date($text('fin_contrato')),
      'contrato_arrendamiento' => $leaseFile['url'] ?? trim((string) ($contract['contrato_arrendamiento'] ?? '')),
    ];
    if ($data['contrato'] === '' || $data['direccion'] === '') {
      throw new \DomainException('El contrato no tiene número o dirección. Complétalos antes de enviar los anexos.');
    }
    if ($data['inicio_contrato'] <= 0 || $data['fin_contrato'] <= 0) {
      throw new \DomainException('Indica la fecha de inicio y la fecha final del contrato.');
    }
    if ($data['fin_contrato'] <= $data['inicio_contrato']) {
      throw new \DomainException('La fecha final debe ser posterior a la fecha de inicio.');
    }
    if (!filter_var($data['registro_fotografico'], FILTER_VALIDATE_URL)) {
      throw new \DomainException('El registro fotográfico debe ser un enlace válido (https://...).');
    }
    $records = $this->annexRecords($contract);
    $missing = [];
    foreach (['acta_entrega' => 'el acta de entrega', 'inventario' => 'el inventario'] as $key => $label) {
      if (!$records[$key]['exists']) {
        $missing[] = $label . ($records[$key]['id'] !== '' ? ' #' . $records[$key]['id'] . ' (no existe)' : '');
      }
    }
    if ($missing !== []) {
      throw new \DomainException('No se pueden enviar los anexos: el contrato no tiene ' . implode(' ni ', $missing) . '. Regístralo antes de enviar.');
    }
    if ($data['contrato_arrendamiento'] === '') {
      throw new \DomainException('Adjunta el contrato de arrendamiento; el arrendatario y los codeudores deben recibirlo.');
    }

    $people = ['propietario' => 'propietario', 'arrendatario' => 'arrendatario'];
    $codebtors = $text('num_codeudores');
    if (!in_array($codebtors, self::CODEBTOR_OPTIONS, true)) {
      throw new \DomainException('Selecciona el número de codeudores.');
    }
    $data['num_codeudores'] = $codebtors;
    $data['codebtors'] = $codebtors === 'Ninguno' ? 0 : (int) $codebtors;
    for ($i = 1; $i <= $data['codebtors']; $i++) {
      $people['codeudor_' . $i] = 'codeudor ' . $i;
    }
    foreach ($people as $field => $label) {
      $data[$field] = $text($field);
      $data['documento_' . $field] = $text('documento_' . $field);
      $data['correo_' . $field] = strtolower($text('correo_' . $field));
      $data['celular_' . $field] = preg_replace('/[^\d+]/', '', $text('celular_' . $field)) ?? '';
      if ($data[$field] === '' || $data['documento_' . $field] === '') {
        throw new \DomainException('Completa el nombre y el documento del ' . $label . '.');
      }
      if (!filter_var($data['correo_' . $field], FILTER_VALIDATE_EMAIL)) {
        throw new \DomainException('El correo del ' . $label . ' no es válido.');
      }
      if (strlen(ltrim($data['celular_' . $field], '+')) < 7) {
        throw new \DomainException('El celular del ' . $label . ' no es válido.');
      }
    }
    return $data;
  }

  /**
   * @param array<string,mixed> $data
   * @param array<string,mixed> $contract
   * @return array<string,array{role:string,index:int,label:string,name:string,email:string,phone:string,annexes_url:string}>
   */
  private function actors(array $data, array $contract, int $contractId, int $expires): array
  {
    $phone = static function (string $phone, string $indicative): string {
      $digits = preg_replace('/\D+/', '', $indicative) ?? '';
      return $digits !== '' && !str_starts_with($phone, '+') ? '+' . $digits . ltrim($phone, '0') : $phone;
    };
    $actors = [];
    foreach (['propietario', 'arrendatario'] as $role) {
      $actors[$role] = [
        'role' => $role, 'index' => 0, 'label' => LeaseAnnexesDocument::ROLES[$role], 'name' => $data[$role],
        'email' => $data['correo_' . $role], 'phone' => $phone($data['celular_' . $role], (string) ($contract['indicativo_' . $role] ?? '')),
        'annexes_url' => LeaseAnnexesDocument::url($contractId, $role, 0, $expires),
      ];
    }
    for ($i = 1; $i <= $data['codebtors']; $i++) {
      $actors['codeudor_' . $i] = [
        'role' => 'codeudor', 'index' => $i, 'label' => 'Codeudor ' . $i, 'name' => $data['codeudor_' . $i],
        'email' => $data['correo_codeudor_' . $i], 'phone' => $data['celular_codeudor_' . $i],
        'annexes_url' => LeaseAnnexesDocument::url($contractId, 'codeudor', $i, $expires),
      ];
    }
    return $actors;
  }

  /**
   * @param array<string,mixed> $context
   * @param array{role:string,index:int,label:string,name:string,annexes_url:string} $actor
   * @return array{url:string,path:string,name:string}
   */
  private function generateAct(array $context, array $actor): array
  {
    $root = dirname(__DIR__, 3);
    $title = 'Acta de bienvenida ' . strtolower($actor['label']) . ' - ' . $actor['name'];
    $bytes = HtmlPdfRenderer::render(LeaseAnnexesDocument::welcome($context, $actor, $actor['annexes_url'], true), $title, [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css', $root . '/public/assets/css/lease-annexes.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) {
      throw new \DomainException('No se pudo generar el acta de ' . strtolower($actor['label']) . '. Verifica el servicio de PDF e intenta nuevamente.');
    }
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
      throw new \RuntimeException('No fue posible guardar el acta en PDF.');
    }
    $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $actor['name']) ?: 'actor'), '-');
    return [
      'url' => StoredFileService::fromRuntime()->urlFor($basename),
      'path' => $path,
      'name' => 'Acta-de-bienvenida-' . strtolower(str_replace(' ', '-', $actor['label'])) . '-' . ($slug !== '' ? $slug : 'actor') . '.pdf',
    ];
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $data @param array<string,string> $sender */
  private function insertHistories(array $contract, array $data, string $summary, array $sender, int $now): void
  {
    $nowSql = date('Y-m-d H:i:s', $now);
    $common = [
      'cct_status' => 'publish',
      'cct_author_id' => $sender['employee_id'],
      'cct_created' => $nowSql,
      'cct_modified' => $nowSql,
      'fecha' => $now,
      'id_empleado' => $sender['employee_id'],
      'funcionario' => $sender['name'],
      'id_inmueble' => trim((string) ($contract['id_inmueble'] ?? '')),
      'id_inmueble_data' => trim((string) ($contract['id_inmueble_data'] ?? '')),
    ];
    $contractHistory = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if ($this->schema->tableExists($contractHistory)) {
      $this->db->insert($contractHistory, $this->schema->filterTableData($contractHistory, $common + [
        'tipo_reporte' => 'Envío de anexos',
        'observacion' => $summary,
        'inmueble' => trim((string) ($contract['inmueble'] ?? '')),
        'contrato' => $data['contrato'],
        'id_contrato' => (string) ($contract['_ID'] ?? ''),
        'archivo' => $data['contrato_arrendamiento'],
      ]));
    }
    $propertyHistory = $this->db->table('jet_cct_historial_del_inmueble');
    if ($this->schema->tableExists($propertyHistory)) {
      $this->db->insert($propertyHistory, $this->schema->filterTableData($propertyHistory, $common + [
        'tipo_reporte' => 'Contrato de arrendamiento',
        'observacion' => $summary,
      ]));
    }
  }

  /**
   * @param array<string,mixed> $context
   * @param array<string,mixed> $contract
   * @param array<string,array<string,mixed>> $actors
   * @param array<string,array{url:string,path:string,name:string}> $documents
   * @param array{url:string,path:string}|null $leaseFile
   * @return array{email:int,whatsapp:int,internal:int}
   */
  private function queueNotifications(array $context, array $contract, array $actors, array $documents, ?array $leaseFile, int $now): array
  {
    $emailQueue = new EmailQueue($this->db);
    $sms = new SmsQueue($this->db);
    $contractNumber = (string) ($contract['contrato'] ?? '');
    $subject = 'Anexos del contrato de arrendamiento #' . $contractNumber;
    $leaseAttachment = $this->localLeaseAttachment($contract, $leaseFile);
    $signature = trim(($context['representante_legal'] ?? '') . (($context['celular_legal'] ?? '') !== '' ? ' | ' . $context['celular_legal'] : '')) . ' - Representante legal';
    $dedupe = 'anexos_contrato_' . (int) ($contract['_ID'] ?? 0) . '_' . $now;
    $queued = ['email' => 0, 'whatsapp' => 0, 'internal' => 0];

    foreach ($actors as $key => $actor) {
      $attachments = [['path' => $documents[$key]['path'], 'name' => $documents[$key]['name']]];
      if ($actor['role'] !== 'propietario' && $leaseAttachment !== null) {
        $attachments[] = $leaseAttachment;
      }
      $buttons = [['url' => $actor['annexes_url'], 'label' => 'Ver anexos']];
      if ($actor['role'] !== 'codeudor') {
        $buttons[] = ['url' => 'https://sucasainmobiliaria.com.co/' . $actor['role'], 'label' => 'Entrar a mi cuenta'];
      }
      $buttons[] = ['url' => 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'];
      $html = EmailTemplate::render($subject, $this->emailBody($actor, $contract, $signature, (string) $context['razon_social']), ['buttons' => $buttons]);
      $queued['email'] += $emailQueue->enqueue($actor['email'], $subject, $html, [
        'source_module' => 'anexos_contrato_arrendamiento',
        // Copia a los funcionarios del evento en cada correo; además reciben el resumen de abajo.
        'internal_action' => self::INTERNAL_ACTION,
        'destination_name' => $actor['name'],
        'dedupe_key' => $dedupe . '_' . $key,
        'payload' => ['attachments' => $attachments, 'reply_to' => (string) ($context['correo_contractual'] ?? '')],
        'meta' => ['contract_id' => (int) ($contract['_ID'] ?? 0), 'recipient_role' => $actor['label'], 'act_url' => $documents[$key]['url']],
      ]);

      $ok = $sms->enqueue($actor['phone'], $actor['name'], 'Anexos del contrato #' . $contractNumber . ': ' . $actor['annexes_url'], [
        'source_module' => 'anexos_contrato_arrendamiento',
        'dedupe_key' => $dedupe . '_whatsapp_' . $key,
        'template_name' => self::WHATSAPP_TEMPLATE,
        'template_language' => 'es_CO',
        'template_components' => [
          ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['link' => $documents[$key]['url'], 'filename' => $documents[$key]['name']]]]],
          ['type' => 'body', 'parameters' => array_map(fn(string $value): array => ['type' => 'text', 'text' => $this->waText($value)], [
            $actor['name'], strtoupper($actor['role']), $contractNumber,
            (string) ($contract['id_inmueble'] ?? $contract['inmueble'] ?? '-'), (string) ($contract['direccion'] ?? '-'),
            $signature,
          ])],
          // Botón "Ver anexos": la plantilla fija https://sucasainmobiliaria.com.co/ y el resto del enlace firmado va como variable.
          ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $this->urlButtonSuffix($actor['annexes_url'])]]],
        ],
        'contract_id' => (int) ($contract['_ID'] ?? 0),
        'document_url' => $documents[$key]['url'],
        'document_filename' => $documents[$key]['name'],
      ]);
      $queued['whatsapp'] += $ok ? 1 : 0;
    }

    $internal = InternalNotificationRecipients::emailsForAction($this->db, self::INTERNAL_ACTION);
    if ($internal !== []) {
      $rows = '';
      foreach ($actors as $key => $actor) {
        $rows .= '<li style="margin:0 0 8px;"><b>' . EmailTemplate::e($actor['label']) . ':</b> ' . EmailTemplate::e($actor['name']) . ' · ' . EmailTemplate::e($actor['email'])
          . ' · <a href="' . EmailTemplate::e($documents[$key]['url']) . '">acta</a> · <a href="' . EmailTemplate::e($actor['annexes_url']) . '">anexos</a></li>';
      }
      $body = '<p style="margin:0 0 14px;line-height:1.65;">Se enviaron los anexos del contrato <b>#' . EmailTemplate::e($contractNumber) . '</b>, ubicado en <b>' . EmailTemplate::e((string) ($contract['direccion'] ?? '')) . '</b>, entregado al arrendatario <b>' . EmailTemplate::e((string) ($contract['arrendatario'] ?? '')) . '</b>.</p>'
        . '<ul style="margin:0 0 14px;padding-left:18px;">' . $rows . '</ul>'
        . '<p style="margin:0;line-height:1.65;">Envío realizado por <b>' . EmailTemplate::e((string) ($context['realizado_por'] ?? '')) . '</b>. Las actas también están adjuntas.</p>';
      $queued['internal'] = $emailQueue->enqueue($internal, $subject, EmailTemplate::render($subject, $body), [
        'source_module' => 'anexos_contrato_arrendamiento',
        'internal_cc' => [],
        'destination_name' => 'Equipo interno',
        'dedupe_key' => $dedupe . '_interno',
        'payload' => ['attachments' => array_values(array_map(static fn(array $document): array => ['path' => $document['path'], 'name' => $document['name']], $documents))],
        'meta' => ['contract_id' => (int) ($contract['_ID'] ?? 0), 'internal_action' => self::INTERNAL_ACTION],
      ]);
    }
    return $queued;
  }

  /** @param array<string,mixed> $actor @param array<string,mixed> $contract */
  private function emailBody(array $actor, array $contract, string $signature, string $company): string
  {
    $annexes = implode(', ', array_slice(LeaseAnnexesDocument::annexLabels($actor['role']), 1));
    $portal = $actor['role'] !== 'codeudor'
      ? '<p style="margin:0 0 14px;line-height:1.65;">Le recordamos que tiene acceso al portal de ' . EmailTemplate::e($actor['role']) . ' usando su <b>número de documento como usuario y contraseña</b>.</p>'
      : '';
    return '<p style="margin:0 0 16px;font-weight:600;">Apreciado(a) ' . EmailTemplate::e($actor['name']) . ':</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">Por medio de la presente le damos la más cordial bienvenida a <b>' . EmailTemplate::e($company) . '</b>, donde ha sido incorporado(a) como <b>' . EmailTemplate::e(strtoupper($actor['role'])) . '</b> del contrato <b>#' . EmailTemplate::e((string) ($contract['contrato'] ?? '')) . '</b>, inmueble ubicado en <b>' . EmailTemplate::e((string) ($contract['direccion'] ?? '')) . '</b>.</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;"><b>Nota:</b> el acta de bienvenida se encuentra adjunta a este correo.</p>'
      . '<p style="margin:0 0 14px;line-height:1.65;">Para consultar los anexos digitales <b>(' . EmailTemplate::e($annexes) . ')</b> use el botón <b>Ver anexos</b>.</p>'
      . $portal
      . '<p style="margin:0 0 14px;line-height:1.65;">¡Descubra nuestra revista digital! Manténgase al día con las mejores oportunidades inmobiliarias, tips exclusivos y tendencias del mercado.</p>'
      . '<p style="margin:0;line-height:1.65;">Cordialmente: <b>' . EmailTemplate::e($signature) . '</b>.</p>';
  }

  /** @param array<string,mixed> $contract @param array{url:string,path:string}|null $leaseFile @return array{path:string,name:string}|null */
  private function localLeaseAttachment(array $contract, ?array $leaseFile): ?array
  {
    $path = $leaseFile['path'] ?? '';
    if ($path === '') {
      parse_str((string) (parse_url((string) ($contract['contrato_arrendamiento'] ?? ''), PHP_URL_QUERY) ?? ''), $query);
      $path = (string) (StoredFileService::fromRuntime()->pathFor((string) ($query['n'] ?? '')) ?? '');
    }
    if ($path === '' || !is_file($path) || filesize($path) > 10 * 1024 * 1024) {
      return null;
    }
    return ['path' => $path, 'name' => 'Contrato-de-arrendamiento-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($contract['contrato'] ?? '')) . '.' . pathinfo($path, PATHINFO_EXTENSION)];
  }

  private function urlButtonSuffix(string $url): string
  {
    $parts = parse_url($url);
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    $query = (string) ($parts['query'] ?? '');
    return strtolower((string) ($parts['host'] ?? '')) === 'sucasainmobiliaria.com.co' && $path !== ''
      ? $path . ($query !== '' ? '?' . $query : '')
      : $url;
  }

  private function date(string $value): int
  {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
      return 0;
    }
    $ts = strtotime($value . ' 00:00:00');
    return $ts === false ? 0 : $ts;
  }

  private function waText(string $text): string
  {
    $text = preg_replace('/[\r\n\t]+/u', ' | ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')) ?? $text;
    $text = preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    $text = trim($text, " \t\n\r\0\x0B|");
    return mb_substr($text !== '' ? $text : '-', 0, 1024, 'UTF-8');
  }
}
