<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait PublicPortalQueriesConcern
{
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
}
