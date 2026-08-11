<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

trait RequesterLookupConcern
{
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
}
