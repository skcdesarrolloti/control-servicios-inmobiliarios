<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

/**
 * Arrendatario de la hoja de cierre: buscarlo y registrarlo desde hoja-cierre.php, sin la página intermedia de
 * WordPress (inmueble-de-administracion) ni el formulario JetForm "Añadir arrendatario" (hook registro-arrendatario).
 * Como el hook: si el documento ya existe se actualiza esa ficha; si no, se crea y se le envía la bienvenida.
 */
final class ClosingSheetTenants
{
  use ClosingSheetRecords;

  /** Glosarios del formulario JetForm "Añadir arrendatario". */
  private const GLOSSARIES = ['tipo_persona' => 272, 'tipo_documento' => 1, 'sexo' => 1047];
  private const TIMEZONE = 'America/Bogota';
  private const PORTAL_URL = 'https://sucasainmobiliaria.com.co/arrendatario';

  private SchemaInspector $schema;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
  }

  /**
   * Listas del formulario: tipo de persona, documento y sexo (glosarios), países con su indicativo, departamentos y
   * ciudades con su departamento (para filtrar la lista de ciudades).
   * @return array{tipo_persona:list<string>,tipo_documento:list<string>,sexo:list<string>,paises:list<array{pais:string,codigo:string}>,departamentos:list<string>,ciudades:list<array{ciudad:string,departamento:string}>}
   */
  public function options(): array
  {
    $glossaries = [];
    foreach (self::GLOSSARIES as $key => $id) {
      $glossaries[$key] = $this->glossary($id);
    }
    $rows = function (string $name, string $columns, string $order): array {
      $table = $this->db->table($name);
      return $this->schema->tableExists($table) ? $this->db->getResults("SELECT {$columns} FROM `{$table}` ORDER BY {$order}") : [];
    };
    $countries = [];
    foreach ($rows('jet_cct_paises', '`pais`, `codigo`', '`pais` ASC') as $row) {
      $country = trim((string) $row['pais']);
      $code = trim((string) $row['codigo']);
      if ($country !== '' && $code !== '') {
        $countries[] = ['pais' => $country, 'codigo' => $code];
      }
    }
    $cities = [];
    foreach ($rows('jet_cct_ciudades', '`ciudad`, `departamento`', '`ciudad` ASC') as $row) {
      if (trim((string) $row['ciudad']) !== '') {
        $cities[] = ['ciudad' => trim((string) $row['ciudad']), 'departamento' => trim((string) $row['departamento'])];
      }
    }
    return $glossaries + [
      'paises' => $countries,
      'departamentos' => array_values(array_unique(array_filter(array_map(static fn(array $row): string => trim((string) $row['departamento']), $rows('jet_cct_departamentos', '`departamento`', '`departamento` ASC'))))),
      'ciudades' => $cities,
    ];
  }

  /**
   * Busca por nombre, documento o celular (mínimo 2 caracteres).
   * @return list<array<string,string>>
   */
  public function search(string $query, int $limit = 15): array
  {
    $query = trim($query);
    $table = $this->db->table('jet_cct_arrendatarios');
    if (mb_strlen($query, 'UTF-8') < 2 || !$this->schema->tableExists($table)) {
      return [];
    }
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $digits = preg_replace('/\D+/', '', $query) ?? '';
    $where = '`nombre` LIKE ?';
    $params = [$like];
    if (strlen($digits) >= 3) {
      $where .= " OR REPLACE(REPLACE(REPLACE(`documento`, '.', ''), ' ', ''), '-', '') LIKE ? OR `celular` LIKE ?";
      array_push($params, '%' . $digits . '%', '%' . $digits . '%');
    }
    $rows = $this->db->getResults("SELECT * FROM `{$table}` WHERE COALESCE(`cct_status`, 'publish') = 'publish' AND ({$where}) ORDER BY `nombre` ASC LIMIT " . max(1, min(30, $limit)), $params);
    return array_map(fn(array $row): array => self::card($row), $rows);
  }

  /** @return array<string,string>|null */
  public function find(int $id): ?array
  {
    $row = $this->row('jet_cct_arrendatarios', $id);
    return $row !== [] ? self::card($row) : null;
  }

  /** _ID del arrendatario con ese documento (sin puntos, espacios ni guiones), o 0. */
  public function idByDocument(string $document): int
  {
    $digits = self::documentKey($document);
    $table = $this->db->table('jet_cct_arrendatarios');
    if ($digits === '' || !$this->schema->tableExists($table)) {
      return 0;
    }
    return (int) ($this->db->getVar("SELECT `_ID` FROM `{$table}` WHERE `documento` = ? OR UPPER(REPLACE(REPLACE(REPLACE(`documento`, '.', ''), ' ', ''), '-', '')) = ? ORDER BY `_ID` ASC LIMIT 1", [trim($document), $digits]) ?? 0);
  }

  /**
   * Registra o actualiza (si el documento ya existe) y devuelve la ficha para llenar la hoja.
   * @param array<string,string> $input @param array{employee_id:string,name:string,email:string,branch:string} $sender
   * @return array{tenant:array<string,string>,created:bool,notified:bool}
   */
  public function save(array $input, array $sender): array
  {
    $data = $this->validate($input);
    $nowSql = date('Y-m-d H:i:s');
    $existing = $this->idByDocument($data['documento']);
    if ($existing > 0) {
      $this->update('jet_cct_arrendatarios', $existing, $data + ['id_arrendatario' => (string) $existing, 'cct_modified' => $nowSql]);
      return ['tenant' => (array) $this->find($existing), 'created' => false, 'notified' => false];
    }
    $id = $this->insert('jet_cct_arrendatarios', $sender, $nowSql, $data + ['sucursal' => $sender['branch']]);
    $this->update('jet_cct_arrendatarios', $id, ['id_arrendatario' => (string) $id]);
    $notified = false;
    try {
      $notified = $this->welcome($id, $data);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_tenant] bienvenida #' . $id . ': ' . $error->getMessage());
    }
    return ['tenant' => (array) $this->find($id), 'created' => true, 'notified' => $notified];
  }

  /** @param array<string,string> $input @return array<string,string> columnas de wp_jet_cct_arrendatarios */
  private function validate(array $input): array
  {
    $options = $this->options();
    $v = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    $errors = [];
    $choice = static function (string $value, array $allowed, string $label) use (&$errors): string {
      if ($value === '' || !in_array($value, $allowed, true)) {
        $errors[] = $label;
      }
      return $value;
    };
    $type = $choice($v('tipo_persona'), $options['tipo_persona'] ?: ['Natural', 'Juridica'], 'Tipo de persona');
    $documentType = $choice($v('tipo_documento'), $options['tipo_documento'] ?: ['Cédula de Ciudadania', 'Nit', 'Cédula Extranjería', 'Pasaporte'], 'Tipo de documento');
    $sex = $choice($v('sexo'), $options['sexo'] ?: ['Masculino', 'Femenino'], 'Sexo');
    $country = '';
    foreach ($options['paises'] as $row) {
      if ($row['codigo'] === $v('indicativo') && ($v('pais') === '' || $row['pais'] === $v('pais'))) {
        $country = $row['pais'];
        break;
      }
    }
    if ($country === '') {
      $errors[] = 'País';
    }
    foreach (['nombre' => 'Nombre', 'documento' => 'Documento', 'direccion' => 'Dirección', 'departamento' => 'Departamento', 'ciudad' => 'Ciudad'] as $key => $label) {
      if ($v($key) === '') {
        $errors[] = $label;
      }
    }
    $company = $type === 'Juridica';
    if ($company && ($v('representante_legal') === '' || $v('documento_legal') === '')) {
      $errors[] = 'Representante legal y su documento';
    }
    $phone = preg_replace('/\D+/', '', $v('celular')) ?? '';
    if (strlen($phone) < 7 || strlen($phone) > 15) {
      $errors[] = 'Celular (solo números)';
    }
    if (!filter_var($v('correo'), FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Correo válido';
    }
    $birthday = \DateTimeImmutable::createFromFormat('!Y-m-d', $v('cumpleanos'), new \DateTimeZone(self::TIMEZONE));
    if ($birthday === false || $birthday->format('Y-m-d') !== $v('cumpleanos') || $birthday > new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE))) {
      $errors[] = 'Cumpleaños (fecha pasada)';
    }
    if ($errors !== []) {
      throw new \DomainException('Revisa estos datos del arrendatario: ' . implode(', ', $errors) . '.');
    }
    return [
      'tipo_persona' => $type, 'nombre' => $v('nombre'), 'tipo_documento' => $documentType, 'documento' => $v('documento'),
      'expedicion' => $v('expedicion'),
      // Como el hook de WordPress: en persona natural el representante queda "No aplica".
      'nombre_juridico' => $company ? $v('representante_legal') : 'No aplica',
      'documento_juridico' => $company ? $v('documento_legal') : 'No aplica',
      'indicativo' => $v('indicativo'), 'celular' => $phone, 'correo' => $v('correo'), 'direccion' => $v('direccion'),
      'pais' => $country, 'departamento' => $v('departamento'), 'ciudad' => $v('ciudad'),
      // Fecha a medianoche de Colombia, como la guarda el campo de fecha de JetForm.
      'cumpleanos' => (string) $birthday->getTimestamp(), 'sexo' => $sex,
    ];
  }

  /** Bienvenida al arrendatario nuevo, como el hook de WordPress, por la cola compartida. @param array<string,string> $data */
  private function welcome(int $id, array $data): bool
  {
    $subject = 'Te damos la bienvenida, ' . $data['nombre'];
    $p = static fn(string $html): string => '<p style="margin:0 0 14px;line-height:1.65;">' . $html . '</p>';
    $html = EmailTemplate::render($subject,
      $p('Apreciado(a) <b>' . EmailTemplate::e($data['nombre']) . '</b>,')
      . $p('Le damos una cordial bienvenida a <b>SKC SuCasa Inmobiliaria</b>. Nos complace informarle que quedó registrado(a) como <b>arrendatario</b> en nuestra plataforma.')
      . $p('Para entrar a su cuenta use como <b>usuario</b> y <b>contraseña</b> su número de documento: <b>' . EmailTemplate::e($data['documento']) . '</b>.'),
      ['buttons' => [['label' => 'Entrar a mi cuenta', 'url' => self::PORTAL_URL]]]);
    return (new EmailQueue($this->db))->enqueue([$data['correo']], $subject, $html, [
      'source_module' => 'hoja_cierre', 'internal_cc' => [], 'destination_name' => $data['nombre'],
      'dedupe_key' => 'arrendatario_bienvenida_' . $id, 'meta' => ['tenant_id' => $id],
    ]) > 0;
  }

  /** @param array<string,mixed> $row @return array<string,string> datos para la lista y para llenar la hoja */
  private static function card(array $row): array
  {
    $s = static fn(string $key): string => trim((string) ($row[$key] ?? ''));
    $company = ClosingSheetService::personType($s('tipo_persona')) === 'Juridica';
    return [
      'id' => (string) (int) ($row['_ID'] ?? 0),
      'nombre' => $s('nombre'), 'documento' => $s('documento'), 'tipo_documento' => $s('tipo_documento'),
      'tipo_persona' => ClosingSheetService::personType($s('tipo_persona')),
      'celular' => $s('celular'), 'correo' => $s('correo'), 'ciudad' => $s('ciudad'),
      'representante' => $company && strcasecmp($s('nombre_juridico'), 'No aplica') !== 0 ? $s('nombre_juridico') : '',
      'documento_representante' => $company && strcasecmp($s('documento_juridico'), 'No aplica') !== 0 ? $s('documento_juridico') : '',
    ];
  }

  private static function documentKey(string $document): string
  {
    return strtoupper(preg_replace('/[\s.\-]+/', '', trim($document)) ?? '');
  }

  /** @return list<string> */
  private function glossary(int $id): array
  {
    $table = $this->db->table('jet_post_types');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $items = @unserialize((string) ($this->db->getVar("SELECT `meta_fields` FROM `{$table}` WHERE `id` = ? AND `status` = 'glossary' LIMIT 1", [$id]) ?? ''), ['allowed_classes' => false]);
    $out = [];
    foreach (is_array($items) ? $items : [] as $item) {
      $value = trim((string) ($item['value'] ?? ''));
      if ($value !== '') {
        $out[$value] = $value;
      }
    }
    return array_values($out);
  }
}
