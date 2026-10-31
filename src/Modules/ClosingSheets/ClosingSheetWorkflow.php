<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Modules\Pending\PublicServicesDocument;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

/**
 * Lo que sigue a la hoja de cierre, en el panel (reemplaza los JetForm "Añadir revision de servicios publicos antes
 * de entregar el inmueble" y "Responder hoja de cierre de negocio"):
 *
 * 1. Hoja creada ("En verificacion de servicios"): aviso a los funcionarios de "Hoja de cierre creada" con su enlace
 *    para hacer la revisión.
 * 2. Revisión de servicios antes de la ocupación: revisión (tipo "Antes de la ocupacion"), resultados en la hoja,
 *    cuentas en el mandato, fechas de corte en el contrato de arrendamiento si ya existe, historial y contador del
 *    inmueble. La hoja queda "Esperando respuesta"; aviso al propietario y a "Hoja de cierre por responder".
 * 3. Respuesta: "Aprobada" o "No aprobada" con observación, en la hoja y en el mandato; aviso a "Hoja de cierre
 *    respondida" y a quien la creó. Si una hoja no aprobada se corrige, vuelve a "Esperando respuesta".
 *
 * Los avisos internos salen de Notificaciones internas (sin correos fijos) y cada funcionario recibe su propio enlace
 * firmado (ClosingSheetAccess), para actuar sin iniciar sesión dejando su nombre como autor.
 */
final class ClosingSheetWorkflow
{
  use ClosingSheetRecords;

  public const STATUS_REVIEWED = 'Esperando respuesta';
  public const APPROVED = 'Aprobada';
  public const REJECTED = 'No aprobada';
  /** Estados en los que se puede hacer (o repetir) la revisión de servicios. */
  public const REVIEW_STATES = [ClosingSheetService::STATUS_NEW, self::STATUS_REVIEWED, self::REJECTED];
  /** Estados en los que se puede aprobar o no aprobar. */
  public const RESPOND_STATES = [self::STATUS_REVIEWED, self::REJECTED];

  /** Pestañas del listado (hojas-cierre.php). */
  public const LIST_STATES = [ClosingSheetService::STATUS_NEW, self::STATUS_REVIEWED, self::REJECTED, self::APPROVED, 'Realizada'];

  public const ACTION_TO_RESPOND = 'hoja_cierre_por_responder';
  public const ACTION_RESPONDED = 'hoja_cierre_respondida';

  public const TEMPLATE_REVIEW = 'scm_hoja_cierre_revision_servicios_v1';
  public const TEMPLATE_TO_RESPOND = 'scm_hoja_cierre_por_responder_v1';
  public const TEMPLATE_RESPONSE = 'scm_hoja_cierre_respuesta_v2';
  public const TEMPLATE_OWNER = 'scm_revision_servicios_entrega_propietario_v1';

  /** Resultados en tiempo (glosario 998 del formulario original). */
  public const RESULTS = ['Al dia', '30 dias', '60 dias', 'Estado critico'];
  private const RESULTS_GLOSSARY = 998;

  /** Servicio => [nombre, valor en servicios_publicos, columna de la cuenta en la hoja, nombre de la cuenta, columna en la revisión, sufijo de columnas]. */
  public const SERVICES = [
    'energia' => ['Energía', 'Energia', 'luz', 'NIC', 'nic', 'luz'],
    'agua' => ['Acueducto y alcantarillado', 'Agua', 'agua', 'Póliza', 'poliza', 'agua'],
    'gas' => ['Gas natural', 'Gas', 'gas', 'Contrato', 'numero_contrato', 'gas'],
  ];

  private const TIMEZONE = 'America/Bogota';
  /** Dirección de la oficina en el membrete (la sucursal no la guarda). */
  private const COMPANY_ADDRESS = 'Manga, Calle 28 No. 24-79, Ed. Iman, Of. 303 · Cartagena de Indias';
  private const OWNER_PORTAL = 'https://sucasainmobiliaria.com.co/propietario';

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,mixed> */
  public function closing(int $closingId, bool $lock = false): array
  {
    $table = $this->db->table('jet_cct_cierres');
    $row = $closingId > 0 ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1" . ($lock ? ' FOR UPDATE' : ''), [$closingId]) : null;
    if (!is_array($row)) {
      throw new \DomainException('La hoja de cierre #' . $closingId . ' no existe.');
    }
    return $row;
  }

  public static function state(array $closing): string
  {
    return trim((string) ($closing['estado'] ?? ''));
  }

  public static function canReview(array $closing): bool
  {
    return in_array(self::state($closing), self::REVIEW_STATES, true);
  }

  public static function canRespond(array $closing): bool
  {
    return in_array(self::state($closing), self::RESPOND_STATES, true);
  }

  /**
   * Funcionario activo por su id_empleado real (el que va en cct_author_id y en los historiales).
   * @return array{employee_id:string,name:string,email:string,phone:string,cargo:string,branch:string}
   */
  public function actor(string $employeeId): array
  {
    return $this->funcionario('id_empleado', trim($employeeId))
      ?? throw new \DomainException('El enlace es de un funcionario que ya no está activo. Pide uno nuevo o entra al panel.');
  }

  /** Funcionario de la sesión del panel (Auth::userId() es el _ID de la ficha). @return array{employee_id:string,name:string,email:string,phone:string,cargo:string,branch:string} */
  public function actorByUser(int $userId): array
  {
    return $this->funcionario('_ID', (string) $userId)
      ?? throw new \DomainException('Tu usuario no tiene una ficha de funcionario activa con ID empleado.');
  }

  /** @return array{employee_id:string,name:string,email:string,phone:string,cargo:string,branch:string}|null */
  private function funcionario(string $column, string $value): ?array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    if ($value === '' || !$this->schema->tableExists($table)) {
      return null;
    }
    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `{$column}` = ? AND LOWER(TRIM(COALESCE(`activo`, ''))) = 'si' LIMIT 1", [$value]);
    if (!is_array($row) || trim((string) ($row['id_empleado'] ?? '')) === '') {
      return null;
    }
    $cargo = '';
    $cargos = $this->db->table('jet_cct_cargos');
    if (trim((string) ($row['id_cargo'] ?? '')) !== '' && $this->schema->tableExists($cargos)) {
      $cargo = trim((string) $this->db->getVar("SELECT `nombre_cargo` FROM `{$cargos}` WHERE `_ID` = ? LIMIT 1", [(int) $row['id_cargo']]));
    }
    return [
      'employee_id' => trim((string) $row['id_empleado']),
      'name' => trim((string) ($row['nombre'] ?? '')) ?: 'Funcionario',
      'email' => trim((string) ($row['correo'] ?? '')),
      'phone' => trim((string) ($row['celular'] ?? $row['telefono'] ?? '')),
      'cargo' => $cargo,
      'branch' => trim((string) ($row['id_sucursal'] ?? '')),
    ];
  }

  /** @return list<string> */
  public function resultOptions(): array
  {
    $table = $this->db->table('jet_post_types');
    $items = $this->schema->tableExists($table)
      ? @unserialize((string) ($this->db->getVar("SELECT `meta_fields` FROM `{$table}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [self::RESULTS_GLOSSARY]) ?? ''), ['allowed_classes' => false])
      : null;
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $out[$value] = $value;
      }
    }
    return $out !== [] ? array_values($out) : self::RESULTS;
  }

  /**
   * Datos para el formulario de revisión: cada servicio con lo último guardado en la hoja.
   * @return array{closing:array<string,mixed>,services:array<string,array<string,mixed>>,results:list<string>,last_review:array<string,mixed>|null}
   */
  public function reviewForm(int $closingId): array
  {
    $closing = $this->closing($closingId);
    $listed = self::serviceList($closing['servicios_publicos'] ?? '');
    $services = [];
    foreach (self::SERVICES as $key => [$label, $listValue, $accountColumn, $accountLabel, , $suffix]) {
      $account = trim((string) ($closing[$accountColumn] ?? ''));
      $cutoff = (int) ($closing['fecha_corte_' . $suffix] ?? 0);
      $services[$key] = [
        'label' => $label, 'account_label' => $accountLabel, 'account' => $account,
        'meter' => trim((string) ($closing['medidor_' . $suffix] ?? '')),
        'cutoff' => $cutoff > 0 ? (new \DateTimeImmutable('@' . $cutoff))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d') : '',
        'status' => trim((string) ($closing['resultado_tiempo_' . $suffix] ?? '')),
        'value' => trim((string) ($closing['resultado_valores_' . $suffix] ?? '')),
        // Sin lista de servicios en la hoja, se marcan los que tienen número de cuenta.
        'checked' => $listed !== [] ? in_array($listValue, $listed, true) : $account !== '',
      ];
    }
    return ['closing' => $closing, 'services' => $services, 'results' => $this->resultOptions(), 'last_review' => $this->latestReview($closingId)];
  }

  /**
   * Hoja para verla en solo lectura: valores como en el formulario de edición, copropietarios y la última revisión.
   * @return array{closing:array<string,mixed>,values:array<string,mixed>,owners:list<array<string,mixed>>,options:array<string,mixed>,review:array<string,mixed>|null,results:list<array<string,mixed>>}
   */
  public function sheet(int $closingId): array
  {
    $closing = $this->closing($closingId);
    $service = new ClosingSheetService($this->db);
    try {
      $context = $service->context([], $closingId);
      $values = $context['values'];
      $owners = $context['owners'];
    } catch (\DomainException) {
      // Hoja sin mandato: se muestra lo guardado tal cual.
      $values = $closing + ['__notes' => []];
      $owners = [];
    }
    $review = $this->latestReview($closingId);
    $reviewDate = (int) ($review['fecha'] ?? 0);
    return [
      'closing' => $closing, 'values' => $values, 'owners' => $owners, 'options' => $service->options(), 'review' => $review,
      'results' => $review !== null ? self::results($review) : self::results($closing, 'closing'),
      // Cierre del documento, como la vista de WordPress: consultor, quien verifica y quien autoriza.
      'signers' => [
        ['role' => 'Consultor de arriendos', 'action' => 'Elaboró', 'date' => (int) ($closing['fecha'] ?? 0)] + $this->signer((string) ($closing['id_empleado'] ?? ''), (string) ($closing['creador'] ?? '')),
        ['role' => 'Revisión de servicios', 'action' => 'Verificó', 'date' => $reviewDate] + $this->signer((string) ($closing['id_verificador'] ?? ''), (string) ($closing['verificador'] ?? '')),
        ['role' => 'Gerencia', 'action' => 'Autorizó', 'date' => (int) ($closing['fecha_repuesta'] ?? 0)] + $this->signer((string) ($closing['id_aprobador'] ?? ''), (string) ($closing['aprobador'] ?? '')),
      ],
      'company' => $this->company((string) ($closing['sucursal'] ?? '')),
    ];
  }

  /** Nombre, cargo y firma (imagen) de un funcionario, activo o no. @return array{name:string,cargo:string,signature:string} */
  private function signer(string $employeeId, string $fallbackName): array
  {
    $table = $this->db->table('jet_cct_funcionarios');
    $row = trim($employeeId) !== '' && $this->schema->tableExists($table)
      ? $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_empleado` = ? LIMIT 1", [trim($employeeId)])
      : null;
    $cargo = '';
    $cargos = $this->db->table('jet_cct_cargos');
    if (is_array($row) && trim((string) ($row['id_cargo'] ?? '')) !== '' && $this->schema->tableExists($cargos)) {
      $cargo = trim((string) $this->db->getVar("SELECT `nombre_cargo` FROM `{$cargos}` WHERE `_ID` = ? LIMIT 1", [(int) $row['id_cargo']]));
    }
    $signature = trim((string) ($row['firma'] ?? ''));
    return [
      'name' => trim($fallbackName) !== '' ? trim($fallbackName) : trim((string) ($row['nombre'] ?? '')),
      'cargo' => $cargo,
      'signature' => filter_var($signature, FILTER_VALIDATE_URL) && str_starts_with($signature, 'https://') ? $signature : '',
    ];
  }

  /** Datos de la empresa para el membrete (sucursal de la hoja o la principal). @return array{nit:string,phone:string,email:string,address:string} */
  private function company(string $branchId): array
  {
    $table = $this->db->table('jet_cct_sucursales');
    $row = null;
    if ($this->schema->tableExists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [ctype_digit(trim($branchId)) && (int) $branchId > 0 ? (int) $branchId : 1]);
    }
    $nit = preg_replace('/\D+/', '', (string) ($row['nit'] ?? '')) ?: '9006232424';
    return [
      'nit' => strlen($nit) === 10 ? number_format((int) substr($nit, 0, 9), 0, ',', '.') . '-' . substr($nit, 9) : $nit,
      'phone' => trim((string) ($row['celular'] ?? '')),
      'email' => trim((string) ($row['correo'] ?? '')),
      'address' => self::COMPANY_ADDRESS,
    ];
  }

  /**
   * Listado por estado, las 100 más recientes, con búsqueda por número, inmueble, dirección o personas.
   * @return array{counts:array<string,int>,rows:list<array<string,mixed>>}
   */
  public function listing(string $state, string $query): array
  {
    $table = $this->db->table('jet_cct_cierres');
    $counts = [];
    foreach ($this->db->getResults("SELECT TRIM(COALESCE(`estado`, '')) AS estado, COUNT(1) AS total FROM `{$table}` GROUP BY TRIM(COALESCE(`estado`, ''))") as $row) {
      $counts[(string) $row['estado']] = (int) $row['total'];
    }
    $where = "TRIM(COALESCE(`estado`, '')) = ?";
    $args = [$state];
    $query = trim($query);
    if ($query !== '') {
      $like = '%' . addcslashes($query, '%_\\') . '%';
      $where .= ' AND (CAST(`_ID` AS CHAR) = ? OR `id_inmueble` = ? OR `direccion` LIKE ? OR `arrendatario` LIKE ? OR `propietario` LIKE ?)';
      array_push($args, $query, $query, $like, $like, $like);
    }
    $rows = $this->db->getResults("SELECT `_ID`, `fecha`, `estado`, `id_inmueble`, `direccion`, `arrendatario`, `creador`, `verificador`, `aprobador` FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT 100", $args);
    return ['counts' => $counts, 'rows' => $rows];
  }

  /** @return array<string,mixed>|null */
  public function latestReview(int $closingId): ?array
  {
    $table = $this->db->table('jet_cct_revisiones_servicios');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_cierre')) {
      return null;
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `id_cierre` = ? ORDER BY `_ID` DESC LIMIT 1", [(string) $closingId]);
  }

  /**
   * Registra la revisión de servicios antes de la ocupación.
   * @param array<string,mixed> $input @param array{employee_id:string,name:string,email:string,phone:string,cargo:string,branch:string} $actor
   * @return array{review_id:int,state:string,summary:string,notified:int}
   */
  public function saveReview(int $closingId, array $input, array $actor): array
  {
    $results = $this->resultOptions();
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $closing = $this->closing($closingId, true);
      if (!self::canReview($closing)) {
        throw new \DomainException('La hoja de cierre #' . $closingId . ' está "' . self::state($closing) . '": ya no admite revisión de servicios.');
      }
      $checked = is_array($input['revisar'] ?? null) ? $input['revisar'] : [];
      $services = [];
      $errors = [];
      foreach (self::SERVICES as $key => [$label, , , $accountLabel]) {
        if (empty($checked[$key])) {
          continue;
        }
        $service = [
          'account' => self::clean($input['cuenta_' . $key] ?? ''), 'meter' => self::clean($input['medidor_' . $key] ?? ''),
          'cutoff' => self::dateTimestamp((string) ($input['corte_' . $key] ?? '')), 'status' => self::clean($input['resultado_' . $key] ?? ''),
          'value' => self::clean($input['valor_' . $key] ?? ''),
        ];
        $missing = array_keys(array_filter([$accountLabel => $service['account'] === '', 'medidor' => $service['meter'] === '', 'fecha de corte' => $service['cutoff'] === 0, 'resultado en tiempo' => $service['status'] === '', 'resultado en valores' => $service['value'] === '']));
        if ($missing !== []) {
          $errors[] = $label . ': falta ' . implode(', ', $missing) . '.';
          continue;
        }
        if (!in_array($service['status'], $results, true)) {
          $errors[] = $label . ': "' . $service['status'] . '" no es un resultado válido.';
          continue;
        }
        if ($service['status'] !== 'Al dia') {
          // En mora el valor es lo adeudado, en pesos.
          $digits = preg_replace('/\D+/', '', $service['value']) ?? '';
          if ($digits === '' || (int) $digits <= 0) {
            $errors[] = $label . ': escribe el valor adeudado (mayor que 0) porque el resultado es "' . $service['status'] . '".';
            continue;
          }
          $service['value'] = ltrim($digits, '0');
        }
        $services[$key] = $service;
      }
      if ($errors !== []) {
        throw new \DomainException(implode(' ', $errors));
      }
      if ($services === []) {
        throw new \DomainException('Marca al menos un servicio para revisar.');
      }

      $propertyPost = (int) ($closing['id_inmueble'] ?? 0);
      $count = $this->reviewCount($propertyPost) + 1;
      $review = [
        'id_inmueble' => (string) ($closing['id_inmueble'] ?? ''), 'id_contrato' => (string) ($closing['id_contrato_arrendamiento'] ?? ''),
        'id_contrato_mandato' => (string) ($closing['id_contrato'] ?? ''), 'id_cierre' => (string) $closingId,
        'id_estudio_aseguradora' => (string) ($closing['id_estudio_aseguradora'] ?? ''), 'numero_solicitud' => (string) ($closing['numero_solicitud'] ?? ''),
        'fecha' => (string) $now, 'fecha_formateada' => date('d-m-Y', $now), 'contrato' => 'Contrato sin entregar', 'inmueble' => 'Inmueble sin entregar',
        'direccion' => (string) ($closing['direccion'] ?? ''), 'arrendatario' => (string) ($closing['arrendatario'] ?? ''), 'propietario' => (string) ($closing['propietario'] ?? ''),
        'id_empleado' => $actor['employee_id'], 'realizado_por' => $actor['name'], 'sucursal' => $actor['branch'],
        'revisiones_servicios' => (string) $count, 'tipo_inmueble' => (string) ($closing['tipo_inmueble'] ?? ''),
        'tipo_negocio' => (string) ($closing['gestion_inmueble'] ?? ''), 'destinacion' => (string) ($closing['destinacion_inmueble'] ?? ''),
        'tipo' => 'Antes de la ocupacion',
      ];
      $closingData = ['id_verificador' => $actor['employee_id'], 'verificador' => $actor['name'], 'estado' => self::STATUS_REVIEWED, 'cct_modified' => $nowSql];
      $contractData = ['ultima_revision_servicios' => (string) $now, 'cct_modified' => $nowSql];
      $mandateData = [];
      foreach ($services as $key => $service) {
        [, , $accountColumn, , $reviewAccount, $suffix] = self::SERVICES[$key];
        $review += [
          $reviewAccount => $service['account'], 'medidor_' . $suffix => $service['meter'], 'fecha_revision_' . $suffix => (string) $now,
          'fecha_corte_' . $suffix => (string) $service['cutoff'], 'resultado_tiempo_' . $suffix => $service['status'], 'resultado_valores_' . $suffix => $service['value'],
        ];
        $closingData += [
          $accountColumn => $service['account'], 'medidor_' . $suffix => $service['meter'], 'fecha_revision_' . $suffix => (string) $now,
          'fecha_corte_' . $suffix => (string) $service['cutoff'], 'resultado_tiempo_' . $suffix => $service['status'], 'resultado_valores_' . $suffix => $service['value'],
        ];
        $contractData['fecha_corte_' . $suffix] = (string) $service['cutoff'];
        // Las cuentas de la hoja también quedan en el mandato.
        $mandateData[ClosingSheetService::MANDATE_SYNC[$accountColumn]] = $service['account'];
      }
      $reviewId = $this->insert('jet_cct_revisiones_servicios', $actor, $nowSql, $review);
      $closingData['id_revision_servicios_publicos'] = (string) $reviewId;
      $this->update('jet_cct_cierres', $closingId, $closingData);
      $this->update('jet_cct_contrato_mandato', (int) ($closing['id_contrato'] ?? 0), $mandateData + ['cct_modified' => $nowSql]);
      $leaseId = (int) ($closing['id_contrato_arrendamiento'] ?? 0);
      if ($leaseId > 0 && $this->row('jet_cct_contratos_arrendamiento', $leaseId) !== []) {
        $this->update('jet_cct_contratos_arrendamiento', $leaseId, $contractData);
      }
      $this->insert('jet_cct_historial_del_inmueble', $actor, $nowSql, [
        'id_inmueble' => (string) ($closing['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($closing['id_inmueble_data'] ?? ''),
        'id_empleado' => $actor['employee_id'], 'funcionario' => $actor['name'], 'fecha' => (string) $now, 'tipo_reporte' => 'Contractual',
        'observacion' => 'Se ha realizado revisión de servicios públicos antes de la entrega de su inmueble (hoja de cierre #' . $closingId . ').',
        'id_revision_servicios_publicos' => (string) $reviewId, 'id_revision_sp' => (string) $reviewId,
      ]);
      $this->propertyMeta($propertyPost, ['revisiones-servicios' => (string) $count]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    $summary = self::summaryText($services);
    return [
      'review_id' => $reviewId,
      'state' => self::STATUS_REVIEWED,
      'summary' => $summary,
      'notified' => $this->safely('review', $closingId, fn(): int => $this->notifyReview($closingId, $reviewId, $services, $actor)),
    ];
  }

  /**
   * Aprueba o no aprueba la hoja.
   * @param array<string,mixed> $input @param array{employee_id:string,name:string,email:string,phone:string,cargo:string,branch:string} $actor
   * @return array{state:string,notified:int}
   */
  public function respond(int $closingId, array $input, array $actor): array
  {
    $state = trim((string) ($input['estado'] ?? ''));
    $note = trim(mb_substr(trim(strip_tags((string) ($input['observacion'] ?? ''))), 0, 2000, 'UTF-8'));
    if (!in_array($state, [self::APPROVED, self::REJECTED], true)) {
      throw new \DomainException('Elige si la hoja queda aprobada o no aprobada.');
    }
    if ($state === self::REJECTED && $note === '') {
      throw new \DomainException('Escribe por qué no se aprueba: lo recibe quien hizo la hoja para corregirla.');
    }
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $closing = $this->closing($closingId, true);
      if (!self::canRespond($closing)) {
        throw new \DomainException(self::state($closing) === ClosingSheetService::STATUS_NEW
          ? 'La hoja de cierre #' . $closingId . ' todavía no tiene la revisión de servicios públicos.'
          : 'La hoja de cierre #' . $closingId . ' ya está "' . self::state($closing) . '".');
      }
      $this->update('jet_cct_cierres', $closingId, [
        'estado' => $state, 'fecha_repuesta' => (string) $now, 'id_aprobador' => $actor['employee_id'], 'aprobador' => $actor['name'],
        'observacion_respuesta' => $note, 'cct_modified' => $nowSql,
      ]);
      $this->update('jet_cct_contrato_mandato', (int) ($closing['id_contrato'] ?? 0), ['estado_hoja_cierre' => $state, 'cct_modified' => $nowSql]);
      $this->insert('jet_cct_historial_del_inmueble', $actor, $nowSql, [
        'id_inmueble' => (string) ($closing['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($closing['id_inmueble_data'] ?? ''),
        'id_empleado' => $actor['employee_id'], 'funcionario' => $actor['name'], 'fecha' => (string) $now, 'tipo_reporte' => 'En cierre',
        'observacion' => 'La hoja de cierre #' . $closingId . ' quedó ' . mb_strtolower($state, 'UTF-8') . '.' . ($note !== '' ? ' Observación: ' . $note : ''),
      ]);
      $pdo->commit();
    } catch (\Throwable $error) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $error;
    }
    return ['state' => $state, 'notified' => $this->safely('response', $closingId, fn(): int => $this->notifyResponse($closingId, $actor))];
  }

  /**
   * Una hoja no aprobada que se corrige vuelve a "Esperando respuesta" (lo llama ClosingSheetService al editar, dentro
   * de su transacción). Devuelve true si cambió.
   */
  public function resubmitIfRejected(array $closing, string $nowSql): bool
  {
    if (self::state($closing) !== self::REJECTED) {
      return false;
    }
    $this->update('jet_cct_cierres', (int) $closing['_ID'], ['estado' => self::STATUS_REVIEWED, 'cct_modified' => $nowSql]);
    $this->update('jet_cct_contrato_mandato', (int) ($closing['id_contrato'] ?? 0), ['estado_hoja_cierre' => self::STATUS_REVIEWED, 'cct_modified' => $nowSql]);
    return true;
  }

  // ---------------------------------------------------------------- avisos

  /** Hoja creada: a los funcionarios de "Hoja de cierre creada" con su enlace de revisión, y copia a quien la creó. @param array{employee_id:string,name:string,email:string} $sender */
  public function notifyCreated(int $closingId, array $sender): int
  {
    $closing = $this->closing($closingId);
    $subject = 'Hoja de cierre #' . $closingId . ' · revisión de servicios pendiente';
    $where = self::where($closing);
    $services = implode(', ', array_map(static fn(string $key): string => self::SERVICES[$key][0], $this->listedServices($closing))) ?: 'sin servicios marcados';
    $queued = $this->notifyStaff(ClosingSheetService::INTERNAL_ACTION, $closingId, ClosingSheetAccess::REVIEW, $subject,
      fn(string $name): string => self::p('Hola, <b>' . self::e($name) . '</b>.')
        . self::p('<b>' . self::e($sender['name']) . '</b> creó la hoja de cierre de negocio <b>#' . $closingId . '</b> del ' . $where . ', con el arrendatario <b>' . self::e($closing['arrendatario'] ?? '') . '</b>.')
        . self::p('Sigue la <b>revisión de servicios públicos antes de la ocupación</b>. Servicios de la hoja: ' . self::e($services) . '.')
        . self::p('El botón abre la revisión con tu usuario, sin iniciar sesión. Vence en 30 días.'),
      'Realizar revisión de servicios', self::TEMPLATE_REVIEW,
      fn(string $name): array => [$name, '#' . $closingId, self::place($closing), (string) ($closing['arrendatario'] ?? ''), $sender['name']],
      'Hoja de cierre #' . $closingId . ': realiza la revisión de servicios públicos.');
    if (filter_var($sender['email'], FILTER_VALIDATE_EMAIL)) {
      $html = EmailTemplate::render($subject, self::p('Hola, <b>' . self::e($sender['name']) . '</b>.')
        . self::p('Tu hoja de cierre de negocio <b>#' . $closingId . '</b> del ' . $where . ' quedó creada y en <b>' . self::e(ClosingSheetService::STATUS_NEW) . '</b>.')
        . self::p('Te avisaremos cuando la respondan.'), ['buttons' => [
          ['label' => 'Ver hoja de cierre', 'url' => ClosingSheetAccess::url(ClosingSheetAccess::VIEW, $closingId, $sender['employee_id'])],
        ]]);
      $queued += (new EmailQueue($this->db))->enqueue([$sender['email']], $subject, $html, [
        'source_module' => 'hoja_cierre', 'internal_cc' => [], 'destination_name' => $sender['name'], 'dedupe_key' => 'hoja_cierre_' . $closingId . '_creador',
        'meta' => ['closing_id' => $closingId],
      ]);
    }
    return $queued;
  }

  /** @param array<string,array<string,mixed>> $services @param array{employee_id:string,name:string,email:string,phone:string,cargo:string} $actor */
  private function notifyReview(int $closingId, int $reviewId, array $services, array $actor): int
  {
    $closing = $this->closing($closingId);
    $reviewUrl = PublicServicesDocument::url($reviewId);
    $table = self::resultsTable($services);
    $summary = self::summaryText($services);
    $signature = self::signature($actor);
    $queued = 0;

    $propertyId = (string) ($closing['id_inmueble'] ?? '');
    $ownerSubject = 'Revisión de servicios públicos de su inmueble #' . $propertyId . ' antes de la entrega';
    foreach ($this->owners($closing) as $index => $owner) {
      if (filter_var($owner['email'], FILTER_VALIDATE_EMAIL)) {
        $html = EmailTemplate::render($ownerSubject, self::p('Apreciado(a) <b>' . self::e($owner['name']) . '</b>:')
          . self::p('En el proceso de entrega de su inmueble <b>#' . self::e($propertyId) . '</b>, ubicado en <b>' . self::e($closing['direccion'] ?? '') . '</b>, realizamos la revisión de los servicios públicos para verificar su estado y mantener actualizadas las cuentas asociadas a su propiedad.')
          . $table
          . self::p('Recuerde que tiene acceso al portal propietario usando su <b>número de documento como usuario y contraseña</b>.')
          . self::p('Atentamente,<br><b>' . self::e($signature) . '</b>'), ['buttons' => [
            ['label' => 'Ver revisión de servicios', 'url' => $reviewUrl], ['label' => 'Entrar a mi cuenta', 'url' => self::OWNER_PORTAL],
          ]]);
        $queued += (new EmailQueue($this->db))->enqueue([$owner['email']], $ownerSubject, $html, [
          'source_module' => 'hoja_cierre', 'internal_cc' => [], 'destination_name' => $owner['name'], 'dedupe_key' => 'hoja_cierre_revision_' . $reviewId . '_propietario_' . $index,
          'payload' => ['reply_to' => $actor['email']], 'meta' => ['closing_id' => $closingId, 'review_id' => $reviewId, 'recipient_role' => 'propietario'],
        ]);
      }
      if ($owner['phone'] !== '') {
        $queued += $this->whatsapp($owner['phone'], $owner['name'], self::TEMPLATE_OWNER, [$owner['name'], (string) ($closing['direccion'] ?? ''), $summary, $signature], $reviewUrl,
          'hoja_cierre_revision_' . $reviewId . '_wa_propietario_' . $index, 'Revisión de servicios públicos de su inmueble: ' . $reviewUrl, $closingId) ? 1 : 0;
      }
    }

    $subject = 'Hoja de cierre #' . $closingId . ' por responder';
    $where = self::where($closing);
    $queued += $this->notifyStaff(self::ACTION_TO_RESPOND, $closingId, ClosingSheetAccess::RESPOND, $subject,
      fn(string $name): string => self::p('Hola, <b>' . self::e($name) . '</b>.')
        . self::p('<b>' . self::e($actor['name']) . '</b> realizó la revisión de servicios públicos antes de la ocupación de la hoja de cierre <b>#' . $closingId . '</b> del ' . $where . ', con el arrendatario <b>' . self::e($closing['arrendatario'] ?? '') . '</b>.')
        . $table
        . self::p('La hoja quedó <b>' . self::e(self::STATUS_REVIEWED) . '</b>. El botón abre la hoja con tu usuario para aprobarla o no aprobarla, sin iniciar sesión. Vence en 30 días.'),
      'Responder hoja de cierre', self::TEMPLATE_TO_RESPOND,
      fn(string $name): array => [$name, '#' . $closingId, self::place($closing), $actor['name'], $summary],
      'Hoja de cierre #' . $closingId . ' por responder. ' . $summary, [['label' => 'Ver revisión de servicios', 'url' => $reviewUrl]], 'revision_' . $reviewId);
    return $queued;
  }

  /** @param array{employee_id:string,name:string} $actor */
  private function notifyResponse(int $closingId, array $actor): int
  {
    $closing = $this->closing($closingId);
    $state = self::state($closing);
    $note = trim((string) ($closing['observacion_respuesta'] ?? ''));
    $rejected = $state === self::REJECTED;
    $subject = 'Hoja de cierre #' . $closingId . ' ' . mb_strtolower($state, 'UTF-8');
    $where = self::where($closing);
    $body = static fn(string $name, string $extra): string => self::p('Hola, <b>' . self::e($name) . '</b>.')
      . self::p('<b>' . self::e($actor['name']) . '</b> respondió la hoja de cierre de negocio <b>#' . $closingId . '</b> del ' . $where . ': <b>' . self::e($state) . '</b>.')
      . ($note !== '' ? self::p('<b>Observación:</b> ' . nl2br(self::e($note))) : '')
      . $extra;
    $waText = 'Hoja de cierre #' . $closingId . ' ' . mb_strtolower($state, 'UTF-8') . ' por ' . $actor['name'] . '.';
    $waParams = fn(string $name): array => [$name, '#' . $closingId, $state, self::place($closing), $actor['name'], $note !== '' ? $note : 'Sin observación'];
    $queued = $this->notifyStaff(self::ACTION_RESPONDED, $closingId, ClosingSheetAccess::VIEW, $subject, fn(string $name): string => $body($name, ''),
      'Ver hoja de cierre', self::TEMPLATE_RESPONSE, $waParams, $waText, [], (string) ($closing['fecha_repuesta'] ?? ''));

    // Quien hizo la hoja: si no se aprobó, la corrige desde el panel y vuelve a quedar por responder.
    $creator = $this->funcionario('id_empleado', trim((string) ($closing['id_empleado'] ?? '')));
    if ($creator !== null && !in_array($creator['employee_id'], $this->staffIds(self::ACTION_RESPONDED), true)) {
      $buttons = [['label' => 'Ver hoja de cierre', 'url' => ClosingSheetAccess::url(ClosingSheetAccess::VIEW, $closingId, $creator['employee_id'])]];
      if ($rejected) {
        $buttons[] = ['label' => 'Corregir hoja de cierre', 'url' => rtrim((string) SCM_BASE_URL, '/') . '/hoja-cierre.php?id_cierre=' . $closingId];
      }
      if (filter_var($creator['email'], FILTER_VALIDATE_EMAIL)) {
        $html = EmailTemplate::render($subject, $body($creator['name'], $rejected ? self::p('Corrige la hoja con el botón (pide iniciar sesión en el panel). Al guardar, vuelve a quedar por responder y se avisa de nuevo.') : ''), ['buttons' => $buttons]);
        $queued += (new EmailQueue($this->db))->enqueue([$creator['email']], $subject, $html, [
          'source_module' => 'hoja_cierre', 'internal_cc' => [], 'destination_name' => $creator['name'],
          'dedupe_key' => 'hoja_cierre_' . $closingId . '_respuesta_' . (string) ($closing['fecha_repuesta'] ?? '') . '_creador', 'meta' => ['closing_id' => $closingId],
        ]);
      }
      if ($creator['phone'] !== '') {
        $queued += $this->whatsapp($creator['phone'], $creator['name'], self::TEMPLATE_RESPONSE, $waParams($creator['name']), $buttons[0]['url'],
          'hoja_cierre_' . $closingId . '_respuesta_' . (string) ($closing['fecha_repuesta'] ?? '') . '_wa_creador', $waText, $closingId) ? 1 : 0;
      }
    }
    return $queued;
  }

  /** Hoja no aprobada que se corrigió: se avisa otra vez a "Hoja de cierre por responder". */
  public function notifyResubmitted(int $closingId, string $editorName): int
  {
    return $this->safely('resubmit', $closingId, function () use ($closingId, $editorName): int {
      $closing = $this->closing($closingId);
      $subject = 'Hoja de cierre #' . $closingId . ' corregida, por responder';
      return $this->notifyStaff(self::ACTION_TO_RESPOND, $closingId, ClosingSheetAccess::RESPOND, $subject,
        fn(string $name): string => self::p('Hola, <b>' . self::e($name) . '</b>.')
          . self::p('<b>' . self::e($editorName) . '</b> corrigió la hoja de cierre <b>#' . $closingId . '</b> del ' . self::where($closing) . ', que no había sido aprobada. Volvió a quedar <b>' . self::e(self::STATUS_REVIEWED) . '</b>.')
          . self::p('El botón abre la hoja con tu usuario para responderla, sin iniciar sesión. Vence en 30 días.'),
        'Responder hoja de cierre', self::TEMPLATE_TO_RESPOND,
        fn(string $name): array => [$name, '#' . $closingId, self::place($closing), $editorName, 'Hoja corregida después de no aprobarse'],
        'Hoja de cierre #' . $closingId . ' corregida, por responder.', [], (string) time());
    });
  }

  /**
   * Correo y WhatsApp a cada funcionario del evento interno, con su propio enlace firmado.
   * @param callable(string):string $body @param callable(string):list<string> $waParams @param list<array{label:string,url:string}> $extraButtons
   */
  private function notifyStaff(string $action, int $closingId, string $purpose, string $subject, callable $body, string $buttonLabel, string $template, callable $waParams, string $waText, array $extraButtons = [], string $dedupeSuffix = ''): int
  {
    $queue = new EmailQueue($this->db);
    $queued = 0;
    $phones = [];
    foreach (InternalNotificationRecipients::contactsForAction($this->db, $action) as $contact) {
      $employeeId = trim((string) ($contact['employee_id'] ?? ''));
      $name = trim((string) ($contact['name'] ?? '')) ?: 'Funcionario';
      if ($employeeId === '') {
        continue;
      }
      $url = ClosingSheetAccess::url($purpose, $closingId, $employeeId);
      $dedupe = 'hoja_cierre_' . $closingId . '_' . $action . ($dedupeSuffix !== '' ? '_' . $dedupeSuffix : '') . '_' . $employeeId;
      $email = strtolower(trim((string) ($contact['email'] ?? '')));
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $html = EmailTemplate::render($subject, $body($name), ['buttons' => array_merge([['label' => $buttonLabel, 'url' => $url]], $extraButtons)]);
        $queued += $queue->enqueue([$email], $subject, $html, [
          'source_module' => 'hoja_cierre', 'internal_action' => $action, 'internal_cc' => [], 'destination_name' => $name, 'dedupe_key' => $dedupe,
          'meta' => ['closing_id' => $closingId, 'internal_action' => $action],
        ]);
      }
      $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? '')) ?? '';
      if ($phone !== '' && !isset($phones[$phone])) {
        $phones[$phone] = true;
        $queued += $this->whatsapp((string) $contact['phone'], $name, $template, $waParams($name), $url, $dedupe . '_wa', $waText, $closingId) ? 1 : 0;
      }
    }
    return $queued;
  }

  /** @param list<string> $params Variables del cuerpo; el botón de la plantilla completa https://sucasainmobiliaria.com.co/{{1}} con $url. */
  private function whatsapp(string $phone, string $name, string $template, array $params, string $url, string $dedupe, string $text, int $closingId): bool
  {
    $parts = parse_url($url);
    $suffix = ltrim((string) ($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return (new SmsQueue($this->db))->enqueue($phone, $name, $text, [
      'source_module' => 'hoja_cierre', 'dedupe_key' => $dedupe, 'template_name' => $template, 'template_language' => 'es_CO',
      'template_components' => [
        ['type' => 'body', 'parameters' => array_map(static function ($value): array {
          $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
          return ['type' => 'text', 'text' => mb_substr($value !== '' ? $value : 'Sin dato', 0, 300, 'UTF-8')];
        }, $params)],
        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
      ],
      'closing_id' => $closingId,
    ]);
  }

  /** La hoja ya quedó guardada: un fallo en las colas solo se registra. */
  private function safely(string $what, int $closingId, callable $send): int
  {
    try {
      return (int) $send();
    } catch (\Throwable $error) {
      error_log('[closing_sheet_' . $what . '_notify] #' . $closingId . ': ' . $error->getMessage());
      return 0;
    }
  }

  /** @return list<string> id_empleado de los funcionarios del evento */
  private function staffIds(string $action): array
  {
    return array_values(array_filter(array_map(static fn(array $contact): string => trim((string) ($contact['employee_id'] ?? '')), InternalNotificationRecipients::contactsForAction($this->db, $action))));
  }

  /** Propietario y copropietarios de la hoja con correo o celular. @return list<array{name:string,email:string,phone:string}> */
  private function owners(array $closing): array
  {
    $out = [['name' => trim((string) ($closing['propietario'] ?? '')), 'email' => trim((string) ($closing['correo_propietario'] ?? '')), 'phone' => trim((string) ($closing['celular_propietario'] ?? ''))]];
    $count = max(1, min(6, (int) ($closing['propietarios'] ?? 1)));
    for ($i = 2; $i <= $count; $i++) {
      $out[] = ['name' => trim((string) ($closing['propietario_' . $i] ?? '')), 'email' => trim((string) ($closing['correo_propietario_' . $i] ?? '')), 'phone' => trim((string) ($closing['celular_propietario_' . $i] ?? ''))];
    }
    $seen = [];
    return array_values(array_filter($out, static function (array $owner) use (&$seen): bool {
      $key = strtolower($owner['email']) . '|' . preg_replace('/\D+/', '', $owner['phone']);
      if ($owner['name'] === '' || $key === '|' || isset($seen[$key])) {
        return false;
      }
      return $seen[$key] = true;
    }));
  }

  /** Revisiones del inmueble hasta hoy (el mayor entre el contador del post y las revisiones guardadas). */
  private function reviewCount(int $propertyPost): int
  {
    $count = 0;
    $reviews = $this->db->table('jet_cct_revisiones_servicios');
    if ($propertyPost > 0 && $this->schema->tableExists($reviews)) {
      $count = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$reviews}` WHERE `id_inmueble` = ?", [(string) $propertyPost]);
    }
    $postmeta = $this->db->table('postmeta');
    if ($propertyPost > 0 && $this->schema->tableExists($postmeta)) {
      $count = max($count, (int) $this->db->getVar("SELECT `meta_value` FROM `{$postmeta}` WHERE `post_id` = ? AND `meta_key` = 'revisiones-servicios' LIMIT 1", [$propertyPost]));
    }
    return $count;
  }

  /** @return list<string> claves de SERVICES marcadas en la hoja */
  private function listedServices(array $closing): array
  {
    $listed = self::serviceList($closing['servicios_publicos'] ?? '');
    return array_keys(array_filter(self::SERVICES, static fn(array $service): bool => in_array($service[1], $listed, true)));
  }

  /** @return list<string> */
  public static function serviceList(mixed $value): array
  {
    if (is_array($value)) {
      return array_values(array_map('strval', $value));
    }
    $text = trim((string) $value);
    $decoded = preg_match('/^a:\d+:\{/', $text) === 1 ? @unserialize($text, ['allowed_classes' => false]) : null;
    return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
  }

  /** Resultados por servicio de una revisión o de la hoja. @return list<array{label:string,account_label:string,account:string,meter:string,cutoff:int,reviewed:int,status:string,value:string}> */
  public static function results(array $row, string $accountSource = 'review'): array
  {
    $out = [];
    foreach (self::SERVICES as [$label, , $closingAccount, $accountLabel, $reviewAccount, $suffix]) {
      $status = trim((string) ($row['resultado_tiempo_' . $suffix] ?? ''));
      if ($status === '') {
        continue;
      }
      $out[] = [
        'label' => $label, 'account_label' => $accountLabel, 'account' => trim((string) ($row[$accountSource === 'review' ? $reviewAccount : $closingAccount] ?? '')),
        'meter' => trim((string) ($row['medidor_' . $suffix] ?? '')), 'cutoff' => (int) ($row['fecha_corte_' . $suffix] ?? 0),
        'reviewed' => (int) ($row['fecha_revision_' . $suffix] ?? 0),
        'status' => $status, 'value' => trim((string) ($row['resultado_valores_' . $suffix] ?? '')),
      ];
    }
    return $out;
  }

  public static function money(string $value): string
  {
    return preg_match('/^\d+$/', $value) === 1 ? '$' . number_format((int) $value, 0, ',', '.') : $value;
  }

  public static function statusLabel(string $status): string
  {
    return ['Al dia' => 'Al día', '30 dias' => '30 días', '60 dias' => '60 días', 'Estado critico' => 'Estado crítico'][$status] ?? $status;
  }

  /** "Energía: Al día · Agua: 30 días ($32.295)". @param array<string,array<string,mixed>> $services */
  private static function summaryText(array $services): string
  {
    $parts = [];
    foreach ($services as $key => $service) {
      $parts[] = self::SERVICES[$key][0] . ': ' . self::statusLabel($service['status']) . ($service['status'] !== 'Al dia' ? ' (' . self::money($service['value']) . ')' : '');
    }
    return implode(' · ', $parts);
  }

  /** @param array<string,array<string,mixed>> $services */
  private static function resultsTable(array $services): string
  {
    $cell = 'padding:8px 10px;border:1px solid #e2e8f0;text-align:left;font-size:14px;';
    $rows = '';
    foreach ($services as $key => $service) {
      [$label, , , $accountLabel] = self::SERVICES[$key];
      $rows .= '<tr><td style="' . $cell . '"><b>' . self::e($label) . '</b><br><span style="color:#64748b;font-size:12px;">' . self::e($accountLabel . ' ' . $service['account']) . '</span></td>'
        . '<td style="' . $cell . '">' . self::e(self::statusLabel($service['status'])) . '</td><td style="' . $cell . '">' . self::e(self::money($service['value'])) . '</td></tr>';
    }
    return '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 16px;"><tr>'
      . '<th style="' . $cell . 'background:#f1f5f9;">Servicio</th><th style="' . $cell . 'background:#f1f5f9;">Resultado</th><th style="' . $cell . 'background:#f1f5f9;">Valor</th></tr>' . $rows . '</table>';
  }

  /** Firma "Nombre - Cargo - Cel. 3015232054" (celular nacional, sin indicativo). @param array{name:string,cargo?:string,phone?:string} $actor */
  public static function signature(array $actor): string
  {
    $phone = preg_replace('/\D+/', '', (string) ($actor['phone'] ?? '')) ?? '';
    if (strlen($phone) === 12 && str_starts_with($phone, '57')) {
      $phone = substr($phone, 2);
    }
    return implode(' - ', array_filter([trim($actor['name']), trim((string) ($actor['cargo'] ?? '')), $phone !== '' ? 'Cel. ' . $phone : '']));
  }

  private static function where(array $closing): string
  {
    return 'inmueble <b>#' . self::e($closing['id_inmueble'] ?? '') . '</b>, ubicado en <b>' . self::e($closing['direccion'] ?? '') . '</b>';
  }

  private static function place(array $closing): string
  {
    return 'Inmueble ' . trim((string) ($closing['id_inmueble'] ?? '')) . ' · ' . trim((string) ($closing['direccion'] ?? ''));
  }

  private static function p(string $html): string
  {
    return '<p style="margin:0 0 14px;line-height:1.65;">' . $html . '</p>';
  }

  private static function e(mixed $value): string
  {
    return EmailTemplate::e((string) $value);
  }

  private static function clean(mixed $value): string
  {
    return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(is_array($value) ? '' : (string) $value)) ?? ''), 0, 180, 'UTF-8');
  }

  /** "2026-10-30" => medianoche en Colombia (como guarda JetEngine las fechas de corte). */
  private static function dateTimestamp(string $value): int
  {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone(self::TIMEZONE));
    return $date !== false && $date->format('Y-m-d') === trim($value) ? $date->getTimestamp() : 0;
  }
}
