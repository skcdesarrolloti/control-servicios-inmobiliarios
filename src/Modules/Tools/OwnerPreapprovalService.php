<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Modules\LeaseContracts\ContractCoOwners;
use SCM\Modules\OwnerCredits\OwnerCreditSettings;

/**
 * Herramientas › "Simulador de ingreso neto del propietario" (el mismo del panel comercial). Calcula lo que le queda al propietario cada mes (canon −
 * comisión − garantía − seguros, con las tarifas de SuCasa: OwnerNetIncomeSimulator) y, con la misma regla del
 * liquidador de créditos, el crédito preaprobado al que accede: ingreso neto × meses
 * de política. Los propietarios registrados cargan sus contratos Entregado o Por entregar; los demás se simulan a mano.
 * Aquí solo se consulta: no se registra ninguna solicitud.
 * El permiso lo revisa el panel (pestaña `herramienta_ingreso_neto`); la configuración de créditos es la de
 * Configuración › Créditos (OwnerCreditSettings).
 */
final class OwnerPreapprovalService
{
  private Database $db;
  private ?Settings $settings;
  /** @var array<string,array<int,string>> */
  private array $columns = [];

  public function __construct(Database $db, ?Settings $settings = null)
  {
    $this->db = $db;
    $this->settings = $settings;
  }

  /** Necesidades que el crédito preaprobado le ayuda a resolver al propietario (argumento comercial del simulador). */
  public const USES = [
    'servicios' => ['Servicios públicos', 'Ponerse al día con agua, luz y gas sin afectar su flujo de caja.'],
    'administracion' => ['Cuotas de administración', 'Pagar la administración pendiente y evitar intereses de mora.'],
    'reparaciones' => ['Reparaciones y mantenimiento', 'Arreglar el inmueble para arrendarlo más rápido y a mejor precio.'],
    'otros' => ['Libre inversión', 'Para lo que el propietario necesite: impuesto predial, mejoras u otros gastos.'],
  ];

  /** @var array<string,mixed>|null */
  private ?array $rates = null;

  /**
   * Tarifas del simulador (IVA, comisión, aseguradoras, Berkley, Seguros Mundial, préstamo) desde sus filas de
   * `jet_cct_confi_sistema`, las mismas que usa el plugin de la calculadora de comisión.
   * @return array<string,mixed>
   */
  public function rates(): array
  {
    if ($this->rates !== null) return $this->rates;
    $rows = [];
    try {
      $keys = OwnerNetIncomeSimulator::RATE_KEYS;
      foreach ($this->db->getResults('SELECT funcion, valor FROM `' . $this->db->table('jet_cct_confi_sistema') . '` WHERE funcion IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) as $row) {
        $rows[(string) $row['funcion']] = (string) $row['valor'];
      }
    } catch (\Throwable $error) {
      error_log('[tools-net-income-rates] ' . $error->getMessage());
    }
    return $this->rates = OwnerNetIncomeSimulator::rates($rows);
  }

  /**
   * Variables de la política que usa la herramienta, con los mismos valores por defecto del liquidador.
   * @return array{income_months:int,term_margin_months:int,no_mandate_deduction:float}
   */
  public function settings(): array
  {
    $stored = [];
    try {
      $stored = OwnerCreditSettings::read($this->settings ?? App::settings());
    } catch (\Throwable $error) {
      error_log('[tools-preapproval-settings] ' . $error->getMessage());
    }
    $int = static fn($value, int $default, int $min, int $max): int => is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    return [
      'income_months' => $int($stored['income_months'] ?? null, 3, 1, 24),
      'term_margin_months' => $int($stored['term_margin_months'] ?? null, 2, 0, 12),
      'no_mandate_deduction' => self::share($stored['no_mandate_deduction'] ?? null, 0.15),
    ];
  }

  /**
   * Quien firma el PDF: el funcionario de la sesión con su cargo, celular y correo de `jet_cct_funcionarios`.
   * @return array{name:string,cargo:string,phone:string,email:string}
   */
  public function signer(): array
  {
    $people = $this->db->table('jet_cct_funcionarios');
    $cargos = $this->db->table('jet_cct_cargos');
    $row = $this->db->getRow(
      "SELECT f.`nombre`, f.`celular`, f.`correo`, c.`nombre_cargo` FROM `{$people}` f LEFT JOIN `{$cargos}` c ON c.`_ID` = f.`id_cargo` WHERE f.`_ID` = ? LIMIT 1",
      [Auth::userId()]
    );
    $row = is_array($row) ? $row : [];
    $value = static fn(string $key): string => trim((string) ($row[$key] ?? ''));
    return ['name' => $value('nombre') !== '' ? $value('nombre') : trim(Auth::user()), 'cargo' => $value('nombre_cargo'), 'phone' => $value('celular'), 'email' => $value('correo')];
  }

  /**
   * Contratos Entregado o Por entregar donde el documento (cédula o NIT) es de un propietario, con el ingreso que
   * toma el liquidador: canon (o el de la última carta de aumento) y descuentos del mandato. La póliza de servicios
   * públicos cubre todo el contrato y se paga una sola vez en `pago_servicios` cuotas desde el inicio del contrato
   * (`precio_mes_servicios` es el valor de cada cuota): `servicios` dice si ya se pagó.
   * @return array{owner:array{name:string,document:string,email:string,phone:string}|null,contracts:list<array<string,mixed>>,active_credits:int}
   */
  public function lookup(string $document): array
  {
    $document = self::document($document);
    if (preg_match('/^[0-9A-Z-]{4,20}$/', $document) !== 1) throw new \InvalidArgumentException('Escribe el documento o NIT del propietario (mínimo 4 caracteres).');
    $clean = static fn(string $column): string => "UPPER(REPLACE(REPLACE({$column}, '.', ''), ' ', '')) = ?";
    $contracts = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = [];
    $args = [];
    if ($this->hasColumn($contracts, 'documento_propietario')) {
      $where[] = $clean('`documento_propietario`');
      $args[] = $document;
    }
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $owners = $this->db->table('jet_cct_propietarios');
    foreach ([['id_contrato_mandato', $mandates, ['documento_1', 'nit_1', 'documento_2', 'nit_2', 'documento_3', 'nit_3', 'documento_4', 'nit_4', 'documento_5', 'nit_5', 'documento_6', 'nit_6'], ['_ID']], ['id_propietario', $owners, ['documento', 'documento_juridico'], ['_ID', 'id_propietario']]] as [$link, $source, $documentColumns, $idColumns]) {
      if (!$this->hasColumn($contracts, $link)) continue;
      $documentColumns = array_values(array_filter($documentColumns, fn(string $column): bool => $this->hasColumn($source, $column)));
      $idColumns = array_values(array_filter($idColumns, fn(string $column): bool => $this->hasColumn($source, $column)));
      if ($documentColumns === [] || $idColumns === []) continue;
      $ids = [];
      $rows = $this->db->getResults('SELECT `' . implode('`, `', $idColumns) . "` FROM `{$source}` WHERE " . implode(' OR ', array_map(static fn(string $column): string => $clean("`{$column}`"), $documentColumns)) . ' LIMIT 100', array_fill(0, count($documentColumns), $document));
      foreach ($rows as $row) foreach ($idColumns as $column) if (trim((string) ($row[$column] ?? '')) !== '') $ids[trim((string) $row[$column])] = true;
      if ($ids === []) continue;
      $where[] = "`{$link}` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
      array_push($args, ...array_map('strval', array_keys($ids)));
    }
    $result = ['owner' => null, 'contracts' => [], 'active_credits' => 0];
    $ownerMatched = false;
    if ($where === []) return $result;
    $noMandate = $this->settings()['no_mandate_deduction'];
    // Solo contratos vigentes (Entregado) o por iniciar. Los Por entregar aún no tienen ingreso real: llegan sin canon
    // ni datos del mandato (`por_entregar`) para que el consultor escriba canon y administración y los simule como un
    // inmueble nuevo.
    foreach ($this->db->getResults("SELECT * FROM `{$contracts}` WHERE (" . implode(' OR ', $where) . ") AND LOWER(TRIM(`estado`)) IN ('entregado', 'por entregar') ORDER BY (LOWER(TRIM(`estado`)) = 'entregado') DESC, `_ID` DESC LIMIT 30", $args) as $contract) {
      $candidates = $this->contractOwners($contract);
      $owner = null;
      foreach ($candidates as $candidate) if (self::document($candidate['document']) === $document) $owner = $candidate;
      $matched = $owner !== null;
      // El documento estaba en el contrato o en la ficha del propietario: es el propietario principal.
      if ($owner === null) $owner = $candidates[0] ?? null;
      if ($owner === null) continue;
      if ($owner['document'] === '') $owner['document'] = $document;
      // Se prefieren los datos donde el documento coincide (mandato); los demás contratos completan lo que falte.
      if ($result['owner'] === null || ($matched && !$ownerMatched)) {
        $previous = $result['owner'] ?? [];
        $result['owner'] = $owner;
        $ownerMatched = $matched;
        $owner = $previous;
      }
      foreach (['email', 'phone'] as $key) if ($result['owner'][$key] === '' && ($owner[$key] ?? '') !== '') $result['owner'][$key] = $owner[$key];
      $end = self::timestamp($contract['fin_contrato'] ?? '');
      $pending = mb_strtolower(trim((string) ($contract['estado'] ?? '')), 'UTF-8') === 'por entregar';
      $income = $pending
        ? ['canon' => 0.0, 'administracion' => 0.0, 'comision' => 0.0, 'garantia' => 0.0, 'seguro' => 0.0, 'intereses' => 0.0, 'otros' => 0.0, 'servicios' => null]
        : $this->income($contract, $noMandate, time());
      $result['contracts'][] = [
        'id' => (int) ($contract['_ID'] ?? 0), 'contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? ''),
        'direccion' => (string) ($contract['direccion'] ?? ''), 'estado' => (string) ($contract['estado'] ?? ''),
        // Meses de contrato que quedan: desde hoy o, si aún no empieza (Por entregar), desde su inicio.
        'fin' => $end > 0 ? date('Y-m-d', $end) : '', 'max_months' => self::monthsUntil(max(time(), self::timestamp($contract['inicio_contrato'] ?? '')), $end),
        'por_entregar' => $pending,
      ] + $income;
    }
    $result['active_credits'] = $this->activeCredits($document);
    return $result;
  }

  /** Aviso del inmueble sin póliza de servicios públicos. */
  public const NO_SERVICES_POLICY = 'Sin póliza de servicios públicos: este contrato no cumple las condiciones para la aprobación del crédito.';
  /** Condiciones para aprobar el crédito; se enumeran en el PDF. */
  public const CONDITIONS = [
    'Queda sujeto a estudio, verificación y aprobación.',
    'El crédito se otorga solamente si el contrato queda perfeccionado, es decir, con la ocupación del inmueble.',
    'El contrato debe contar con la póliza de servicios públicos.',
    'La duración del contrato debe ser mínimo de 12 meses.',
    'El contrato debe contar con garantía inmobiliaria (asegurado).',
  ];
  /** Duración mínima del contrato (meses) que exige el crédito. */
  public const MIN_CONTRACT_MONTHS = 12;

  /**
   * Simulación para el PDF, recalculada aquí con los valores que escribió o ajustó el consultor (no se confía en los
   * totales del navegador). Cada inmueble se simula con las tarifas de SuCasa (`calc` = "sim") o trae los descuentos del
   * contrato. Ingreso neto = canon − comisión − garantía − (seguro + intereses) / 12 − otros; crédito preaprobado = suma ×
   * meses de política. Los contratos de un propietario registrado (`contrato`) descuentan como mínimo `no_mandate_deduction`
   * (15 %) del canon: si la simulación da menos (p. ej. sin mandato), la diferencia va como ajuste. Plazo: duración en meses
   * del contrato más largo − margen. Las necesidades del propietario solo
   * salen si respondió que tiene algo por resolver (`needs`).
   * @param array<string,mixed> $input owner{name,document,phone,email}, registered, sources[{calc,canon,…,otros,duracion,meta}], needs, uses{servicios,…}, notes
   * @return array<string,mixed>
   */
  public function estimate(array $input): array
  {
    $settings = $this->settings();
    $text = static fn($value, int $limit): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), 0, $limit);
    $owner = is_array($input['owner'] ?? null) ? $input['owner'] : [];
    $owner = ['name' => $text($owner['name'] ?? '', 120), 'document' => $text($owner['document'] ?? '', 24), 'phone' => $text($owner['phone'] ?? '', 20), 'email' => $text($owner['email'] ?? '', 120)];
    foreach (['name' => 'el nombre', 'document' => 'la cédula o NIT', 'phone' => 'el celular', 'email' => 'el correo'] as $key => $label) {
      if ($owner[$key] === '') throw new \InvalidArgumentException('Escribe ' . $label . ' del propietario: es obligatorio y aparece en el PDF.');
    }
    if (filter_var($owner['email'], FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('El correo del propietario no es válido.');
    $sources = [];
    foreach (array_slice(is_array($input['sources'] ?? null) ? $input['sources'] : [], 0, 30) as $source) {
      if (!is_array($source)) continue;
      $item = ['sim' => null];
      foreach (['canon', 'comision', 'garantia', 'seguro', 'intereses', 'otros'] as $key) $item[$key] = min(9999999999.0, round(self::money($source[$key] ?? 0)));
      if (($source['calc'] ?? '') === 'sim') {
        $sim = OwnerNetIncomeSimulator::simulate($source, $this->rates());
        foreach (['canon', 'comision', 'garantia', 'seguro', 'intereses'] as $key) $item[$key] = $sim[$key];
        $item['sim'] = $sim;
      }
      // Póliza de servicios públicos (condición del crédito): 'si' si se descuenta, 'pagada' si la del mandato ya se pagó,
      // 'no' si el inmueble no la tiene.
      $services = !empty($source['servicios']) && $source['servicios'] !== 'No';
      $item['poliza'] = $services ? 'si' : (!empty($source['poliza_pagada']) ? 'pagada' : 'no');
      $item['duracion'] = is_numeric($source['duracion'] ?? null) ? max(0, min(240, (int) $source['duracion'])) : 0;
      $item['meta'] = $text($source['meta'] ?? '', 160);
      $item['seguro_mes'] = ($item['seguro'] + $item['intereses']) / 12;
      $deductions = $item['comision'] + $item['garantia'] + $item['seguro_mes'] + $item['otros'];
      $minimum = !empty($source['contrato']) ? round($item['canon'] * $settings['no_mandate_deduction'], 2) : 0.0;
      $item['ajuste'] = max(0.0, round($minimum - $deductions, 2));
      $item['net'] = max(0.0, $item['canon'] - $deductions - $item['ajuste']);
      $sources[] = $item;
    }
    if ($sources === []) throw new \InvalidArgumentException('Agrega al menos un inmueble con su canon.');
    $net = array_sum(array_column($sources, 'net'));
    if ($net <= 0) throw new \InvalidArgumentException('El ingreso neto es $0: revisa el canon y los descuentos.');
    $longest = max(array_column($sources, 'duracion'));
    $needs = !empty($input['needs']);
    $uses = [];
    $requested = $needs && is_array($input['uses'] ?? null) ? $input['uses'] : [];
    foreach (array_keys(self::USES) as $key) {
      $value = min(9999999999.0, round(self::money($requested[$key] ?? 0)));
      if ($value > 0) $uses[$key] = $value;
    }
    return [
      'owner' => $owner, 'registered' => !empty($input['registered']), 'sources' => $sources,
      'income_months' => $settings['income_months'], 'term_margin_months' => $settings['term_margin_months'],
      'gross' => array_sum(array_column($sources, 'canon')), 'net' => $net, 'amount' => $net * $settings['income_months'], 'longest_months' => $longest, 'min_deduction' => $settings['no_mandate_deduction'],
      'term' => $longest > 0 ? max(0, $longest - $settings['term_margin_months']) : null,
      'needs' => $needs, 'uses' => $uses, 'notes' => mb_substr(trim(str_replace("\r", '', (string) ($input['notes'] ?? ''))), 0, 600),
    ];
  }

  /**
   * Canon y descuentos mensuales del contrato; sin mandato con comisión, el porcentaje configurado del canon.
   * `seguro` es la prima de la póliza de servicios aún por pagar (0 si ya se pagó).
   * @return array{canon:float,administracion:float,comision:float,garantia:float,seguro:float,intereses:float,otros:float,servicios:array{estado:string,cuotas:int,pagadas:int,valor:float,prima:float,inicio:string}|null}
   */
  private function income(array $contract, float $noMandate, int $today): array
  {
    $canon = self::money($contract['valor_canon'] ?? '');
    $letterId = (int) ($contract['id_carta_aumento_canon'] ?? 0);
    $letters = $this->db->table('jet_cct_cartas_aumento');
    if ($letterId > 0 && $this->hasColumn($letters, 'canon')) $canon = max($canon, self::money($this->db->getVar("SELECT `canon` FROM `{$letters}` WHERE `_ID` = ? LIMIT 1", [$letterId]) ?? ''));
    $income = ['canon' => $canon, 'administracion' => 0.0, 'comision' => 0.0, 'garantia' => 0.0, 'seguro' => 0.0, 'intereses' => 0.0, 'otros' => 0.0, 'servicios' => null];
    $mandate = $this->mandate($contract);
    if (is_array($mandate) && self::money($mandate['total_comision'] ?? '') > 0) {
      $income['comision'] = self::money($mandate['total_comision'] ?? '');
      $income['administracion'] = self::money($mandate['administracion'] ?? '');
      $income['garantia'] = self::isYes($mandate['incluye_garantia'] ?? '') ? self::money($mandate['precio_mes_garantia'] ?? '') : 0.0;
      if (self::isYes($mandate['incluye_garantia_servicios'] ?? '')) {
        $income['servicios'] = self::servicesPolicy($mandate, self::timestamp($contract['inicio_contrato'] ?? ''), $today);
        $income['seguro'] = $income['servicios']['estado'] === 'pagada' ? 0.0 : $income['servicios']['prima'];
      }
      return $income;
    }
    $income['otros'] = round($canon * $noMandate);
    return $income;
  }

  /**
   * Póliza de servicios públicos del mandato: se paga una sola vez para todo el contrato, en `pago_servicios` cuotas
   * mensuales desde el inicio del contrato. Si ya pasaron esos meses, está pagada; sin fecha de inicio no se puede saber.
   * @param array<string,mixed> $mandate
   * @return array{estado:string,cuotas:int,pagadas:int,valor:float,prima:float,inicio:string}
   */
  private static function servicesPolicy(array $mandate, int $start, int $today): array
  {
    $installments = max(1, min(12, (int) self::money($mandate['pago_servicios'] ?? '')));
    $premium = self::money($mandate['precio_anual_servicios'] ?? '');
    if ($premium <= 0) $premium = self::money($mandate['precio_mes_servicios'] ?? '') * $installments;
    $paid = $start > 0 ? min($installments, self::monthsUntil($start, $today)) : 0;
    return [
      'estado' => $start <= 0 ? 'sin_inicio' : ($paid >= $installments ? 'pagada' : 'pendiente'),
      'cuotas' => $installments, 'pagadas' => $paid, 'valor' => self::money($mandate['precio_garantia_sevicios'] ?? ''),
      'prima' => round($premium, 2), 'inicio' => $start > 0 ? date('Y-m-d', $start) : '',
    ];
  }

  /** Créditos aprobados (vigentes) de la persona en el liquidador: el analista descuenta su saldo del cupo. */
  private function activeCredits(string $document): int
  {
    $table = $this->db->table('scm_owner_credits');
    if (!$this->hasColumn($table, 'owner_document')) return 0;
    return (int) $this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE UPPER(REPLACE(REPLACE(`owner_document`, '.', ''), ' ', '')) = ? AND `status` = 'aprobado'", [$document]);
  }

  /** @return array<string,mixed>|null */
  private function mandate(array $contract): ?array
  {
    $mandates = $this->db->table('jet_cct_contrato_mandato');
    $mandateId = (int) ($contract['id_contrato_mandato'] ?? 0);
    $row = $mandateId > 0 && $this->columnsOf($mandates) !== [] ? $this->db->getRow("SELECT * FROM `{$mandates}` WHERE `_ID` = ? LIMIT 1", [$mandateId]) : null;
    return is_array($row) ? $row : null;
  }

  /**
   * Propietarios del contrato de mandato (1 a 6, con la misma lectura que el resto del panel: ContractCoOwners) o, sin
   * mandato, el propietario del contrato de arrendamiento.
   * @return list<array{name:string,document:string,email:string,phone:string}>
   */
  private function contractOwners(array $contract): array
  {
    $owners = [];
    $mandate = $this->mandate($contract);
    if (is_array($mandate)) {
      foreach (ContractCoOwners::mandateOwners($mandate) as $owner) {
        if ($owner['name'] === '') continue;
        $owners[] = ['name' => $owner['name'], 'document' => $owner['document'], 'email' => $owner['email'], 'phone' => self::phone($owner['phone'], $owner['indicativo'])];
      }
    }
    if ($owners === []) {
      $owners[] = [
        'name' => trim((string) ($contract['propietario'] ?? '')), 'document' => '',
        'email' => trim((string) ($contract['correo_propietario'] ?? '')),
        'phone' => self::phone((string) ($contract['celular_propietario'] ?? ''), (string) ($contract['indicativo_propietario'] ?? '')),
      ];
    }
    return $owners;
  }

  /** Columnas de una tabla (vacío si no existe); funciona en MySQL y en la base SQLite de las pruebas. @return array<int,string> */
  private function columnsOf(string $table): array
  {
    if (isset($this->columns[$table])) return $this->columns[$table];
    $columns = [];
    try {
      $statement = $this->db->pdo()->query("SELECT * FROM `{$table}` LIMIT 0");
      if ($statement !== false) {
        for ($i = 0; $i < $statement->columnCount(); $i++) {
          $meta = $statement->getColumnMeta($i);
          if ($meta !== false) $columns[] = $meta['name'];
        }
      }
    } catch (\PDOException $error) {
      $columns = [];
    }
    return $this->columns[$table] = $columns;
  }

  private function hasColumn(string $table, string $column): bool
  {
    return in_array($column, $this->columnsOf($table), true);
  }

  /** Meses (de 30 días) entre hoy y el fin del contrato, con la misma regla del liquidador. */
  private static function monthsUntil(int $from, int $to): int
  {
    if ($from <= 0 || $to <= 0) return 0;
    $days = (int) floor(((int) strtotime(date('Y-m-d', $to)) - (int) strtotime(date('Y-m-d', $from))) / 86400);
    return max(0, (int) floor($days / 30));
  }

  /** Porción de 0 a 1: se acepta 15, "15 %" o 0,15. */
  private static function share($value, float $default): float
  {
    $text = str_replace(',', '.', trim((string) $value));
    if ($text === '' || !is_numeric(rtrim($text, '%'))) return $default;
    $number = (float) rtrim($text, '%');
    if (str_ends_with($text, '%') || $number >= 1) $number /= 100;
    return max(0.0, min(1.0, $number));
  }

  private static function document(string $value): string
  {
    return strtoupper(preg_replace('/[\s.]+/', '', trim($value)) ?? '');
  }

  /** Celular con indicativo en dígitos (+57 314 5106244 → 573145106244). */
  private static function phone(string $phone, string $prefix): string
  {
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $prefixDigits = preg_replace('/\D/', '', $prefix) ?? '';
    if ($digits === '') return '';
    if (str_starts_with(trim($phone), '+') || $prefixDigits === '' || str_starts_with($digits, $prefixDigits)) return $digits;
    return $prefixDigits . $digits;
  }

  public static function money($value): float
  {
    if (is_int($value) || is_float($value)) return max(0.0, (float) $value);
    $text = preg_replace('/[^\d,.\-]/', '', trim((string) $value)) ?? '';
    if ($text === '') return 0.0;
    if (str_contains($text, ',') && str_contains($text, '.')) {
      $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
    } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $text) === 1) {
      $text = str_replace('.', '', $text);
    } elseif (str_contains($text, ',')) {
      $text = preg_match('/^\d{1,3}(?:,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    }
    return is_numeric($text) ? max(0.0, (float) $text) : 0.0;
  }

  private static function timestamp($value): int
  {
    $text = trim((string) $value);
    if ($text === '') return 0;
    if (ctype_digit($text)) {
      $number = (int) $text;
      return $number > 9999999999 ? intdiv($number, 1000) : $number;
    }
    return (int) (strtotime($text) ?: 0);
  }

  private static function isYes($value): bool
  {
    return in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['si', 'sí', '1', 'yes'], true);
  }
}
