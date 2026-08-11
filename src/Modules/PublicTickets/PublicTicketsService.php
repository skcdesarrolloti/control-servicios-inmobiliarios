<?php

namespace SCM\Modules\PublicTickets;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

final class PublicTicketsService
{
  /** @var array<string,array<string,mixed>> */
  private const ACTOR_CONFIG = [
    'propietario' => [
      'actor' => 'propietario',
      'label' => 'Propietario',
      'lookup_label' => 'Documento del propietario',
      'lookup_placeholder' => 'Ejemplo: 1143349627',
      'requires_contract' => true,
      'theme_default' => 'Queja mantenimiento',
      'medium' => 'Portal propietario',
      'actor_table' => 'propietarios',
    ],
    'arrendatario' => [
      'actor' => 'arrendatario',
      'label' => 'Arrendatario',
      'lookup_label' => 'Documento del arrendatario',
      'lookup_placeholder' => 'Ejemplo: 1053005059',
      'requires_contract' => true,
      'theme_default' => 'Queja mantenimiento',
      'medium' => 'Portal arrendatario',
      'actor_table' => 'arrendatarios',
    ],
    'copropiedad' => [
      'actor' => 'copropiedad',
      'label' => 'Copropiedad',
      'lookup_label' => 'NIT de la copropiedad',
      'lookup_placeholder' => 'Ejemplo: 901890678',
      'requires_contract' => true,
      'theme_default' => 'Queja mantenimiento',
      'medium' => 'Portal copropiedad',
      'actor_table' => 'copropiedades',
    ],
    'cliente' => [
      'actor' => 'cliente',
      'label' => 'Cliente',
      'lookup_label' => 'Celular del cliente',
      'lookup_placeholder' => 'Ejemplo: 3001234567',
      'requires_contract' => false,
      'theme_default' => 'Arriendo',
      'medium' => 'WhatsApp cliente',
      'actor_table' => 'clientes',
    ],
  ];

  /** @var array<int,string> */
  private const PQR_THEMES = [
    'Avaluo',
    'Actualizacion',
    'Captacion',
    'Recaptacion',
    'Arriendo',
    'Venta',
    'Arriendo o venta',
    'Entrega de inmuebles',
    'Revision preventiva',
    'Recibo de inmuebles',
    'Reparaciones necesarias',
    'Reparaciones locativas',
    'Mejoras utiles',
    'Reparaciones voluntarias',
    'Contable y tributaria',
    'Certificaciones tributarias',
    'Procesos juridicos',
    'Solicitud contractual',
    'Solicitud de servicios publicos',
    'Ruta',
    'Retoque',
    'Otros servicios',
    'Retencion de contrato',
    'Reparaciones antes de la entrega',
    'Reparaciones antes del recibo',
  ];

  /** @var array<int,string> */
  private const INTERNAL_ONLY_PQR_THEMES = [
    'Ruta',
    'Retoque',
    'Otros servicios',
    'Retencion de contrato',
  ];

  /** @var array<string,string> */
  private const PQR_THEME_DEPARTMENT_MAP = [
    'avaluo'                           => 'Comercial',
    'actualizacion'                    => 'Comercial',
    'captacion'                        => 'Comercial',
    'recaptacion'                      => 'Comercial',
    'arriendo'                         => 'Comercial',
    'venta'                            => 'Comercial',
    'arriendo o venta'                 => 'Comercial',
    'entrega de inmuebles'             => 'Mantenimiento',
    'revision preventiva'              => 'Mantenimiento',
    'recibo de inmuebles'              => 'Mantenimiento',
    'reparaciones necesarias'          => 'Mantenimiento',
    'reparaciones locativas'           => 'Mantenimiento',
    'mejoras utiles'                   => 'Mantenimiento',
    'reparaciones voluntarias'         => 'Mantenimiento',
    'contable y tributaria'            => 'Contable',
    'certificaciones tributarias'      => 'Contable',
    'procesos juridicos'               => 'Juridico',
    'solicitud contractual'            => 'Contractual',
    'solicitud de servicios publicos'  => 'Mantenimiento',
    'ruta'                             => 'Mantenimiento',
    'retoque'                          => 'Comercial',
    'otros servicios'                  => 'General',
    'retencion de contrato'            => 'Contractual',
    'reparaciones antes de la entrega' => 'Mantenimiento',
    'reparaciones antes del recibo'    => 'Mantenimiento',
  ];

  private const OPTION_CORRESPONSABLES = 'scm_public_pqrs_corresponsables';
  private const OPTION_NOTIF_RESPONSABLE = 'scm_public_pqrs_notif_responsable';

  private Database $db;
  private SchemaInspector $schema;
  private EmailQueue $emailQueue;
  private SmsQueue $smsQueue;
  private string $appSecret = '';
  private string $groupComercial = '';
  /** @var array<string,mixed> */
  private array $clienteNotificationsOverride = [];

  /** @var array<int,array<string,mixed>>|null */
  private ?array $contractsCache = null;

  public function __construct(Database $db, array $appConfig = [])
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
    $this->emailQueue = new EmailQueue($db);
    $this->smsQueue = new SmsQueue($db);
    $this->appSecret = trim((string) ($appConfig['app_secret'] ?? ''));
    $this->groupComercial = trim((string) ($appConfig['group_comercial'] ?? ''));
    $override = $appConfig['cliente_notifications_override'] ?? [];
    $this->clienteNotificationsOverride = is_array($override) ? $override : [];
  }

  /** @return array<string,mixed> */
  public static function getActorConfig(string $actor): array
  {
    $actor = strtolower(trim($actor));
    $config = self::ACTOR_CONFIG[$actor] ?? [];
    return is_array($config) ? $config : [];
  }

  /** @return array<string,mixed> */
  public function getFormOptions(): array
  {
    $publicThemes = self::getPqrThemes();
    return [
      'tema_ayuda' => $publicThemes,
      'tipo_pqrs' => $publicThemes, // Compatibilidad temporal
    ];
  }

  /** @return array<string,mixed> */
  public function lookup(string $actor, string $lookupValue): array
  {
    $config = self::getActorConfig($actor);
    if (empty($config)) {
      return ['ok' => false, 'message' => 'Tipo de actor no valido.', 'rows' => []];
    }

    $lookupValue = trim($lookupValue);
    if ($lookupValue === '') {
      return ['ok' => false, 'message' => 'Debes ingresar un valor para consultar.', 'rows' => []];
    }

    $rows = [];
    if ($actor === 'propietario') {
      $rows = $this->lookupPropietario($lookupValue);
    } elseif ($actor === 'arrendatario') {
      $rows = $this->lookupArrendatario($lookupValue);
    } elseif ($actor === 'copropiedad') {
      $rows = $this->lookupCopropiedad($lookupValue);
    } elseif ($actor === 'cliente') {
      $rows = $this->lookupCliente($lookupValue);
    }

    return [
      'ok' => true,
      'message' => empty($rows) ? 'No se encontraron resultados.' : '',
      'rows' => $rows,
      'actor' => $actor,
      'lookup_value' => $lookupValue,
      'config' => $config,
    ];
  }

  /** @return array<string,mixed> */
  public function lookupClientByPhone(string $phone): array
  {
    $config = self::getActorConfig('cliente');
    if (empty($config)) {
      return ['ok' => false, 'message' => 'Tipo de actor no valido.', 'rows' => []];
    }

    $phone = trim($phone);
    if ($phone === '') {
      return ['ok' => false, 'message' => 'Debes ingresar un numero de telefono valido.', 'rows' => []];
    }

    $rows = $this->lookupClienteByPhone($phone);

    return [
      'ok' => true,
      'message' => empty($rows) ? 'No se encontraron clientes para ese numero.' : '',
      'rows' => $rows,
      'actor' => 'cliente',
      'lookup_value' => $phone,
      'config' => $config,
    ];
  }

  /** @return array<string,mixed> */
  public function lookupRequests(string $actor, string $selectedId, string $lookupValue = '', array $options = []): array
  {
    $config = self::getActorConfig($actor);
    if (empty($config)) {
      return ['ok' => false, 'message' => 'Tipo de actor no valido.', 'rows' => []];
    }

    $selectedId = trim($selectedId);
    if ($selectedId === '') {
      return ['ok' => false, 'message' => 'Debes seleccionar un registro para consultar tus solicitudes.', 'rows' => []];
    }

    $contract = null;
    $client = null;
    $allowWithoutContract = (!empty($options['no_contract_commercial']) || !empty($options['all_by_solicitante']))
      && in_array($actor, ['propietario', 'arrendatario', 'copropiedad'], true);

    if (!empty($config['requires_contract']) && !$allowWithoutContract) {
      $contract = $this->fetchContractById($selectedId);
      if (!is_array($contract)) {
        return ['ok' => false, 'message' => 'Contrato no encontrado.', 'rows' => []];
      }

      if ($lookupValue !== '' && !$this->contractMatchesLookup($actor, $contract, $lookupValue)) {
        return ['ok' => false, 'message' => 'El contrato no coincide con la consulta realizada.', 'rows' => []];
      }
    } elseif ($actor === 'cliente' || empty($config['requires_contract'])) {
      $client = $this->fetchClientById($selectedId);
      if (!is_array($client)) {
        return ['ok' => false, 'message' => 'Cliente no encontrado.', 'rows' => []];
      }
    }

    return [
      'ok' => true,
      'message' => '',
      'rows' => $this->findRequestsForSelection($actor, $contract, $client, $options),
      'actor' => $actor,
      'selected_id' => $selectedId,
    ];
  }

  /** @return array<string,mixed> */
  public function upsertClient(array $input): array
  {
    $clientsTable = $this->db->table('jet_cct_clientes');
    if (!$this->schema->tableExists($clientsTable)) {
      return ['ok' => false, 'message' => 'No existe la tabla de clientes.'];
    }

    $name = trim((string) ($input['nombre'] ?? ''));
    $phone = $this->normalizeDigits((string) ($input['celular'] ?? ''));
    $indicativo = $this->normalizeIndicativo((string) ($input['indicativo'] ?? '+57'));
    $email = trim((string) ($input['correo'] ?? ''));
    $tipoCliente = trim((string) ($input['tipo_cliente'] ?? 'Cliente WhatsApp'));
    $selectedId = trim((string) ($input['selected_id'] ?? ''));

    if ($name === '' || $phone === '') {
      return ['ok' => false, 'message' => 'Nombre y celular del cliente son obligatorios.'];
    }

    $existing = $selectedId !== '' ? $this->fetchClientById($selectedId) : null;
    if (!is_array($existing)) {
      $matches = $this->lookupClienteByPhone($phone);
      if (!empty($matches)) {
        $existing = $this->fetchClientById((string) ($matches[0]['id'] ?? ''));
      }
    }

    $nowMysql = date('Y-m-d H:i:s');
    $baseData = [
      'nombre' => $name,
      'indicativo' => $indicativo,
      'celular' => $phone,
      'correo' => $email,
      'tipo_cliente' => $tipoCliente,
      'cct_modified' => $nowMysql,
    ];

    try {
      if (is_array($existing)) {
        $updateData = $this->schema->filterTableData($clientsTable, $baseData);
        if (!empty($updateData)) {
          $this->db->update($clientsTable, $updateData, ['_ID' => (string) ($existing['_ID'] ?? '')]);
        }
        $clientId = (string) ($existing['_ID'] ?? '');
      } else {
        $insertData = $this->schema->filterTableData($clientsTable, [
          'cct_status' => 'publish',
          'cct_author_id' => 0,
          'cct_created' => $nowMysql,
        ] + $baseData);
        if (empty($insertData)) {
          return ['ok' => false, 'message' => 'No se pudo construir el payload del cliente.'];
        }

        $this->db->insert($clientsTable, $insertData);
        $clientId = (string) $this->db->lastInsertId();
      }
    } catch (\Throwable $e) {
      return ['ok' => false, 'message' => 'No se pudo guardar el cliente: ' . $e->getMessage()];
    }

    $client = $this->fetchClientById($clientId);
    if (!is_array($client)) {
      return ['ok' => false, 'message' => 'No se pudo recuperar el cliente guardado.'];
    }

    return [
      'ok' => true,
      'message' => 'Cliente guardado correctamente.',
      'client_id' => $clientId,
      'client' => $this->formatClientLookupRow($client),
    ];
  }

  /** @return array<string,mixed> */
  public function lookupClientQuotes(string $selectedId): array
  {
    $client = $this->fetchClientById($selectedId);
    if (!is_array($client)) {
      return ['ok' => false, 'message' => 'Cliente no encontrado.', 'rows' => []];
    }

    $quotesTable = $this->db->table('jet_cct_cotizacion_inmuebles');
    if (!$this->schema->tableExists($quotesTable)) {
      return ['ok' => true, 'message' => '', 'rows' => []];
    }

    [$where, $args] = $this->buildClientLookupConditions($quotesTable, $client, [
      'id_cliente' => ['exact'],
    ]);

    if ($where === []) {
      return ['ok' => true, 'message' => '', 'rows' => []];
    }

    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_cotizacion`, '')) AS id_cotizacion,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`tipo`, '')) AS tipo,
              TRIM(COALESCE(`destinatario`, '')) AS destinatario,
              TRIM(COALESCE(`direccion`, '')) AS direccion,
              TRIM(COALESCE(`email`, '')) AS email,
              TRIM(COALESCE(`celular`, '')) AS celular,
              COALESCE(`fecha`, UNIX_TIMESTAMP(`cct_created`), 0) AS fecha_ref
            FROM `{$quotesTable}`
            WHERE (" . implode(' OR ', $where) . ")
            ORDER BY `_ID` DESC
            LIMIT 20";

    $rows = $this->db->getResults($sql, $args);
    $out = [];
    foreach ($rows as $row) {
      $quoteId = trim((string) ($row['id_cotizacion'] ?? ''));
      if ($quoteId === '') {
        $quoteId = trim((string) ($row['_ID'] ?? ''));
      }

      $quotePk = trim((string) ($row['_ID'] ?? ''));
      $quoteNumero = $quotePk !== '' ? $quotePk : $quoteId;

      $ticketId = trim((string) ($row['id_ticket'] ?? ''));
      $timestamp = (int) ($row['fecha_ref'] ?? 0);
      $out[] = [
        'id_cotizacion' => $quoteId,
        'tipo' => trim((string) ($row['tipo'] ?? '')),
        'destinatario' => trim((string) ($row['destinatario'] ?? '')),
        'direccion' => trim((string) ($row['direccion'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'celular' => trim((string) ($row['celular'] ?? '')),
        'fecha' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '',
        'ticket_id' => $ticketId,
        'quote_url' => $this->buildClientQuoteUrl($quoteNumero),
        'ticket_url' => $this->buildPublicTicketUrl($ticketId),
      ];
    }

    return ['ok' => true, 'message' => '', 'rows' => $out];
  }

  /** @return array<string,mixed> */
  public function lookupClientStudies(string $selectedId): array
  {
    $client = $this->fetchClientById($selectedId);
    if (!is_array($client)) {
      return ['ok' => false, 'message' => 'Cliente no encontrado.', 'rows' => []];
    }

    $studiesTable = $this->db->table('jet_cct_estudios_aseguradoras');
    if (!$this->schema->tableExists($studiesTable)) {
      return ['ok' => true, 'message' => '', 'rows' => []];
    }

    [$where, $args] = $this->buildClientLookupConditions($studiesTable, $client, [
      'id_cliente' => ['exact'],
    ]);

    if ($where === []) {
      return ['ok' => true, 'message' => '', 'rows' => []];
    }

    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`num_solicitud`, '')) AS num_solicitud,
              TRIM(COALESCE(`estado`, '')) AS estado,
              TRIM(COALESCE(`aseguradora`, '')) AS aseguradora,
              TRIM(COALESCE(`valor`, '')) AS valor,
              TRIM(COALESCE(`faltante`, '')) AS faltante,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              COALESCE(`fecha_respuesta`, `fecha`, 0) AS fecha_ref
            FROM `{$studiesTable}`
            WHERE (" . implode(' OR ', $where) . ")
            ORDER BY `_ID` DESC
            LIMIT 20";

    $rows = $this->db->getResults($sql, $args);
    $out = [];
    foreach ($rows as $row) {
      $requestNumber = trim((string) ($row['num_solicitud'] ?? ''));
      if ($requestNumber === '') {
        $requestNumber = trim((string) ($row['_ID'] ?? ''));
      }

      $studyPk = trim((string) ($row['_ID'] ?? ''));
      $ticketId = trim((string) ($row['id_ticket'] ?? ''));
      $timestamp = (int) ($row['fecha_ref'] ?? 0);
      $out[] = [
        'num_solicitud' => $requestNumber,
        'estado' => trim((string) ($row['estado'] ?? '')),
        'aseguradora' => trim((string) ($row['aseguradora'] ?? '')),
        'valor' => trim((string) ($row['valor'] ?? '')),
        'faltante' => trim((string) ($row['faltante'] ?? '')),
        'fecha' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '',
        'ticket_id' => $ticketId,
        'study_url' => $this->buildClientStudyUrl($studyPk),
        'ticket_url' => $this->buildPublicTicketUrl($ticketId),
      ];
    }

    return ['ok' => true, 'message' => '', 'rows' => $out];
  }

  /** @return array<string,mixed> */
  public function lookupPublishedPropertyByCode(string $codigo): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->schema->tableExists($table)) {
      return ['ok' => false, 'message' => 'No existe la tabla de inmuebles.', 'property' => null];
    }

    $candidates = $this->buildPropertyCodeCandidates($codigo);
    if ($candidates === []) {
      return ['ok' => false, 'message' => 'Debes ingresar un codigo de inmueble valido.', 'property' => null];
    }

    $where = [];
    $extraWhere = [];
    $args = [];
    foreach ($candidates as $candidate) {
      $where[] = "TRIM(COALESCE(`codigo`, '')) = ?";
      $args[] = $candidate;
    }

    foreach ($candidates as $candidate) {
      $candidate = (string) $candidate;
      if (ctype_digit($candidate)) {
        $where[] = "`_ID` = ?";
        $args[] = $candidate;
      }
    }

    $statusWhere = '';
    if ($this->schema->columnExists($table, 'cct_status')) {
      $statusWhere = " AND LOWER(TRIM(COALESCE(`cct_status`, ''))) IN ('publish', 'published', '')";
    }

    $sql = "SELECT *
              FROM `{$table}`
             WHERE (" . implode(' OR ', $where) . ")
             {$statusWhere}
             ORDER BY `_ID` DESC
             LIMIT 1";
    $row = $this->db->getRow($sql, $args);
    if (!is_array($row)) {
      return [
        'ok' => false,
        'message' => 'No encontramos un inmueble publicado con ese codigo.',
        'property' => null,
        'suggestions' => $this->searchSimilarPublishedPropertiesByCode($codigo, 5),
      ];
    }

    if (mb_strtolower(trim((string) ($row['estado'] ?? '')), 'UTF-8') !== 'publico') {
      return ['ok' => false, 'message' => 'Encontramos el codigo, pero el inmueble no esta disponible actualmente.', 'property' => null];
    }

    if (!array_key_exists('lookup_value', $options)) {
      $options['lookup_value'] = $lookupValue;
    }

    return [
      'ok' => true,
      'message' => '',
      'property' => $this->formatPublishedProperty($row),
    ];
  }

  /**
   * @param array<string,mixed> $criteria
   * @return array<string,mixed>
   */
  public function searchPublishedProperties(array $criteria): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->schema->tableExists($table)) {
      return ['ok' => false, 'message' => 'No existe la tabla de inmuebles.', 'rows' => []];
    }

    $tipoNegocio = mb_strtolower(trim((string) ($criteria['tipo_negocio'] ?? '')), 'UTF-8');
    $tipoInmueble = trim((string) ($criteria['tipo_inmueble'] ?? ''));
    $barrio = trim((string) ($criteria['barrio'] ?? ''));
    $presupuestoMin = (int) ($criteria['presupuesto_min'] ?? 0);
    $presupuestoMax = (int) ($criteria['presupuesto_max'] ?? 0);
    $habitacionesMin = (int) ($criteria['habitaciones_min'] ?? 0);
    $banosMin = (int) ($criteria['banos_min'] ?? 0);
    $limit = (int) ($criteria['limit'] ?? 5);
    if ($limit <= 0 || $limit > 10) {
      $limit = 5;
    }

    $where = [
      "LOWER(TRIM(COALESCE(`cct_status`, ''))) IN ('publish', 'published', '')",
      "LOWER(TRIM(COALESCE(`estado`, ''))) = 'publico'",
    ];
    $args = [];

    if ($tipoNegocio === 'arriendo') {
      $where[] = "LOWER(TRIM(COALESCE(`tipo_negocio`, ''))) IN ('arriendo', 'arriendo/venta', 'arriendo y venta')";
      $priceColumn = 'precio_arriendo';
    } elseif ($tipoNegocio === 'venta') {
      $where[] = "LOWER(TRIM(COALESCE(`tipo_negocio`, ''))) IN ('venta', 'arriendo/venta', 'arriendo y venta')";
      $priceColumn = 'precio_venta';
    } else {
      $priceColumn = '';
    }

    if ($tipoInmueble !== '') {
      $where[] = "LOWER(TRIM(COALESCE(`tipo_inmueble`, ''))) = ?";
      $args[] = mb_strtolower($tipoInmueble, 'UTF-8');
    }

    if ($barrio !== '') {
      $where[] = "LOWER(TRIM(COALESCE(`barrio`, ''))) LIKE ?";
      $args[] = '%' . mb_strtolower($this->db->escapeLike($barrio), 'UTF-8') . '%';
    }

    if ($priceColumn !== '' && ($presupuestoMin > 0 || $presupuestoMax > 0)) {
      if ($presupuestoMin > 0 && $presupuestoMax > 0) {
        $where[] = "CAST(REGEXP_REPLACE(COALESCE(`{$priceColumn}`, ''), '[^0-9]', '') AS UNSIGNED) BETWEEN ? AND ?";
        $args[] = $presupuestoMin;
        $args[] = $presupuestoMax;
      } elseif ($presupuestoMin > 0) {
        $where[] = "CAST(REGEXP_REPLACE(COALESCE(`{$priceColumn}`, ''), '[^0-9]', '') AS UNSIGNED) >= ?";
        $args[] = $presupuestoMin;
      } else {
        $where[] = "CAST(REGEXP_REPLACE(COALESCE(`{$priceColumn}`, ''), '[^0-9]', '') AS UNSIGNED) BETWEEN 1 AND ?";
        $args[] = $presupuestoMax;
      }
    }

    if ($habitacionesMin > 0) {
      $where[] = "CAST(REGEXP_REPLACE(COALESCE(`habitaciones`, ''), '[^0-9]', '') AS UNSIGNED) >= ?";
      $args[] = $habitacionesMin;
    }

    if ($banosMin > 0) {
      $where[] = "CAST(REGEXP_REPLACE(COALESCE(`banos`, ''), '[^0-9]', '') AS UNSIGNED) >= ?";
      $args[] = $banosMin;
    }

    $priceExpr = $priceColumn !== ''
      ? "CAST(REGEXP_REPLACE(COALESCE(`{$priceColumn}`, ''), '[^0-9]', '') AS UNSIGNED)"
      : "0";

    $sql = "SELECT *
              FROM `{$table}`
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$priceExpr} ASC, `_ID` DESC
             LIMIT " . $limit;

    $rows = $this->db->getResults($sql, $args);

    return [
      'ok' => true,
      'message' => '',
      'rows' => array_map(fn(array $row): array => $this->formatPublishedProperty($row), $rows),
    ];
  }

  /**
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public function createTicket(string $actor, string $selectedId, string $lookupValue, array $input): array
  {
    $config = self::getActorConfig($actor);
    if (empty($config)) {
      return ['ok' => false, 'message' => 'Tipo de actor no valido.'];
    }

    $selectedId = trim($selectedId);
    if ($selectedId === '') {
      return ['ok' => false, 'message' => 'Debes seleccionar un registro para crear el PQR.'];
    }

    $asunto = trim((string) ($input['asunto'] ?? ''));
    $descripcion = trim((string) ($input['descripcion'] ?? ''));
    if ($asunto === '' || $descripcion === '') {
      return ['ok' => false, 'message' => 'Asunto y descripcion son obligatorios.'];
    }

    $tipoPqrsInput = (string) ($input['tema_ayuda'] ?? $input['tipo_pqrs'] ?? '');
    $tipoPqrs = $this->normalizePublicPqrTheme($tipoPqrsInput);
    if ($tipoPqrs === '') {
      $defaultTheme = $this->normalizePublicPqrTheme((string) ($config['theme_default'] ?? ''));
      $publicThemes = self::getPqrThemes();
      $tipoPqrs = $defaultTheme !== '' ? $defaultTheme : ($publicThemes[0] ?? self::PQR_THEMES[0]);
    }
    $departamento = $this->getDepartmentForActor($actor);
    $allowWithoutContract = !empty($input['no_contract_commercial'])
      && in_array($actor, ['propietario', 'arrendatario'], true)
      && in_array($tipoPqrs, ['Captacion', 'Avaluo'], true);

    $contract = null;
    $client = null;
    if (!empty($config['requires_contract']) && !$allowWithoutContract) {
      $contract = $this->fetchContractById($selectedId);
      if (!is_array($contract)) {
        return ['ok' => false, 'message' => 'Contrato no encontrado.'];
      }

      if (in_array($actor, ['propietario', 'arrendatario'], true) && $this->requiresDeliveredContractTheme($tipoPqrs) && !$this->isDeliveredContract($contract)) {
        return ['ok' => false, 'message' => 'El contrato seleccionado no esta en estado Entregado. No se puede hacer una solicitud en esta categoria.'];
      }

      if ($lookupValue !== '' && !$this->contractMatchesLookup($actor, $contract, $lookupValue)) {
        return ['ok' => false, 'message' => 'El contrato no coincide con la consulta realizada.'];
      }
    } else {
      $client = $this->fetchClientById($selectedId);
      if (!is_array($client)) {
        return ['ok' => false, 'message' => 'Cliente no encontrado.'];
      }
    }

    $contact = $this->buildRequesterContact($actor, $contract, $client, $input);
    if ($contact['name'] === '' || $contact['phone'] === '' || ($actor !== 'cliente' && $contact['email'] === '')) {
      return ['ok' => false, 'message' => 'Debes completar solicitante, correo y celular.'];
    }

    $responsable = $this->resolveResponsible($contract, $tipoPqrs, $departamento, $actor);

    $ticketResult = $this->insertTicket(
      $actor,
      $config,
      $tipoPqrs,
      $departamento,
      $asunto,
      $descripcion,
      $input,
      $contact,
      $responsable,
      $contract,
      $client
    );

    if (empty($ticketResult['ok'])) {
      return $ticketResult;
    }

    $ticketId = (int) ($ticketResult['ticket_id'] ?? 0);
    $logicalTicket = (string) $ticketId;
    $this->insertPropertyHistory($logicalTicket, $asunto, $responsable, $contract);

    $notify = $this->notifyResponsible(
      $ticketId,
      $logicalTicket,
      $asunto,
      $tipoPqrs,
      $departamento,
      $contact,
      $responsable,
      $contract,
      $client,
      $actor,
      (string) ($config['label'] ?? ucfirst($actor))
    );

    return [
      'ok' => true,
      'message' => 'PQR creado correctamente.',
      'ticket_id' => $logicalTicket,
      'responsable' => $responsable,
      'notifications' => $notify,
    ];
  }

  /** @return array<int,array<string,mixed>> */
  private function lookupPropietario(string $lookupValue): array
  {
    $docKey = $this->normalizeAlphaNum($lookupValue);
    if ($docKey === '') {
      return [];
    }

    $ownerIds = $this->findOwnerIdsByDocument($lookupValue, $docKey);
    $rows = [];
    foreach ($this->getContractsCatalog() as $contract) {
      $contractDoc = $this->normalizeAlphaNum((string) ($contract['documento_propietario'] ?? ''));
      $contractOwnerId = trim((string) ($contract['id_propietario'] ?? ''));
      if ($contractDoc === $docKey || isset($ownerIds[$contractOwnerId])) {
        $rows[] = $this->formatContractLookupRow($contract, 'propietario');
      }
    }
    return $rows;
  }

  /** @return array<int,array<string,mixed>> */
  private function lookupArrendatario(string $lookupValue): array
  {
    $docKey = $this->normalizeAlphaNum($lookupValue);
    if ($docKey === '') {
      return [];
    }

    $tenantIds = $this->findTenantIdsByDocument($lookupValue, $docKey);
    $rows = [];
    foreach ($this->getContractsCatalog() as $contract) {
      $contractDoc = $this->normalizeAlphaNum((string) ($contract['documento_arrendatario'] ?? ''));
      $contractTenantId = trim((string) ($contract['id_arrendatario'] ?? ''));
      if ($contractDoc === $docKey || isset($tenantIds[$contractTenantId])) {
        $rows[] = $this->formatContractLookupRow($contract, 'arrendatario');
      }
    }
    return $rows;
  }

  /** @return array<int,array<string,mixed>> */
  private function lookupCopropiedad(string $lookupValue): array
  {
    $lookupDigits = $this->normalizeDigits($lookupValue);
    $lookupText = mb_strtolower(trim($lookupValue), 'UTF-8');
    if ($lookupDigits === '' && $lookupText === '') {
      return [];
    }

    $coproIds = $this->findCopropiedadIds($lookupValue, $lookupDigits);
    $rows = [];
    foreach ($this->getContractsCatalog() as $contract) {
      $nit = $this->normalizeDigits((string) ($contract['nit_copropiedad'] ?? ''));
      $coproId = trim((string) ($contract['id_copropiedad'] ?? ''));
      $coproName = mb_strtolower(trim((string) ($contract['copropiedad'] ?? '')), 'UTF-8');
      $matchNit = $lookupDigits !== '' && $nit !== '' && $nit === $lookupDigits;
      $matchId = $coproId !== '' && isset($coproIds[$coproId]);
      $matchName = $lookupText !== '' && $coproName !== '' && strpos($coproName, $lookupText) !== false;
      if ($matchNit || $matchId || $matchName) {
        $rows[] = $this->formatContractLookupRow($contract, 'copropiedad');
      }
    }
    return $rows;
  }

  /** @return array<int,array<string,mixed>> */
  private function lookupCliente(string $lookupValue): array
  {
    $clientsTable = $this->db->table('jet_cct_clientes');
    if (!$this->schema->tableExists($clientsTable)) {
      return [];
    }

    $lookupValue = trim($lookupValue);
    $like = '%' . $this->db->escapeLike($lookupValue) . '%';
    $sql = "SELECT * FROM `{$clientsTable}`
            WHERE TRIM(COALESCE(`id_cliente`, '')) LIKE ?
               OR TRIM(COALESCE(`nombre`, '')) LIKE ?
               OR TRIM(COALESCE(`correo`, '')) LIKE ?
               OR TRIM(COALESCE(`celular`, '')) LIKE ?
            ORDER BY `_ID` DESC
            LIMIT 80";
    $rows = $this->db->getResults($sql, [$like, $like, $like, $like]);
    return array_map(fn(array $row): array => $this->formatClientLookupRow($row), $rows);
  }

  /** @return array<int,array<string,mixed>> */
  private function lookupClienteByPhone(string $phone): array
  {
    $clientsTable = $this->db->table('jet_cct_clientes');
    if (!$this->schema->tableExists($clientsTable)) {
      return [];
    }

    $terms = $this->buildPhoneTerms($phone);
    if ($terms === []) {
      return [];
    }

    $where = [];
    $args = [];
    foreach ($terms as $term) {
      $where[] = "TRIM(COALESCE(`celular`, '')) = ?";
      $args[] = $term;
    }

    $sql = "SELECT * FROM `{$clientsTable}`
            WHERE " . implode(' OR ', $where) . "
            ORDER BY `_ID` DESC
            LIMIT 20";
    $rows = $this->db->getResults($sql, $args);

    return array_map(fn(array $row): array => $this->formatClientLookupRow($row), $rows);
  }

  /** @return array<string,mixed> */
  private function formatClientLookupRow(array $row): array
  {
    return [
      'id' => trim((string) ($row['_ID'] ?? '')),
      'type' => 'client',
      'actor_id' => trim((string) ($row['_ID'] ?? '')),
      'cliente_id' => trim((string) ($row['id_cliente'] ?? '')),
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'correo' => trim((string) ($row['correo'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
      'indicativo' => $this->normalizeIndicativo((string) ($row['indicativo'] ?? '+57')),
      'tipo_cliente' => trim((string) ($row['tipo_cliente'] ?? '')),
      'display' => trim((string) ($row['nombre'] ?? '')),
    ];
  }

  /** @return array<string,bool> */
  private function findOwnerIdsByDocument(string $rawLookup, string $docKey): array
  {
    $table = $this->db->table('jet_cct_propietarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $like = '%' . $this->db->escapeLike(trim($rawLookup)) . '%';
    $rows = $this->db->getResults(
      "SELECT `_ID`, `id_propietario`, `documento`, `documento_juridico`
         FROM `{$table}`
        WHERE TRIM(COALESCE(`documento`, '')) LIKE ?
           OR TRIM(COALESCE(`documento_juridico`, '')) LIKE ?
           OR TRIM(COALESCE(`id_propietario`, '')) LIKE ?
        LIMIT 300",
      [$like, $like, $like]
    );

    $ids = [];
    foreach ($rows as $row) {
      $keys = [
        $this->normalizeAlphaNum((string) ($row['documento'] ?? '')),
        $this->normalizeAlphaNum((string) ($row['documento_juridico'] ?? '')),
        $this->normalizeAlphaNum((string) ($row['id_propietario'] ?? '')),
      ];
      if (!in_array($docKey, $keys, true)) {
        continue;
      }
      $idA = trim((string) ($row['_ID'] ?? ''));
      $idB = trim((string) ($row['id_propietario'] ?? ''));
      if ($idA !== '') {
        $ids[$idA] = true;
      }
      if ($idB !== '') {
        $ids[$idB] = true;
      }
    }
    return $ids;
  }

  /** @return array<string,bool> */
  private function findTenantIdsByDocument(string $rawLookup, string $docKey): array
  {
    $table = $this->db->table('jet_cct_arrendatarios');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $like = '%' . $this->db->escapeLike(trim($rawLookup)) . '%';
    $rows = $this->db->getResults(
      "SELECT `_ID`, `id_arrendatario`, `documento`, `documento_juridico`
         FROM `{$table}`
        WHERE TRIM(COALESCE(`documento`, '')) LIKE ?
           OR TRIM(COALESCE(`documento_juridico`, '')) LIKE ?
           OR TRIM(COALESCE(`id_arrendatario`, '')) LIKE ?
        LIMIT 300",
      [$like, $like, $like]
    );

    $ids = [];
    foreach ($rows as $row) {
      $keys = [
        $this->normalizeAlphaNum((string) ($row['documento'] ?? '')),
        $this->normalizeAlphaNum((string) ($row['documento_juridico'] ?? '')),
        $this->normalizeAlphaNum((string) ($row['id_arrendatario'] ?? '')),
      ];
      if (!in_array($docKey, $keys, true)) {
        continue;
      }
      $idA = trim((string) ($row['_ID'] ?? ''));
      $idB = trim((string) ($row['id_arrendatario'] ?? ''));
      if ($idA !== '') {
        $ids[$idA] = true;
      }
      if ($idB !== '') {
        $ids[$idB] = true;
      }
    }
    return $ids;
  }

  /** @return array<string,bool> */
  private function findCopropiedadIds(string $rawLookup, string $nitDigits): array
  {
    $table = $this->db->table('jet_cct_copropiedades');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $like = '%' . $this->db->escapeLike(trim($rawLookup)) . '%';
    $rows = $this->db->getResults(
      "SELECT `_ID`, `nit`, `copropiedad`
         FROM `{$table}`
        WHERE TRIM(COALESCE(`nit`, '')) LIKE ?
           OR TRIM(COALESCE(`copropiedad`, '')) LIKE ?
        LIMIT 300",
      [$like, $like]
    );

    $ids = [];
    foreach ($rows as $row) {
      $id = trim((string) ($row['_ID'] ?? ''));
      if ($id === '') {
        continue;
      }
      $nit = $this->normalizeDigits((string) ($row['nit'] ?? ''));
      if ($nitDigits !== '' && $nit !== '' && $nit === $nitDigits) {
        $ids[$id] = true;
        continue;
      }
      if ($nitDigits === '') {
        $ids[$id] = true;
      }
    }
    return $ids;
  }

  /** @return array<int,array<string,mixed>> */
  private function getContractsCatalog(): array
  {
    if (is_array($this->contractsCache)) {
      return $this->contractsCache;
    }

    $contractsTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($contractsTable)) {
      $this->contractsCache = [];
      return [];
    }

    $funcTable = $this->db->table('jet_cct_funcionarios');
    $joinFuncionario = $this->schema->tableExists($funcTable)
      ? "LEFT JOIN `{$funcTable}` f ON TRIM(COALESCE(c.`id_empleado`, '')) = TRIM(COALESCE(f.`id_empleado`, ''))"
      : '';

    $sql = "SELECT c.*,
              TRIM(COALESCE(f.`nombre`, '')) AS funcionario_nombre,
              TRIM(COALESCE(f.`correo`, '')) AS funcionario_correo,
              TRIM(COALESCE(f.`celular`, '')) AS funcionario_celular
            FROM `{$contractsTable}` c
            {$joinFuncionario}
            ORDER BY c.`_ID` DESC
            LIMIT 5000";
    $this->contractsCache = $this->db->getResults($sql);
    return $this->contractsCache;
  }

  /** @return array<string,mixed>|null */
  private function fetchContractById(string $id): ?array
  {
    foreach ($this->getContractsCatalog() as $row) {
      if (trim((string) ($row['_ID'] ?? '')) === trim($id)) {
        return $row;
      }
    }
    return null;
  }

  /** @return array<string,mixed>|null */
  private function fetchClientById(string $id): ?array
  {
    $table = $this->db->table('jet_cct_clientes');
    if (!$this->schema->tableExists($table)) {
      return null;
    }

    $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [trim($id)]);
    return is_array($row) ? $row : null;
  }

  private function isDeliveredContract(array $contract): bool
  {
    return mb_strtolower(trim((string) ($contract['estado'] ?? '')), 'UTF-8') === 'entregado';
  }

  private function requiresDeliveredContractTheme(string $theme): bool
  {
    return in_array($this->normalizePqrTheme($theme), [
      'Entrega de inmuebles',
      'Revision preventiva',
      'Recibo de inmuebles',
      'Procesos juridicos',
      'Solicitud contractual',
      'Solicitud de servicios publicos',
      'Reparaciones necesarias',
      'Reparaciones locativas',
      'Mejoras utiles',
      'Reparaciones voluntarias',
      'Reparaciones antes de la entrega',
      'Reparaciones antes del recibo',
      'Contable y tributaria',
      'Certificaciones tributarias',
    ], true);
  }

  /** @return string[] */
  private function buildPropertyCodeCandidates(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $candidates = [$raw];
    $decoded = urldecode($raw);
    if ($decoded !== $raw) {
      $candidates[] = $decoded;
    }

    $url = parse_url($raw);
    if (is_array($url)) {
      if (!empty($url['path'])) {
        $path = trim((string) $url['path'], "/ \t\n\r\0\x0B");
        $parts = preg_split('#/+#', $path);
        if (is_array($parts)) {
          $last = trim((string) end($parts));
          if ($last !== '') {
            $candidates[] = $last;
          }
        }
      }

      if (!empty($url['query'])) {
        parse_str((string) $url['query'], $query);
        foreach (['codigo', 'code', 'cod', 'inmueble', 'id', 'numero'] as $key) {
          if (!empty($query[$key]) && !is_array($query[$key])) {
            $candidates[] = (string) $query[$key];
          }
        }
      }
    }

    preg_match_all('/[A-Za-z0-9-]{3,}/', $raw, $matches);
    foreach ((array) ($matches[0] ?? []) as $token) {
      $candidates[] = (string) $token;
    }

    $out = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate === '') {
        continue;
      }
      $out[$candidate] = true;

      $digits = $this->normalizeDigits($candidate);
      if ($digits !== '') {
        $out[$digits] = true;
      }
    }

    return array_keys($out);
  }

  /** @return array<int,array<string,mixed>> */
  private function searchSimilarPublishedPropertiesByCode(string $rawCode, int $limit = 5): array
  {
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $candidates = $this->buildPropertyCodeCandidates($rawCode);
    $digits = '';
    foreach ($candidates as $candidate) {
      $digits = $this->normalizeDigits((string) $candidate);
      if (strlen($digits) >= 3) {
        break;
      }
    }

    if (strlen($digits) < 3) {
      return [];
    }

    $parts = [$digits];
    if (strlen($digits) > 4) {
      $parts[] = substr($digits, 0, 4);
      $parts[] = substr($digits, -4);
    }
    if (strlen($digits) > 3) {
      $parts[] = substr($digits, 0, 3);
      $parts[] = substr($digits, -3);
    }

    $parts = array_values(array_unique(array_filter($parts, static fn($part) => is_string($part) && strlen($part) >= 3)));
    $whereCode = [];
    $args = [];
    foreach ($parts as $part) {
      $whereCode[] = "TRIM(COALESCE(`codigo`, '')) LIKE ?";
      $args[] = '%' . $this->db->escapeLike($part) . '%';
    }

    if ($whereCode === []) {
      return [];
    }

    $limit = max(1, min($limit, 10));
    $sql = "SELECT *
              FROM `{$table}`
             WHERE LOWER(TRIM(COALESCE(`cct_status`, ''))) IN ('publish', 'published', '')
               AND LOWER(TRIM(COALESCE(`estado`, ''))) = 'publico'
               AND (" . implode(' OR ', $whereCode) . ")
             ORDER BY `_ID` DESC
             LIMIT " . $limit;

    $rows = $this->db->getResults($sql, $args);
    return array_map(fn(array $row): array => $this->formatPublishedProperty($row), $rows);
  }

  /** @return array<string,mixed> */
  private function formatPublishedProperty(array $row): array
  {
    $codigo = trim((string) ($row['codigo'] ?? ''));
    $tipoNegocio = trim((string) ($row['tipo_negocio'] ?? ''));
    $precioArriendo = trim((string) ($row['precio_arriendo'] ?? ''));
    $precioVenta = trim((string) ($row['precio_venta'] ?? ''));
    $precioAdmin = trim((string) ($row['precio_admin'] ?? $row['valor_administracion'] ?? ''));

    return [
      'id' => trim((string) ($row['_ID'] ?? '')),
      'codigo' => $codigo,
      'estado' => trim((string) ($row['estado'] ?? '')),
      'tipo_inmueble' => trim((string) ($row['tipo_inmueble'] ?? '')),
      'tipo_negocio' => $tipoNegocio,
      'categoria' => $tipoNegocio,
      'destinacion' => trim((string) ($row['destinacion'] ?? '')),
      'barrio' => trim((string) ($row['barrio'] ?? '')),
      'ciudad' => trim((string) ($row['ciudad'] ?? '')),
      'direccion' => trim((string) ($row['direccion'] ?? '')),
      'habitaciones' => trim((string) ($row['habitaciones'] ?? '')),
      'banos' => trim((string) ($row['banos'] ?? '')),
      'precio_arriendo' => $precioArriendo,
      'precio_venta' => $precioVenta,
      'precio_admin' => $precioAdmin,
      'valor_administracion' => $precioAdmin,
      'area_construida' => trim((string) ($row['area_construida'] ?? '')),
      'area_terreno' => trim((string) ($row['area_terreno'] ?? '')),
      'medio' => 'WhatsApp cliente',
      'display' => trim(implode(' - ', array_filter([$codigo, trim((string) ($row['tipo_inmueble'] ?? '')), trim((string) ($row['barrio'] ?? ''))]))),
    ];
  }

  /** @return array<string,mixed> */
  private function formatContractLookupRow(array $contract, string $actor): array
  {
    $contactName = '';
    $contactEmail = '';
    $contactPhone = '';
    $contactIndicativo = '+57';
    $actorId = '';

    if ($actor === 'propietario') {
      $contactName = trim((string) ($contract['propietario'] ?? ''));
      $contactEmail = trim((string) ($contract['correo_propietario'] ?? ''));
      $contactPhone = trim((string) ($contract['celular_propietario'] ?? ''));
      $contactIndicativo = $this->normalizeIndicativo((string) ($contract['indicativo_propietario'] ?? '+57'));
      $actorId = trim((string) ($contract['id_propietario'] ?? ''));
    } elseif ($actor === 'arrendatario') {
      $contactName = trim((string) ($contract['arrendatario'] ?? ''));
      $contactEmail = trim((string) ($contract['correo_arrendatario'] ?? ''));
      $contactPhone = trim((string) ($contract['celular_arrendatario'] ?? ''));
      $contactIndicativo = $this->normalizeIndicativo((string) ($contract['indicativo_arrendatario'] ?? '+57'));
      $actorId = trim((string) ($contract['id_arrendatario'] ?? ''));
    } elseif ($actor === 'copropiedad') {
      $contactName = trim((string) ($contract['administrador'] ?? $contract['copropiedad'] ?? ''));
      $contactEmail = trim((string) ($contract['correo_copropiedad'] ?? ''));
      $contactPhone = trim((string) ($contract['celular_copropiedad'] ?? ''));
      $contactIndicativo = $this->normalizeIndicativo((string) ($contract['indicativo'] ?? '+57'));
      $actorId = trim((string) ($contract['id_copropiedad'] ?? ''));
    }

    return [
      'id' => trim((string) ($contract['_ID'] ?? '')),
      'type' => 'contract',
      'actor_id' => $actorId,
      'contrato' => trim((string) ($contract['contrato'] ?? '')),
      'estado' => trim((string) ($contract['estado'] ?? '')),
      'inmueble' => trim((string) ($contract['inmueble'] ?? '')),
      'direccion' => trim((string) ($contract['direccion'] ?? '')),
      'barrio' => trim((string) ($contract['barrio'] ?? '')),
      'sucursal' => trim((string) ($contract['sucursal'] ?? '')),
      'propietario' => trim((string) ($contract['propietario'] ?? '')),
      'arrendatario' => trim((string) ($contract['arrendatario'] ?? '')),
      'copropiedad' => trim((string) ($contract['copropiedad'] ?? '')),
      'nit_copropiedad' => trim((string) ($contract['nit_copropiedad'] ?? '')),
      'contact_name' => $contactName,
      'contact_email' => $contactEmail,
      'contact_phone' => $contactPhone,
      'contact_indicativo' => $contactIndicativo,
      'responsable_id' => trim((string) ($contract['id_empleado'] ?? '')),
      'responsable_nombre' => trim((string) ($contract['funcionario_nombre'] ?? '')),
      'responsable_correo' => trim((string) ($contract['funcionario_correo'] ?? '')),
      'responsable_celular' => trim((string) ($contract['funcionario_celular'] ?? '')),
      'display' => trim((string) ($contract['contrato'] ?? $contract['_ID'] ?? '')),
    ];
  }

  private function contractMatchesLookup(string $actor, array $contract, string $lookupValue): bool
  {
    $lookupValue = trim($lookupValue);
    if ($lookupValue === '') {
      return true;
    }

    if ($actor === 'propietario') {
      $docKey = $this->normalizeAlphaNum($lookupValue);
      if ($docKey === '') {
        return true;
      }
      $contractDoc = $this->normalizeAlphaNum((string) ($contract['documento_propietario'] ?? ''));
      if ($contractDoc !== '' && $contractDoc === $docKey) {
        return true;
      }
      $ownerIds = $this->findOwnerIdsByDocument($lookupValue, $docKey);
      $ownerId = trim((string) ($contract['id_propietario'] ?? ''));
      return $ownerId !== '' && isset($ownerIds[$ownerId]);
    }

    if ($actor === 'arrendatario') {
      $docKey = $this->normalizeAlphaNum($lookupValue);
      if ($docKey === '') {
        return true;
      }
      $contractDoc = $this->normalizeAlphaNum((string) ($contract['documento_arrendatario'] ?? ''));
      if ($contractDoc !== '' && $contractDoc === $docKey) {
        return true;
      }
      $tenantIds = $this->findTenantIdsByDocument($lookupValue, $docKey);
      $tenantId = trim((string) ($contract['id_arrendatario'] ?? ''));
      return $tenantId !== '' && isset($tenantIds[$tenantId]);
    }

    if ($actor === 'copropiedad') {
      $lookupDigits = $this->normalizeDigits($lookupValue);
      if ($lookupDigits !== '') {
        $contractNit = $this->normalizeDigits((string) ($contract['nit_copropiedad'] ?? ''));
        if ($contractNit !== '' && $contractNit === $lookupDigits) {
          return true;
        }
      }
      $lookupText = mb_strtolower($lookupValue, 'UTF-8');
      $name = mb_strtolower((string) ($contract['copropiedad'] ?? ''), 'UTF-8');
      return $lookupText !== '' && $name !== '' && strpos($name, $lookupText) !== false;
    }

    return true;
  }

  /**
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @param array<string,mixed> $input
   * @return array{name:string,email:string,phone:string,indicativo:string,actor_id:string}
   */
  private function buildRequesterContact(string $actor, ?array $contract, ?array $client, array $input): array
  {
    $name = trim((string) ($input['solicitante'] ?? ''));
    $email = trim((string) ($input['correo_solicitante'] ?? ''));
    $phone = trim((string) ($input['celular_solicitante'] ?? ''));
    $indicativo = $this->normalizeIndicativo((string) ($input['indicativo'] ?? '+57'));
    $actorId = trim((string) ($input['actor_id'] ?? ''));

    if ($actor === 'propietario' && is_array($contract)) {
      $name = $name !== '' ? $name : trim((string) ($contract['propietario'] ?? ''));
      $email = $email !== '' ? $email : trim((string) ($contract['correo_propietario'] ?? ''));
      $phone = $phone !== '' ? $phone : trim((string) ($contract['celular_propietario'] ?? ''));
      $indicativo = $indicativo !== '' ? $indicativo : $this->normalizeIndicativo((string) ($contract['indicativo_propietario'] ?? '+57'));
      $actorId = $actorId !== '' ? $actorId : trim((string) ($contract['id_propietario'] ?? ''));
    } elseif ($actor === 'arrendatario' && is_array($contract)) {
      $name = $name !== '' ? $name : trim((string) ($contract['arrendatario'] ?? ''));
      $email = $email !== '' ? $email : trim((string) ($contract['correo_arrendatario'] ?? ''));
      $phone = $phone !== '' ? $phone : trim((string) ($contract['celular_arrendatario'] ?? ''));
      $indicativo = $indicativo !== '' ? $indicativo : $this->normalizeIndicativo((string) ($contract['indicativo_arrendatario'] ?? '+57'));
      $actorId = $actorId !== '' ? $actorId : trim((string) ($contract['id_arrendatario'] ?? ''));
    } elseif ($actor === 'copropiedad' && is_array($contract)) {
      $name = $name !== '' ? $name : trim((string) ($contract['administrador'] ?? $contract['copropiedad'] ?? ''));
      $email = $email !== '' ? $email : trim((string) ($contract['correo_copropiedad'] ?? ''));
      $phone = $phone !== '' ? $phone : trim((string) ($contract['celular_copropiedad'] ?? ''));
      $indicativo = $indicativo !== '' ? $indicativo : $this->normalizeIndicativo((string) ($contract['indicativo'] ?? '+57'));
      $actorId = $actorId !== '' ? $actorId : trim((string) ($contract['id_copropiedad'] ?? ''));
    } elseif ($actor === 'cliente' && is_array($client)) {
      $name = trim((string) ($client['nombre'] ?? ''));
      $email = trim((string) ($client['correo'] ?? ''));
      $phone = trim((string) ($client['celular'] ?? ''));
      $indicativo = $this->normalizeIndicativo((string) ($client['indicativo'] ?? '+57'));
      $actorId = trim((string) ($client['_ID'] ?? ''));
    }

    return [
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'indicativo' => $indicativo,
      'actor_id' => $actorId,
    ];
  }

  /**
   * @param array<string,mixed>|null $contract
   * @return array<string,string>
   */
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
  private function insertTicket(
    string $actor,
    array $config,
    string $tipoPqrs,
    string $departamento,
    string $asunto,
    string $descripcion,
    array $input,
    array $contact,
    array $responsable,
    ?array $contract,
    ?array $client
  ): array {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return ['ok' => false, 'message' => 'No existe la tabla de tickets.'];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $actorLabel = $this->getPqrCreatedByLabel($actor, $config);
    $clientPk = $actor === 'cliente' && is_array($client)
      ? trim((string) ($client['_ID'] ?? ''))
      : '';

    $data = [
      'cct_status' => 'publish',
      'cct_author_id' => 0,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_ticket' => '',
      'id_creador' => $clientPk !== '' ? $clientPk : (string) $contact['actor_id'],
      'asunto' => $asunto,
      'descripcion' => $descripcion,
      'fecha_actualizacion' => $nowTs,
      'empleado' => (string) ($responsable['nombre'] ?? ''),
      'id_empleado' => (string) ($responsable['id_empleado'] ?? ''),
      'nombre_empleado' => (string) ($responsable['nombre'] ?? ''),
      'correo_empleado' => (string) ($responsable['correo'] ?? ''),
      'celular_empleado' => (string) ($responsable['celular'] ?? ''),
      'prioridad' => 'Prioridad urgente',
      'tema_ayuda' => $tipoPqrs,
      'departamento' => $departamento,
      'estado' => 'Nuevo',
      'estado_rev_correctiva' => 'No',
      'estado_rev_preventiva' => 'No',
      'estado_cotizacion_mantenimiento' => 'No',
      'estado_acta_satisfaccion' => 'No',
      'tuvo_reporte' => 'No',
      'medio' => (string) ($config['medium'] ?? 'Portal autoservicio'),
      'creado_por' => $actorLabel,
      'creador_por' => $actorLabel,
      'solicitante' => (string) $contact['name'],
      'correo_solicitante' => (string) $contact['email'],
      'celular_solicitante' => (string) $contact['phone'],
      'indicativo' => (string) $contact['indicativo'],
      'fecha' => $nowTs,
      'id_solicitante' => $clientPk !== '' ? $clientPk : (string) $contact['actor_id'],
      'seguimientos' => '0',
      'tuvo_seguimiento' => 'No',
    ];

    if ($actor === 'cliente' || in_array($tipoPqrs, ['Captacion', 'Avaluo'], true)) {
      $data['estado_comercial'] = 'Nuevo';
    } else {
      $data['estado_administrativo'] = 'Nuevo';
    }

    $tipoInmueble = trim((string) ($input['tipo_inmueble'] ?? ''));
    if ($tipoInmueble !== '') {
      $data['tipo_inmueble'] = $tipoInmueble;
    }

    $destinacion = trim((string) ($input['destinacion'] ?? ''));
    if ($destinacion !== '') {
      $data['destinacion'] = $destinacion;
    }

    $presupuesto = trim((string) ($input['presupuesto'] ?? ''));
    if ($presupuesto !== '') {
      $data['presupuesto'] = $presupuesto;
    }

    $sucursal = trim((string) ($input['sucursal'] ?? ''));
    if ($sucursal !== '') {
      $data['sucursal'] = $sucursal;
    }

    $publishedPropertyFields = [
      'id_inmueble',
      'id_inmueble_data',
      'codigo_inmueble',
      'direccion',
      'barrio',
      'ciudad',
      'habitaciones',
      'banos',
      'precio_arriendo',
      'precio_venta',
      'precio_admin',
      'area_construida',
      'area_terreno',
    ];
    foreach ($publishedPropertyFields as $field) {
      $value = trim((string) ($input[$field] ?? ''));
      if ($value !== '') {
        $data[$field] = $value;
      }
    }

    if (is_array($contract)) {
      $data['id_contrato'] = trim((string) ($contract['_ID'] ?? ''));
      $data['contrato'] = trim((string) ($contract['contrato'] ?? ''));
      $data['id_inmueble'] = trim((string) ($contract['id_inmueble'] ?? ''));
      $data['id_inmueble_data'] = trim((string) ($contract['id_inmueble_data'] ?? ''));
      $data['inmueble'] = trim((string) ($contract['inmueble'] ?? ''));
      $data['direccion'] = trim((string) ($contract['direccion'] ?? ''));
      $data['barrio'] = trim((string) ($contract['barrio'] ?? ''));
      $data['sucursal'] = trim((string) ($contract['sucursal'] ?? ''));
      $data['id_propietario'] = trim((string) ($contract['id_propietario'] ?? ''));
      $data['id_arrendatario'] = trim((string) ($contract['id_arrendatario'] ?? ''));
      $data['propietario'] = trim((string) ($contract['propietario'] ?? ''));
      $data['correo_propietario'] = trim((string) ($contract['correo_propietario'] ?? ''));
      $data['celular_propietario'] = trim((string) ($contract['celular_propietario'] ?? ''));
      $data['indicativo_propietario'] = $this->normalizeIndicativo((string) ($contract['indicativo_propietario'] ?? ''));
      $data['arrendatario'] = trim((string) ($contract['arrendatario'] ?? ''));
      $data['correo_arrendatario'] = trim((string) ($contract['correo_arrendatario'] ?? ''));
      $data['celular_arrendatario'] = trim((string) ($contract['celular_arrendatario'] ?? ''));
      $data['indicativo_arrendatario'] = $this->normalizeIndicativo((string) ($contract['indicativo_arrendatario'] ?? ''));
    } elseif ($actor === 'propietario') {
      $data['id_propietario'] = (string) $contact['actor_id'];
      $data['propietario'] = (string) $contact['name'];
      $data['correo_propietario'] = (string) $contact['email'];
      $data['celular_propietario'] = (string) $contact['phone'];
      $data['indicativo_propietario'] = (string) $contact['indicativo'];
    } elseif ($actor === 'arrendatario') {
      $data['id_arrendatario'] = (string) $contact['actor_id'];
      $data['arrendatario'] = (string) $contact['name'];
      $data['correo_arrendatario'] = (string) $contact['email'];
      $data['celular_arrendatario'] = (string) $contact['phone'];
      $data['indicativo_arrendatario'] = (string) $contact['indicativo'];
    }

    if ($actor === 'cliente' && is_array($client)) {
      $data['id_cliente'] = $clientPk;
    }

    $insertData = $this->schema->filterTableData($ticketsTable, $data);
    if (empty($insertData)) {
      return ['ok' => false, 'message' => 'No se pudo construir el payload del ticket.'];
    }

    try {
      $this->db->insert($ticketsTable, $insertData);
      $ticketId = (int) $this->db->lastInsertId();
    } catch (\Throwable $e) {
      return ['ok' => false, 'message' => 'Error insertando ticket: ' . $e->getMessage()];
    }

    if ($ticketId <= 0) {
      return ['ok' => false, 'message' => 'No se pudo obtener el ID del ticket creado.'];
    }

    $updateData = $this->schema->filterTableData($ticketsTable, [
      'id_ticket' => (string) $ticketId,
      'fecha_actualizacion' => $nowTs,
      'cct_modified' => $nowMysql,
    ]);
    if (!empty($updateData)) {
      $this->db->update($ticketsTable, $updateData, ['_ID' => $ticketId]);
    }

    return ['ok' => true, 'ticket_id' => $ticketId];
  }

  /**
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @return array<int,array<string,mixed>>
   */
  private function findRequestsForSelection(string $actor, ?array $contract, ?array $client, array $options = []): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable)) {
      return [];
    }

    $where = [];
    $args = [];
    $extraWhere = [];
    if ($actor !== 'cliente') {
      $actorLabel = mb_strtolower($this->getPqrCreatedByLabel($actor, self::getActorConfig($actor)), 'UTF-8');
      if ($this->schema->columnExists($ticketsTable, 'creado_por')) {
        $where[] = "LOWER(TRIM(COALESCE(`creado_por`, ''))) = ?";
        $args[] = $actorLabel;
      }
      if ($this->schema->columnExists($ticketsTable, 'creador_por')) {
        $where[] = "LOWER(TRIM(COALESCE(`creador_por`, ''))) = ?";
        $args[] = $actorLabel;
      }
      if ($this->schema->columnExists($ticketsTable, 'medio')) {
        $where[] = "LOWER(TRIM(COALESCE(`medio`, ''))) = ?";
        $args[] = mb_strtolower(trim((string) (self::getActorConfig($actor)['medium'] ?? '')), 'UTF-8');
      }
    }

    $identityClauses = [];
    if (!empty($options['all_by_solicitante'])) {
      $actorIds = [];
      if (is_array($options['actor_ids'] ?? null)) {
        foreach ($options['actor_ids'] as $actorId) {
          $actorId = trim((string) $actorId);
          if ($actorId !== '' && !in_array($actorId, $actorIds, true)) {
            $actorIds[] = $actorId;
          }
        }
      }

      $actorId = trim((string) ($options['actor_id'] ?? ''));
      if ($actorId !== '' && !in_array($actorId, $actorIds, true)) {
        $actorIds[] = $actorId;
      }

      $lookupValue = trim((string) ($options['lookup_value'] ?? ''));
      if ($lookupValue !== '') {
        if ($actor === 'propietario') {
          foreach (array_keys($this->findOwnerIdsByDocument($lookupValue, $this->normalizeAlphaNum($lookupValue))) as $ownerId) {
            if ($ownerId !== '' && !in_array($ownerId, $actorIds, true)) {
              $actorIds[] = $ownerId;
            }
          }
        } elseif ($actor === 'arrendatario') {
          foreach (array_keys($this->findTenantIdsByDocument($lookupValue, $this->normalizeAlphaNum($lookupValue))) as $tenantId) {
            if ($tenantId !== '' && !in_array($tenantId, $actorIds, true)) {
              $actorIds[] = $tenantId;
            }
          }
        } elseif ($actor === 'copropiedad') {
          foreach (array_keys($this->findCopropiedadIds($lookupValue, $this->normalizeDigits($lookupValue))) as $coproId) {
            if ($coproId !== '' && !in_array($coproId, $actorIds, true)) {
              $actorIds[] = $coproId;
            }
          }
        }
      }

      if ($actorIds !== [] && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) IN (" . implode(', ', array_fill(0, count($actorIds), '?')) . ")";
        foreach ($actorIds as $actorIdItem) {
          $args[] = $actorIdItem;
        }
      }
    } elseif (is_array($contract)) {
      $contractId = trim((string) ($contract['_ID'] ?? ''));
      $contractCode = trim((string) ($contract['contrato'] ?? ''));
      $actorIdField = $actor === 'propietario' ? 'id_propietario' : ($actor === 'arrendatario' ? 'id_arrendatario' : 'id_copropiedad');
      $actorId = trim((string) ($contract[$actorIdField] ?? ''));

      if ($contractId !== '' && $this->schema->columnExists($ticketsTable, 'id_contrato')) {
        $identityClauses[] = "TRIM(COALESCE(`id_contrato`, '')) = ?";
        $args[] = $contractId;
      }
      if ($contractCode !== '' && $this->schema->columnExists($ticketsTable, 'contrato')) {
        $identityClauses[] = "TRIM(COALESCE(`contrato`, '')) = ?";
        $args[] = $contractCode;
      }
      if ($actorId !== '' && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) = ?";
        $args[] = $actorId;
      }
    } elseif (is_array($client)) {
      $clientId = trim((string) ($client['_ID'] ?? ''));
      if ($clientId !== '' && $this->schema->columnExists($ticketsTable, 'id_cliente')) {
        $identityClauses[] = "TRIM(COALESCE(`id_cliente`, '')) = ?";
        $args[] = $clientId;
      }
    } elseif (!empty($options['no_contract_commercial'])) {
      $actorId = trim((string) ($options['actor_id'] ?? ''));
      $actorIdField = $actor === 'propietario' ? 'id_propietario' : ($actor === 'arrendatario' ? 'id_arrendatario' : '');
      if ($actorId !== '' && $actorIdField !== '' && $this->schema->columnExists($ticketsTable, $actorIdField)) {
        $identityClauses[] = "TRIM(COALESCE(`{$actorIdField}`, '')) = ?";
        $args[] = $actorId;
      }
      if ($actorId !== '' && $this->schema->columnExists($ticketsTable, 'id_solicitante')) {
        $identityClauses[] = "TRIM(COALESCE(`id_solicitante`, '')) = ?";
        $args[] = $actorId;
      }
    }

    if (empty($identityClauses)) {
      return [];
    }

    if (empty($where)) {
      if ($actor === 'cliente') {
        $where[] = '1=1';
      } else {
        return [];
      }
    }

    $hasTemaAyuda = $this->schema->columnExists($ticketsTable, 'tema_ayuda');
    $hasTipoPqrs = $this->schema->columnExists($ticketsTable, 'tipo_pqrs');
    if ($hasTemaAyuda && $hasTipoPqrs) {
      $tipoExpr = "TRIM(COALESCE(`tema_ayuda`, `tipo_pqrs`, ''))";
    } elseif ($hasTemaAyuda) {
      $tipoExpr = "TRIM(COALESCE(`tema_ayuda`, ''))";
    } else {
      $tipoExpr = "TRIM(COALESCE(`tipo_pqrs`, ''))";
    }

    $temaFiltro = trim((string) ($options['tema_ayuda'] ?? ''));
    if ($temaFiltro !== '') {
      $extraWhere[] = "{$tipoExpr} = ?";
      $args[] = $temaFiltro;
    } else {
      $temaFiltros = [];
      if (is_array($options['tema_ayuda_in'] ?? null)) {
        foreach ($options['tema_ayuda_in'] as $temaItem) {
          $temaItem = trim((string) $temaItem);
          if ($temaItem !== '' && !in_array($temaItem, $temaFiltros, true)) {
            $temaFiltros[] = $temaItem;
          }
        }
      }

      if ($temaFiltros !== []) {
        $extraWhere[] = "{$tipoExpr} IN (" . implode(', ', array_fill(0, count($temaFiltros), '?')) . ")";
        foreach ($temaFiltros as $temaItem) {
          $args[] = $temaItem;
        }
      }
    }

    $idContratoExpr = $this->schema->columnExists($ticketsTable, 'id_contrato')
      ? "TRIM(COALESCE(`id_contrato`, ''))"
      : "''";

    $sql = "SELECT
              `_ID`,
              TRIM(COALESCE(`id_ticket`, '')) AS id_ticket,
              TRIM(COALESCE(`asunto`, '')) AS asunto,
              {$tipoExpr} AS tema_ayuda,
              TRIM(COALESCE(`estado`, '')) AS estado,
              TRIM(COALESCE(`departamento`, '')) AS departamento,
              COALESCE(`fecha_actualizacion`, `fecha`, 0) AS fecha_ref,
              {$idContratoExpr} AS id_contrato,
              TRIM(COALESCE(`contrato`, '')) AS contrato,
              TRIM(COALESCE(`inmueble`, '')) AS inmueble,
              TRIM(COALESCE(`direccion`, '')) AS direccion
            FROM `{$ticketsTable}`
            WHERE (" . implode(' OR ', $where) . ")
              AND (" . implode(' OR ', $identityClauses) . ")"
      . ($extraWhere !== [] ? " AND " . implode(' AND ', $extraWhere) : "") . "
            ORDER BY `_ID` DESC
            LIMIT 20";

    $rows = $this->db->getResults($sql, $args);
    $out = [];
    foreach ($rows as $row) {
      $logicalId = trim((string) ($row['id_ticket'] ?? ''));
      if ($logicalId === '') {
        $logicalId = trim((string) ($row['_ID'] ?? ''));
      }
      $timestamp = (int) ($row['fecha_ref'] ?? 0);
      $out[] = [
        'ticket_id' => $logicalId,
        'asunto' => trim((string) ($row['asunto'] ?? '')),
        'tema_ayuda' => trim((string) ($row['tema_ayuda'] ?? '')),
        'estado' => trim((string) ($row['estado'] ?? '')),
        'departamento' => trim((string) ($row['departamento'] ?? '')),
        'fecha' => $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '',
        'contrato' => trim((string) ($row['contrato'] ?? '')),
        'origen' => (trim((string) ($row['contrato'] ?? '')) !== '' || trim((string) ($row['id_contrato'] ?? '')) !== '') ? 'Contrato' : 'Comercial',
        'inmueble' => trim((string) ($row['inmueble'] ?? '')),
        'direccion' => trim((string) ($row['direccion'] ?? '')),
        'ticket_url' => $this->buildPublicTicketUrl($logicalId),
      ];
    }

    return $out;
  }

  private function buildPublicTicketUrl(string $logicalTicket): string
  {
    $logicalTicket = trim($logicalTicket);
    if ($logicalTicket === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
  }

  private function buildClientQuoteUrl(string $quoteNumero): string
  {
    $quoteNumero = trim($quoteNumero);
    if ($quoteNumero === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/cotizacion-de-inmuebles/?numero=' . rawurlencode($quoteNumero);
  }

  private function buildClientStudyUrl(string $studyPk): string
  {
    $studyPk = trim($studyPk);
    if ($studyPk === '') {
      return '';
    }

    return 'https://sucasainmobiliaria.com.co/estudio-aseguradora/?id_estudio=' . rawurlencode($studyPk);
  }

  private function insertTicketHistory(int $ticketPk, string $logicalTicket, string $actor, string $solicitante, string $responsableId): void
  {
    $historyTable = $this->db->table('jet_cct_historial_del_ticket');
    if (!$this->schema->tableExists($historyTable)) {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $obs = 'PQR creado desde portal de ' . ucfirst($actor) . '.';

    $payload = [
      'cct_status' => 'publish',
      'id_ticket' => $ticketPk,
      'fecha' => $nowTs,
      'nombre' => $solicitante !== '' ? $solicitante : ucfirst($actor),
      'observacion' => $obs,
      'cct_author_id' => $responsableId !== '' ? $responsableId : 0,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $responsableId,
      'fue_editada' => 'No',
    ];

    $payload = $this->schema->filterTableData($historyTable, $payload);
    if (empty($payload)) {
      return;
    }

    try {
      $this->db->insert($historyTable, $payload);
    } catch (\Throwable $e) {
      // no-op
    }
  }

  /**
   * @param array{name:string,email:string,phone:string,indicativo:string,actor_id:string} $contact
   * @param array<string,string> $responsable
   * @param array<string,mixed>|null $contract
   * @param array<string,mixed>|null $client
   * @return array<string,mixed>
   */
  private function notifyResponsible(
    int $ticketPk,
    string $logicalTicket,
    string $asunto,
    string $tipoPqrs,
    string $departamento,
    array $contact,
    array $responsable,
    ?array $contract,
    ?array $client,
    string $actor,
    string $actorLabel
  ): array {
    $emailQueued = false;
    $smsQueued = false;
    $clienteOverride = $this->resolveClienteNotificationsOverride($actor);

    $responsableEmail = trim((string) ($responsable['correo'] ?? ''));
    $responsablePhone = trim((string) ($responsable['celular'] ?? ''));
    $responsableName = trim((string) ($responsable['nombre'] ?? ''));
    $responsableId = trim((string) ($responsable['id_empleado'] ?? ''));
    $responsableLabel = $responsableName !== '' ? $responsableName : ($responsableId !== '' ? 'Funcionario #' . $responsableId : 'Sin asignar');
    $solicitanteEmail = trim((string) ($contact['email'] ?? ''));
    $solicitantePhone = trim((string) ($contact['phone'] ?? ''));

    $ticketUrl = 'https://sucasainmobiliaria.com.co/ticket/?id_ticket=' . rawurlencode($logicalTicket);
    $subject = 'Nueva Solicitud #' . $logicalTicket . ' asignada';
    $contractLabel = '';
    if (is_array($contract)) {
      $contractLabel = trim((string) ($contract['contrato'] ?? $contract['_ID'] ?? ''));
    }

    $content = '<p style="font-weight:600;margin:0 0 12px;">Se registro una nueva solicitud desde el portal de ' . EmailTemplate::e($actorLabel) . '.</p>';
    $content .= '<p style="margin:4px 0;"><b>Solicitud:</b> #' . EmailTemplate::e($logicalTicket) . '</p>';
    if ($contractLabel !== '') {
      $content .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e($contractLabel) . '</p>';
    }
    if (is_array($contract)) {
      $inmuebleLabel = trim((string) ($contract['inmueble'] ?? ''));
      $direccionLabel = trim((string) ($contract['direccion'] ?? ''));
      if ($inmuebleLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Inmueble:</b> ' . EmailTemplate::e($inmuebleLabel) . '</p>';
      }
      if ($direccionLabel !== '') {
        $content .= '<p style="margin:4px 0;"><b>Direccion:</b> ' . EmailTemplate::e($direccionLabel) . '</p>';
      }
    }
    $content .= '<p style="margin:4px 0;"><b>Tipo de solicitud:</b> ' . EmailTemplate::e($tipoPqrs) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Departamento:</b> ' . EmailTemplate::e($departamento) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e($asunto) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Solicitante:</b> ' . EmailTemplate::e((string) $contact['name']) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Correo:</b> ' . EmailTemplate::e((string) $contact['email']) . '</p>';
    $content .= '<p style="margin:4px 0;"><b>Celular:</b> ' . EmailTemplate::e((string) $contact['phone']) . '</p>';
    if (is_array($client)) {
      $content .= '<p style="margin:4px 0;"><b>ID cliente:</b> ' . EmailTemplate::e((string) ($client['id_cliente'] ?? $client['_ID'] ?? '')) . '</p>';
    }
    $corresponsableIds = !empty($clienteOverride['enabled']) ? [] : self::getCorresponsableForType($tipoPqrs, $actor);
    $corresponsables = [];
    foreach ($corresponsableIds as $corresponsableId) {
      $corresponsableId = trim((string) $corresponsableId);
      if ($corresponsableId === '' || $corresponsableId === $responsableId) {
        continue;
      }
      $corresponsable = $this->findFuncionarioByEmployeeId($corresponsableId);
      if (empty($corresponsable)) {
        $corresponsable = [
          'id_empleado' => $corresponsableId,
          'nombre' => '',
          'correo' => '',
          'celular' => '',
        ];
      }
      $corresponsables[] = $corresponsable;
    }

    $themeResponsibleLabel = $responsableLabel;
    foreach ($corresponsables as $corresponsable) {
      $themeResponsibleId = trim((string) ($corresponsable['id_empleado'] ?? ''));
      $themeResponsibleName = trim((string) ($corresponsable['nombre'] ?? ''));
      if ($themeResponsibleName !== '') {
        $themeResponsibleLabel = $themeResponsibleName;
        break;
      }
      if ($themeResponsibleId !== '') {
        $themeResponsibleLabel = 'Funcionario #' . $themeResponsibleId;
        break;
      }
    }

    $staffEmailRecipients = [];
    $staffPhones = [];

    if ($responsableEmail !== '' && filter_var($responsableEmail, FILTER_VALIDATE_EMAIL)) {
      $staffEmailRecipients[] = [
        'email' => $responsableEmail,
        'name' => $responsableName,
        'id_empleado' => $responsableId,
        'access_scope' => 'assigned',
      ];
    }
    foreach ($corresponsables as $corresponsable) {
      if (!empty($corresponsable['correo'])) {
        $correspEmail = trim((string) $corresponsable['correo']);
        if ($correspEmail !== '' && filter_var($correspEmail, FILTER_VALIDATE_EMAIL)) {
          $staffEmailRecipients[] = [
            'email' => $correspEmail,
            'name' => trim((string) ($corresponsable['nombre'] ?? '')),
            'id_empleado' => trim((string) ($corresponsable['id_empleado'] ?? '')),
            'access_scope' => 'assigned',
          ];
        }
      }
    }

    if ($responsablePhone !== '') {
      $staffPhones[] = [
        'phone' => $responsablePhone,
        'name' => $responsableName,
        'id_empleado' => $responsableId,
        'access_scope' => 'assigned',
      ];
    }
    foreach ($corresponsables as $corresponsable) {
      if (!empty($corresponsable['celular'])) {
        $staffPhones[] = [
          'phone' => trim((string) $corresponsable['celular']),
          'name' => trim((string) ($corresponsable['nombre'] ?? '')),
          'id_empleado' => trim((string) ($corresponsable['id_empleado'] ?? '')),
          'access_scope' => 'assigned',
        ];
      }
    }

    // ── Funcionario configurado para notificaciones PQR ──────────────────
    $notifResponsableIds = [];
    if (empty($clienteOverride['enabled'])) {
      $notifResponsableIds = self::getNotifResponsables();
      foreach ($notifResponsableIds as $notifResponsableId) {
        if ($notifResponsableId === '') {
          continue;
        }

        $alreadyAdded = false;
        foreach ($staffPhones as &$sp) {
          if (trim((string) ($sp['id_empleado'] ?? '')) === $notifResponsableId) {
            $sp['access_scope'] = 'all';
            $alreadyAdded = true;
            break;
          }
        }
        unset($sp);
        if ($alreadyAdded) {
          // keep processing email below so dedupe can promote this recipient to global access.
        }

        $notifFunc = $this->findFuncionarioByEmployeeId($notifResponsableId);
        $notifPhone = trim((string) ($notifFunc['celular'] ?? ''));
        $notifName  = trim((string) ($notifFunc['nombre'] ?? ''));
        $notifEmail = trim((string) ($notifFunc['correo'] ?? ''));
        if (!$alreadyAdded && $notifPhone !== '') {
          $staffPhones[] = [
            'phone'       => $notifPhone,
            'name'        => $notifName,
            'id_empleado' => $notifResponsableId,
            'access_scope' => 'all',
          ];
        }
        if ($notifEmail !== '' && filter_var($notifEmail, FILTER_VALIDATE_EMAIL)) {
          $staffEmailRecipients[] = [
            'email' => $notifEmail,
            'name' => $notifName,
            'id_empleado' => $notifResponsableId,
            'access_scope' => 'all',
          ];
        }
      }
    }

    if (!empty($clienteOverride['enabled'])) {
      $staffEmailRecipients = [];
      $staffPhones = [];

      $overrideEmail = trim((string) ($clienteOverride['email'] ?? ''));
      $overridePhone = trim((string) ($clienteOverride['phone'] ?? ''));
      $overrideName = trim((string) ($clienteOverride['name'] ?? 'Monitoreo temporal cliente'));
      $overrideEmployeeId = trim((string) ($clienteOverride['id_empleado'] ?? ''));
      if ($overrideEmployeeId === '') {
        $overrideEmployeeId = $this->resolveNotificationEmployeeId($overrideEmail, $overridePhone, $overrideName);
      }

      if ($overrideEmail !== '' && filter_var($overrideEmail, FILTER_VALIDATE_EMAIL)) {
        $staffEmailRecipients[] = [
          'email' => $overrideEmail,
          'name' => $overrideName,
          'id_empleado' => $overrideEmployeeId,
          'access_scope' => 'all',
        ];
      }

      if ($overridePhone !== '') {
        $staffPhones[] = [
          'phone' => $overridePhone,
          'name' => $overrideName,
          'id_empleado' => $overrideEmployeeId,
          'access_scope' => 'all',
        ];
      }
    }

    $uniqueStaffEmails = [];
    foreach ($staffEmailRecipients as $recipient) {
      $mail = trim((string) ($recipient['email'] ?? ''));
      if ($mail === '') {
        continue;
      }
      $key = mb_strtolower($mail, 'UTF-8');
      if (!isset($uniqueStaffEmails[$key])) {
        $uniqueStaffEmails[$key] = [
          'email' => $mail,
          'name' => trim((string) ($recipient['name'] ?? '')),
          'id_empleado' => trim((string) ($recipient['id_empleado'] ?? '')),
          'access_scope' => $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned')),
        ];
      } elseif (
        $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned')) === 'all'
        && ($uniqueStaffEmails[$key]['access_scope'] ?? 'assigned') !== 'all'
      ) {
        $uniqueStaffEmails[$key]['access_scope'] = 'all';
        $uniqueStaffEmails[$key]['id_empleado'] = trim((string) ($recipient['id_empleado'] ?? ''));
      }
    }
    if (!empty($uniqueStaffEmails)) {
      foreach ($uniqueStaffEmails as $recipient) {
        $recipientName = trim((string) ($recipient['name'] ?? ''));
        $recipientLabel = $recipientName !== '' ? $recipientName : 'Destinatario de la notificacion';
        $recipientEmployeeId = trim((string) ($recipient['id_empleado'] ?? ''));
        $recipientScope = $this->normalizePublicPqrAccessScope((string) ($recipient['access_scope'] ?? 'assigned'));
        $urlEmployeeId = $recipientEmployeeId !== '' ? $recipientEmployeeId : $responsableId;
        $recipientPublicUrl = $urlEmployeeId !== ''
          ? $this->buildResponsiblePublicPqrUrl($urlEmployeeId, 2592000, $recipientScope)
          : '';
        $emailContent = $content;
        $emailContent .= '<p style="margin:4px 0;"><b>Responsable:</b> ' . EmailTemplate::e($themeResponsibleLabel) . '</p>';
        if ($recipientName !== '' && $recipientName !== $responsableLabel) {
          $emailContent .= '<p style="margin:4px 0;"><b>Recibe esta notificacion:</b> ' . EmailTemplate::e($recipientLabel) . '</p>';
        }
        $buttons = [];
        if ($recipientPublicUrl !== '') {
          $buttons[] = [
            'url' => $recipientPublicUrl,
            'label' => $recipientScope === 'all' ? 'Ver todas las solicitudes web' : 'Ver solicitudes web filtradas',
          ];
        }

        $html = EmailTemplate::render($subject, $emailContent, [
          'ticket_url' => $ticketUrl,
          'buttons' => $buttons,
        ]);

        $this->emailQueue->enqueue(
          (string) ($recipient['email'] ?? ''),
          $subject,
          $html,
          [
            'source' => 'public_pqr_portal',
            'destination_name' => trim((string) ($recipient['name'] ?? '')),
          ]
        );
        $emailQueued = true;
      }
    }

    $uniqueStaffPhones = [];
    foreach ($staffPhones as $dest) {
      $phoneRaw = trim((string) ($dest['phone'] ?? ''));
      if ($phoneRaw === '') {
        continue;
      }
      $phoneKey = $this->normalizePhoneForDedup($phoneRaw);
      if ($phoneKey === '') {
        continue;
      }
      if (!isset($uniqueStaffPhones[$phoneKey])) {
        $uniqueStaffPhones[$phoneKey] = $dest;
      } elseif (
        $this->normalizePublicPqrAccessScope((string) ($dest['access_scope'] ?? 'assigned')) === 'all'
        && $this->normalizePublicPqrAccessScope((string) ($uniqueStaffPhones[$phoneKey]['access_scope'] ?? 'assigned')) !== 'all'
      ) {
        $uniqueStaffPhones[$phoneKey] = $dest;
      }
    }

    foreach ($uniqueStaffPhones as $dest) {
      $phoneRaw = trim((string) ($dest['phone'] ?? ''));
      $destName = trim((string) ($dest['name'] ?? ''));
      $destLabel = $destName !== '' ? $destName : 'Destinatario de la notificacion';
      $destEmployeeId = trim((string) ($dest['id_empleado'] ?? ''));
      $destScope = $this->normalizePublicPqrAccessScope((string) ($dest['access_scope'] ?? 'assigned'));
      $urlEmployeeId = $destEmployeeId !== '' ? $destEmployeeId : $responsableId;
      $publicRequestsUrl = $urlEmployeeId !== ''
        ? $this->buildResponsiblePublicPqrUrl($urlEmployeeId, 2592000, $destScope)
        : '';
      $smsMessage = "📥 *Nueva solicitud web*\n";
      $smsMessage .= '*Solicitud:* #' . $logicalTicket . "\n";
      $smsMessage .= '*Tema:* ' . $tipoPqrs . "\n";
      if ($contractLabel !== '') {
        $smsMessage .= '*Contrato:* ' . $contractLabel . "\n";
      }
      $smsMessage .= '*Responsable:* ' . $themeResponsibleLabel . "\n";
      if ($destName !== '' && $destName !== $responsableLabel) {
        $smsMessage .= '*Notificado:* ' . $destLabel . "\n";
      }
      $smsMessage .= '*Solicitante:* ' . (string) $contact['name'] . "\n\n";
      $smsMessage .= "*Ver solicitud:*\n" . $ticketUrl;
      if ($publicRequestsUrl !== '') {
        $smsMessage .= "\n\n*" . ($destScope === 'all' ? 'Ver todas las solicitudes web:' : 'Ver solicitudes web filtradas:') . "*\n" . $publicRequestsUrl;
      }
      $queued = $this->smsQueue->enqueue($phoneRaw, $destName, $smsMessage, [
        'id_funcionario' => is_numeric((string) ($dest['id_empleado'] ?? '')) ? (int) $dest['id_empleado'] : 0,
        'id_actor' => is_numeric((string) $contact['actor_id']) ? (int) $contact['actor_id'] : 0,
        'tipo_actor' => strtolower($actorLabel),
        'categoria_mensaje' => 'informacion',
        'campaign_tag' => 'pqr_portal_publico',
        'dedupe_key' => 'pqr_nueva_asignada:' . $logicalTicket,
        'template_name' => 'pqr_nueva_asignada_v1',
        'template_language' => 'es_CO',
        'template_components' => [
          [
            'type' => 'body',
            'parameters' => [
              ['type' => 'text', 'text' => $destLabel],
              ['type' => 'text', 'text' => $logicalTicket],
              ['type' => 'text', 'text' => $tipoPqrs],
              ['type' => 'text', 'text' => $themeResponsibleLabel],
              ['type' => 'text', 'text' => (string) $contact['name']],
            ],
          ],
          [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
              ['type' => 'text', 'text' => $logicalTicket],
            ],
          ],
        ],
      ]);
      if ($queued) {
        $smsQueued = true;
      }
    }

    $confirmationSubject = 'Confirmacion PQR #' . $logicalTicket . ' recibido';
    $confirmationContent = '<p style="font-weight:600;margin:0 0 12px;">Hemos recibido tu PQR y ya fue asignado a gestion interna.</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>PQR:</b> #' . EmailTemplate::e($logicalTicket) . '</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>Tipo PQR/Peticion:</b> ' . EmailTemplate::e($tipoPqrs) . '</p>';
    $confirmationContent .= '<p style="margin:4px 0;"><b>Asunto:</b> ' . EmailTemplate::e($asunto) . '</p>';
    if ($contractLabel !== '') {
      $confirmationContent .= '<p style="margin:4px 0;"><b>Contrato:</b> ' . EmailTemplate::e($contractLabel) . '</p>';
    }
    $confirmationContent .= '<p style="margin:4px 0;"><b>Solicitante:</b> ' . EmailTemplate::e((string) $contact['name']) . '</p>';
    $confirmationHtml = EmailTemplate::render($confirmationSubject, $confirmationContent, ['ticket_url' => $ticketUrl]);

    if (empty($clienteOverride['enabled']) && $solicitanteEmail !== '' && filter_var($solicitanteEmail, FILTER_VALIDATE_EMAIL)) {
      $dedupKey = mb_strtolower($solicitanteEmail, 'UTF-8');
      if (!isset($uniqueStaffEmails[$dedupKey])) {
        $this->emailQueue->enqueue($solicitanteEmail, $confirmationSubject, $confirmationHtml, ['source' => 'public_pqr_portal']);
        $emailQueued = true;
      }
    }

    return [
      'email_queued' => $emailQueued,
      'sms_queued' => $smsQueued,
      'corresponsable_ids' => $corresponsableIds,
      'notif_responsable_ids' => $notifResponsableIds,
    ];
  }

  /**
   * @param array<string,string> $responsable
   * @param array<string,mixed>|null $contract
   */
  private function insertPropertyHistory(string $logicalTicket, string $asunto, array $responsable, ?array $contract): void
  {
    if (!is_array($contract)) {
      return;
    }

    $histTable = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($histTable)) {
      return;
    }

    $propertyId = trim((string) ($contract['id_inmueble'] ?? ''));
    $propertyDataId = trim((string) ($contract['id_inmueble_data'] ?? ''));
    if ($propertyId === '' && $propertyDataId === '') {
      return;
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $employeeId = trim((string) ($responsable['id_empleado'] ?? ''));
    $employeeName = trim((string) ($responsable['nombre'] ?? ''));

    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_empleado' => $employeeId,
      'id_inmueble' => $propertyId,
      'id_inmueble_data' => $propertyDataId,
      'id_ticket' => $logicalTicket,
      'fecha' => $nowTs,
      'tipo_reporte' => 'Ticket',
      'observacion' => $asunto,
      'funcionario' => $employeeName,
    ];

    $payload = $this->schema->filterTableData($histTable, $payload);
    if (empty($payload)) {
      return;
    }

    try {
      $this->db->insert($histTable, $payload);
    } catch (\Throwable $e) {
      // no-op
    }
  }

  /** @return array<int,string> */
  public static function getPqrThemes(): array
  {
    $hidden = [];
    foreach (self::INTERNAL_ONLY_PQR_THEMES as $theme) {
      $hidden[mb_strtolower(trim($theme), 'UTF-8')] = true;
    }

    $public = [];
    foreach (self::PQR_THEMES as $theme) {
      $key = mb_strtolower(trim($theme), 'UTF-8');
      if (!isset($hidden[$key])) {
        $public[] = $theme;
      }
    }
    return $public;
  }

  /** @return array<int,string> */
  public static function getPqrsTypes(): array
  {
    return self::getPqrThemes();
  }

  /** @return array<string,mixed> */
  public static function getCorresponsableAssignments(): array
  {
    $raw = get_option(self::OPTION_CORRESPONSABLES, []);
    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $raw = $decoded;
      }
    }
    if (!is_array($raw)) {
      return [];
    }

    $out = [];
    foreach ($raw as $type => $employeeValue) {
      $normalizedType = self::normalizePqrsTypeValue((string) $type);
      if ($normalizedType === '') {
        continue;
      }

      $assignment = self::normalizeCorresponsableAssignmentValue($employeeValue);
      if (!empty($assignment)) {
        $out[$normalizedType] = $assignment;
      }
    }
    return $out;
  }

  public static function saveCorresponsableAssignments(array $map): bool
  {
    $clean = [];
    foreach ($map as $type => $employeeValue) {
      $normalizedType = self::normalizePqrsTypeValue((string) $type);
      if ($normalizedType === '') {
        continue;
      }

      $assignment = self::normalizeCorresponsableAssignmentValue($employeeValue);
      if (!empty($assignment)) {
        $clean[$normalizedType] = $assignment;
      }
    }
    return update_option(self::OPTION_CORRESPONSABLES, $clean);
  }

  /** @return array<int,string> */
  public static function getCorresponsableForType(string $type, string $actor = ''): array
  {
    $normalizedType = self::normalizePqrsTypeValue($type);
    if ($normalizedType === '') {
      return [];
    }
    $map = self::getCorresponsableAssignments();
    $value = $map[$normalizedType] ?? [];

    $actor = mb_strtolower(trim($actor), 'UTF-8');
    if ($actor !== '' && is_array($value) && self::isAssociativeArray($value)) {
      $actorValue = $value[$actor] ?? ($value['default'] ?? []);
      if (is_array($actorValue)) {
        $value = $actorValue;
      } else {
        $value = [];
      }
    } elseif ($actor === '' && is_array($value) && self::isAssociativeArray($value)) {
      $value = $value['default'] ?? [];
    }

    if (!is_array($value)) {
      return [];
    }

    $out = [];
    foreach ($value as $idRaw) {
      $id = trim((string) $idRaw);
      if ($id !== '' && !in_array($id, $out, true)) {
        $out[] = $id;
      }
    }
    return $out;
  }

  /** @return array<mixed> */
  private static function normalizeCorresponsableAssignmentValue($employeeValue): array
  {
    if (!is_array($employeeValue)) {
      $single = trim((string) $employeeValue);
      return $single !== '' ? [$single] : [];
    }

    if (!self::isAssociativeArray($employeeValue)) {
      return self::normalizeCorresponsableIds($employeeValue);
    }

    $out = [];
    foreach (['default', 'propietario', 'arrendatario', 'copropiedad', 'cliente'] as $actor) {
      if (!array_key_exists($actor, $employeeValue)) {
        continue;
      }

      $ids = is_array($employeeValue[$actor])
        ? self::normalizeCorresponsableIds($employeeValue[$actor])
        : self::normalizeCorresponsableIds([$employeeValue[$actor]]);
      if ($ids !== []) {
        $out[$actor] = $ids;
      }
    }

    return $out;
  }

  /** @return array<int,string> */
  private static function normalizeCorresponsableIds(array $values): array
  {
    $ids = [];
    foreach ($values as $idRaw) {
      if (is_array($idRaw)) {
        continue;
      }

      $id = trim((string) $idRaw);
      if ($id !== '' && !in_array($id, $ids, true)) {
        $ids[] = $id;
      }
    }

    return $ids;
  }

  private static function isAssociativeArray(array $value): bool
  {
    if ($value === []) {
      return false;
    }

    return array_keys($value) !== range(0, count($value) - 1);
  }

  private function getDepartmentForActor(string $actor): string
  {
    if ($actor === 'propietario') {
      return 'Servicio al propietario';
    }
    if ($actor === 'arrendatario') {
      return 'Servicio al arrendatario';
    }
    if ($actor === 'copropiedad') {
      return 'Servicio a la copropiedad';
    }
    return 'Servicio al cliente';
  }

  public static function getDepartmentForTheme(string $theme): string
  {
    $key = mb_strtolower(trim($theme), 'UTF-8');
    return self::PQR_THEME_DEPARTMENT_MAP[$key] ?? 'Servicio al cliente';
  }

  public static function getNotifResponsable(): string
  {
    $ids = self::getNotifResponsables();
    return $ids[0] ?? '';
  }

  public static function saveNotifResponsable(string $empleadoId): bool
  {
    $empleadoId = trim($empleadoId);
    return self::saveNotifResponsables($empleadoId === '' ? [] : [$empleadoId]);
  }

  /** @return array<int,string> */
  public static function getNotifResponsables(): array
  {
    $raw = get_option(self::OPTION_NOTIF_RESPONSABLE, []);
    $ids = [];

    if (is_array($raw)) {
      foreach ($raw as $candidate) {
        $id = trim((string) $candidate);
        if ($id !== '' && !in_array($id, $ids, true)) {
          $ids[] = $id;
        }
      }
      return $ids;
    }

    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        foreach ($decoded as $candidate) {
          $id = trim((string) $candidate);
          if ($id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
          }
        }
        return $ids;
      }

      $single = trim($raw);
      if ($single !== '') {
        $ids[] = $single;
      }
    }

    return $ids;
  }

  /** @param array<int,string> $empleadoIds */
  public static function saveNotifResponsables(array $empleadoIds): bool
  {
    $clean = [];
    foreach ($empleadoIds as $candidate) {
      $id = trim((string) $candidate);
      if ($id !== '' && !in_array($id, $clean, true)) {
        $clean[] = $id;
      }
    }
    return update_option(self::OPTION_NOTIF_RESPONSABLE, $clean);
  }

  /** @return array<int,string> */
  private function getDistinctTicketValues(string $column, array $fallback): array
  {
    $ticketsTable = $this->db->table('jet_cct_tickets');
    if (!$this->schema->tableExists($ticketsTable) || !$this->schema->columnExists($ticketsTable, $column)) {
      return $fallback;
    }

    $sql = "SELECT TRIM(COALESCE(`{$column}`, '')) AS value
              FROM `{$ticketsTable}`
             WHERE TRIM(COALESCE(`{$column}`, '')) <> ''
             GROUP BY value
             ORDER BY COUNT(*) DESC
             LIMIT 60";
    $rows = $this->db->getCol($sql);
    $out = [];
    foreach ($rows as $value) {
      $text = trim((string) $value);
      if ($text !== '') {
        $out[] = $text;
      }
    }

    if (empty($out)) {
      return $fallback;
    }

    foreach ($fallback as $candidate) {
      if (!in_array($candidate, $out, true)) {
        $out[] = $candidate;
      }
    }

    return array_values(array_unique($out));
  }

  private function normalizeIndicativo(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '+57';
    }
    $clean = preg_replace('/[^0-9+]/', '', $value);
    if (!is_string($clean) || $clean === '') {
      return '+57';
    }
    if ($clean[0] !== '+') {
      $clean = '+' . ltrim($clean, '+');
    }
    return $clean;
  }

  private function normalizeAlphaNum(string $value): string
  {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^a-z0-9]/u', '', $value);
    return is_string($value) ? $value : '';
  }

  private function normalizeDigits(string $value): string
  {
    $value = preg_replace('/\D+/', '', $value);
    return is_string($value) ? $value : '';
  }

  private function normalizePqrTheme(string $value): string
  {
    return self::normalizePqrsTypeValue($value);
  }

  /** @return array{enabled:bool,email:string,phone:string,name:string,id_empleado:string} */
  private function resolveClienteNotificationsOverride(string $actor): array
  {
    $isCliente = mb_strtolower(trim($actor), 'UTF-8') === 'cliente';
    if (!$isCliente) {
      return ['enabled' => false, 'email' => '', 'phone' => '', 'name' => '', 'id_empleado' => ''];
    }

    $email = trim((string) ($this->clienteNotificationsOverride['email'] ?? ''));
    $phone = trim((string) ($this->clienteNotificationsOverride['whatsapp'] ?? $this->clienteNotificationsOverride['phone'] ?? ''));
    $name = trim((string) ($this->clienteNotificationsOverride['name'] ?? 'Monitoreo temporal cliente'));
    $employeeId = trim((string) ($this->clienteNotificationsOverride['id_empleado'] ?? ''));
    $enabled = !empty($this->clienteNotificationsOverride['enabled']) && ($email !== '' || $phone !== '' || $employeeId !== '');

    return [
      'enabled' => $enabled,
      'email' => $email,
      'phone' => $phone,
      'name' => $name,
      'id_empleado' => $employeeId,
    ];
  }

  private function normalizePublicPqrTheme(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $needle = mb_strtolower($value, 'UTF-8');
    foreach (self::getPqrThemes() as $theme) {
      if (mb_strtolower($theme, 'UTF-8') === $needle) {
        return $theme;
      }
    }
    return '';
  }

  private static function normalizePqrsTypeValue(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $needle = mb_strtolower($value, 'UTF-8');
    foreach (self::PQR_THEMES as $theme) {
      if (mb_strtolower($theme, 'UTF-8') === $needle) {
        return $theme;
      }
    }
    return '';
  }

  private function normalizePhoneForDedup(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $isPlus = isset($value[0]) && $value[0] === '+';
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits) || $digits === '') {
      return '';
    }

    if (strlen($digits) <= 10 && !$isPlus) {
      $digits = '57' . $digits;
    }

    return '+' . ltrim($digits, '+');
  }

  /** @return array<int,string> */
  private function buildPhoneTerms(string $value): array
  {
    $digits = $this->normalizeDigits($value);
    if ($digits === '') {
      return [];
    }

    $terms = [$digits];
    if (strlen($digits) > 10) {
      $terms[] = substr($digits, -10);
    }
    if (strlen($digits) > 7) {
      $terms[] = substr($digits, -7);
    }

    return array_values(array_unique(array_filter($terms, static fn($term) => is_string($term) && $term !== '')));
  }

  /**
   * @param array<string,mixed> $client
   * @param array<string,array<int,string>> $columns
   * @return array{0:array<int,string>,1:array<int,string>}
   */
  private function buildClientLookupConditions(string $table, array $client, array $columns): array
  {
    $where = [];
    $args = [];
    $physicalId = trim((string) ($client['_ID'] ?? ''));

    foreach ($columns as $column => $modes) {
      if (!$this->schema->columnExists($table, $column)) {
        continue;
      }

      foreach ($modes as $mode) {
        if ($mode === 'exact') {
          if ($physicalId !== '') {
            $where[] = "TRIM(COALESCE(`{$column}`, '')) = ?";
            $args[] = $physicalId;
          }
        }
      }
    }

    return [$where, $args];
  }

  public function buildResponsiblePublicPqrUrl(string $employeeId, int $ttlSeconds = 2592000, string $accessScope = 'assigned'): string
  {
    $employeeId = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeId));
    $accessScope = $this->normalizePublicPqrAccessScope($accessScope);
    if (!is_string($employeeId) || $employeeId === '') {
      return '';
    }

    $baseUrl = defined('SCM_BASE_URL') ? rtrim((string) SCM_BASE_URL, '/') : '';
    if ($baseUrl === '') {
      return '';
    }

    $ttlSeconds = max(3600, $ttlSeconds);
    $expiresAt = time() + $ttlSeconds;
    $token = $this->buildResponsiblePublicPqrAccessToken($employeeId, $expiresAt, $accessScope);
    if ($token === '') {
      return '';
    }

    // Use compact token URL to avoid truncation/rewrites in chat clients.
    return $baseUrl . '/solicitudes-web.php?k=' . rawurlencode($token);
  }

  /** @return array{id_empleado:string,scope:string,exp:string,sig:string,token:string}|null */
  public function resolveResponsiblePublicPqrAccessToken(string $token): ?array
  {
    $token = trim($token);
    if ($token === '' || preg_match('/^[A-Za-z0-9._-]+$/', $token) !== 1) {
      return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 4) {
      return null;
    }

    [$employeeId, $scopeCode, $expiresCompact, $signature] = $parts;
    $employeeId = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $employeeId));
    $scopeCode = trim((string) $scopeCode);
    $signature = trim((string) $signature);
    $accessScope = $scopeCode === 'g' ? 'all' : ($scopeCode === 'a' ? 'assigned' : '');
    $expiresAt = $this->decodeBase36Int($expiresCompact);

    if (!is_string($employeeId) || $employeeId === '' || $accessScope === '' || $signature === '' || $expiresAt <= time()) {
      return null;
    }

    $expected = $this->signResponsiblePublicPqrAccessToken($employeeId, $expiresAt, $accessScope);
    if (!hash_equals($expected, $signature)) {
      return null;
    }

    return [
      'id_empleado' => $employeeId,
      'scope' => $accessScope,
      'exp' => (string) $expiresAt,
      'sig' => $this->signResponsiblePublicPqrAccess($employeeId, $expiresAt, $accessScope),
      'token' => $token,
    ];
  }

  public function validateResponsiblePublicPqrAccess(string $employeeId, string $expiresAtRaw, string $signature, string $accessScope = 'assigned'): bool
  {
    $employeeId = preg_replace('/[^A-Za-z0-9_-]/', '', trim($employeeId));
    $signature = trim($signature);
    $accessScope = $this->normalizePublicPqrAccessScope($accessScope);
    $expiresAt = (int) trim($expiresAtRaw);

    if (!is_string($employeeId) || $employeeId === '' || $signature === '' || $expiresAt <= time()) {
      return false;
    }

    return hash_equals($this->signResponsiblePublicPqrAccess($employeeId, $expiresAt, $accessScope), $signature);
  }

  private function signResponsiblePublicPqrAccess(string $employeeId, int $expiresAt, string $accessScope): string
  {
    $secret = $this->appSecret !== '' ? $this->appSecret : 'scm_public_pqr_access';
    return hash_hmac('sha256', $employeeId . '|' . $expiresAt . '|' . $accessScope . '|public_pqr_access', $secret);
  }

  private function buildResponsiblePublicPqrAccessToken(string $employeeId, int $expiresAt, string $accessScope): string
  {
    $scopeCode = $accessScope === 'all' ? 'g' : 'a';
    $expiresCompact = strtolower(base_convert((string) $expiresAt, 10, 36));
    $signature = $this->signResponsiblePublicPqrAccessToken($employeeId, $expiresAt, $accessScope);

    return $employeeId . '.' . $scopeCode . '.' . $expiresCompact . '.' . $signature;
  }

  private function signResponsiblePublicPqrAccessToken(string $employeeId, int $expiresAt, string $accessScope): string
  {
    $binary = hash_hmac('sha256', $employeeId . '|' . $expiresAt . '|' . $accessScope . '|public_pqr_access_short', $this->appSecret !== '' ? $this->appSecret : 'scm_public_pqr_access', true);
    return rtrim(strtr(base64_encode(substr($binary, 0, 12)), '+/', '-_'), '=');
  }

  private function decodeBase36Int(string $value): int
  {
    $value = trim($value);
    if ($value === '' || preg_match('/^[0-9a-z]+$/i', $value) !== 1) {
      return 0;
    }

    $decoded = base_convert(strtolower($value), 36, 10);
    return is_string($decoded) ? (int) $decoded : 0;
  }

  private function normalizePublicPqrAccessScope(string $accessScope): string
  {
    $accessScope = mb_strtolower(trim($accessScope), 'UTF-8');
    return $accessScope === 'all' ? 'all' : 'assigned';
  }

  /** @param array<string,mixed> $config */
  private function getPqrCreatedByLabel(string $actor, array $config): string
  {
    $label = trim((string) ($config['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }

    return ucfirst($actor);
  }
}
