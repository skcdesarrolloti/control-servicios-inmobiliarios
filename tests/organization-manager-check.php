<?php

declare(strict_types=1);

// Gestor organizacional (antes plugin [gestor_organizacional]): lecturas y filtros, guardar y eliminar con bitácora,
// funcionarios (CCT y desactivación), versiones y lectura de políticas, Mi gestión, traslados, PDF, migración de las
// políticas de proveedores, campos sin pestaña del CCT y permisos. SQLite en memoria: no toca la base real.
require dirname(__DIR__) . '/vendor/autoload.php';
if (!defined('SCM_BASE_URL')) define('SCM_BASE_URL', 'https://example.test/panel');

use SCM\Core\Database;
use SCM\Modules\OrganizationManager\OrganizationPdf;
use SCM\Modules\OrganizationManager\OrganizationPolicies;
use SCM\Modules\OrganizationManager\OrganizationPolicyMigration;
use SCM\Modules\OrganizationManager\OrganizationRepository;
use SCM\Modules\OrganizationManager\OrganizationTransfers;
use SCM\Support\CctFieldInstaller;
use SCM\Support\SchemaInspector;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  $checks++;
  echo "OK: {$message}\n";
};
$fails = static function (callable $work, string $expected) use ($check): void {
  try {
    $work();
  } catch (\DomainException $error) {
    $check(str_contains($error->getMessage(), $expected), 'Rechaza: ' . $expected . ($error->getMessage() !== $expected ? ' («' . $error->getMessage() . '»)' : ''));
    return;
  }
  $check(false, 'Debió rechazar: ' . $expected);
};

/** SQLite con el DDL de MySQL traducido y FIND_IN_SET. */
final class OrgTestPdo extends PDO
{
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
    $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->sqliteCreateFunction('FIND_IN_SET', static function ($needle, $set): int {
      $position = array_search((string) $needle, explode(',', (string) $set), true);
      return $position === false ? 0 : $position + 1;
    }, 2);
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    if (str_contains($query, 'information_schema.TABLES')) $query = "SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1";
    if (preg_match('/^DESCRIBE `([^`]+)`/', $query, $m)) $query = "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
    return parent::prepare($query, $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    $stmt = $this->prepare($query);
    $stmt->execute();
    if ($fetchMode !== null) $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
    return $stmt;
  }

  public function exec(string $statement): int|false
  {
    if ($this->inTransaction() && preg_match('/^\s*(CREATE|ALTER|DROP)\s/i', $statement) === 1) throw new RuntimeException('DDL dentro de una transacción: ' . substr(trim($statement), 0, 60));
    if (str_contains($statement, 'ENGINE=InnoDB')) {
      $statement = str_replace(['BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['INTEGER PRIMARY KEY AUTOINCREMENT', ''], $statement);
      $statement = (string) preg_replace('/,\s*UNIQUE KEY \w+ \(([^)]*)\)/', ', UNIQUE ($1)', $statement);
      $statement = (string) preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $statement);
    }
    return parent::exec($statement);
  }
}

$pdo = new OrgTestPdo();
$db = new Database($pdo, 'wp_');
$table = static function (string $name, array $columns) use ($pdo): void {
  $pdo->exec("CREATE TABLE {$name}(_ID INTEGER PRIMARY KEY AUTOINCREMENT, " . implode(', ', array_map(static fn(string $c): string => '"' . $c . '" TEXT', array_unique($columns))) . ')');
};
$cct = ['cct_status', 'cct_author_id', 'cct_created', 'cct_modified'];
$table('wp_jet_cct_areas_organizacion', array_merge($cct, ['nombre', 'responsable', 'descripcion']));
$table('wp_jet_cct_cargos', array_merge($cct, ['nombre_cargo', 'cargos_a_cargo', 'mision_cargo', 'kpis', 'id_area', 'funcion_principal', 'competencias_tecnicas']));
// Políticas sin las columnas de control: así están hoy en producción.
$table('wp_jet_cct_politicas', array_merge($cct, ['politica', 'departamento', 'fecha', 'fecha_actualizacion', 'id_area', 'id_cargo', 'titulo']));
$table('wp_jet_cct_procesos', array_merge($cct, ['alcance', 'definicion', 'entregable', 'rol', 'nombre', 'id_area']));
$table('wp_jet_cct_funciones', array_merge($cct, ['funcion', 'frecuencia', 'medida', 'principal', 'rol', 'id_area', 'id_cargo']));
$table('wp_jet_cct_compensaciones', array_merge($cct, ['base_compensacion', 'condicionado_a', 'id_area', 'id_cargo']));
$table('wp_jet_cct_funcionarios', array_merge($cct, ['id_empleado', 'nombre', 'celular', 'correo', 'rol', 'gestion', 'activo', 'id_sucursal', 'rol_academia', 'documento', 'correo_dian', 'id_area', 'id_cargo', 'pass_others_apss', 'user_others_apss']));
$table('wp_jet_cct_sucursales', array_merge($cct, ['nombre', 'id_comercial', 'correo_comercial']));
$table('wp_jet_cct_inmuebles', array_merge($cct, ['codigo', 'funcionario', 'id_funcionario', 'tipo_negocio', 'estado', 'direccion']));
$table('wp_jet_cct_tickets', array_merge($cct, ['id_ticket', 'id_empleado', 'empleado', 'nombre_empleado', 'correo_empleado', 'celular_empleado', 'sucursal', 'asunto', 'estado', 'estado_administrativo', 'id_inmueble']));
$pdo->exec('CREATE TABLE wp_users(ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT)');
$pdo->exec('CREATE TABLE wp_usermeta(umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT)');
$pdo->exec('CREATE TABLE wp_options(option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT)');
$pdo->exec('CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY, post_author INTEGER, post_type TEXT)');
$pdo->exec('CREATE TABLE wp_postmeta(meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');

foreach ([1 => 'Mantenimiento, Aseo y Reparacion', 3 => 'Contractual', 9 => 'Comercial - Promocion', 15 => 'Contable'] as $id => $name) {
  $db->insert('wp_jet_cct_areas_organizacion', ['_ID' => $id, 'nombre' => $name, 'descripcion' => 'Área ' . $name]);
}
$db->insert('wp_jet_cct_cargos', ['_ID' => 1, 'nombre_cargo' => 'Asistente de Captación', 'id_area' => '9', 'cargos_a_cargo' => '', 'mision_cargo' => 'Captar inmuebles']);
$db->insert('wp_jet_cct_cargos', ['_ID' => 7, 'nombre_cargo' => 'Coordinador Contractual', 'id_area' => '3, 1', 'cargos_a_cargo' => '4;5', 'mision_cargo' => 'Coordinar', 'kpis' => 'Casos al día']);
$db->insert('wp_jet_cct_cargos', ['_ID' => 4, 'nombre_cargo' => 'Asistente Arrendatario', 'id_area' => '3', 'mision_cargo' => 'Atender arrendatarios']);
$db->insert('wp_jet_cct_cargos', ['_ID' => 5, 'nombre_cargo' => 'Asistente Propietario', 'id_area' => '15']);
$db->insert('wp_jet_cct_cargos', ['_ID' => 8, 'nombre_cargo' => 'Proveedor de entregas', 'id_area' => '1']);
$db->insert('wp_jet_cct_sucursales', ['_ID' => 1, 'nombre' => 'Cartagena', 'id_comercial' => '', 'correo_comercial' => '']);
$people = [
  [10, '9', 'Julián Coordinador', 'julian@example.invalid', '7', 'Si', ''],
  [11, '10', 'Dilsa Arrendatario', 'dilsa@example.invalid', '4', 'Si', ''],
  [12, '11', 'Clara Propietario', 'clara@example.invalid', '5', 'Si', ''],
  [13, '12', 'Retirado', 'retirado@example.invalid', '4', 'No', ''],
];
foreach ($people as [$recordId, $employee, $name, $email, $cargo, $active, $area]) {
  $db->insert('wp_jet_cct_funcionarios', ['_ID' => $recordId, 'id_empleado' => $employee, 'nombre' => $name, 'correo' => $email, 'id_cargo' => $cargo, 'activo' => $active, 'id_area' => $area, 'id_sucursal' => '1', 'pass_others_apss' => '$2y$secreto', 'user_others_apss' => 'login' . $employee, 'celular' => '300' . $employee]);
  $db->insert('wp_users', ['ID' => (int) $employee, 'user_login' => 'u' . $employee, 'user_email' => $email]);
}
$db->insert('wp_users', ['ID' => 55, 'user_login' => 'nueva', 'user_email' => 'nueva@example.invalid']);
$db->insert('wp_usermeta', ['user_id' => 9, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['servicio_arrendatario' => true])]);
$db->insert('wp_options', ['option_name' => 'wp_user_roles', 'option_value' => serialize(['servicio_arrendatario' => ['name' => 'Servicio arrendatario'], 'administrator' => ['name' => 'Administrador']])]);
$db->insert('wp_jet_cct_politicas', ['_ID' => 47, 'titulo' => 'Garantía de Servicios Públicos', 'politica' => "Primer párrafo.\n\nSegundo <párrafo> & más.", 'id_area' => '3', 'id_cargo' => '7,4', 'fecha_actualizacion' => '1700000000']);
$db->insert('wp_jet_cct_politicas', ['_ID' => 48, 'titulo' => 'Uso del celular', 'politica' => 'No usar el celular en reuniones.', 'id_area' => '', 'id_cargo' => '4,5']);
$db->insert('wp_jet_cct_funciones', ['_ID' => 1, 'funcion' => 'Revisar contratos', 'frecuencia' => 'Semanal', 'id_cargo' => '7', 'id_area' => '3']);
$db->insert('wp_jet_cct_procesos', ['_ID' => 1, 'nombre' => 'Renovación de contratos', 'alcance' => 'Todos los contratos', 'id_area' => '3']);
$db->insert('wp_jet_cct_compensaciones', ['_ID' => 1, 'base_compensacion' => 'Bono por renovación', 'condicionado_a' => 'Meta mensual', 'id_cargo' => '7', 'id_area' => '3']);

$repo = new OrganizationRepository($db);
$actor = ['id' => '9', 'name' => 'Julián Coordinador'];

// --- Lecturas y filtros (los mismos del plugin) ---------------------------------------------------------------------
$names = static fn(array $result, string $column): array => array_map(static fn(array $row): string => (string) $row[$column], $result['rows']);
$check($names($repo->list('cargos', ['id_area' => '1']), 'nombre_cargo') === ['Proveedor de entregas', 'Coordinador Contractual'], 'Filtra cargos por área con listas «3, 1» normalizadas.');
$check($names($repo->list('politicas', ['id_cargo' => '5']), 'titulo') === ['Uso del celular'], 'Filtra políticas por cargo.');
$check($names($repo->list('politicas', ['titulo' => 'garant']), 'titulo') === ['Garantía de Servicios Públicos'], 'Busca por título.');
$check($names($repo->list('politicas', ['detalle' => 'reuniones']), 'titulo') === ['Uso del celular'], 'Busca en el detalle.');
$check($names($repo->list('politicas', ['sin_area' => '1']), 'titulo') === ['Uso del celular'], 'Filtra políticas sin área.');
$check(count($repo->list('politicas', ['estado' => 'Vigente'])['rows']) === 2, 'Sin la columna estado, el filtro de estado no rompe la consulta.');
$people = $repo->list('funcionarios', ['activo' => 'si']);
$check(count($people['rows']) === 3 && !array_key_exists('pass_others_apss', $people['rows'][0]) && !array_key_exists('user_others_apss', $people['rows'][0]), 'Filtra activos y nunca envía las credenciales del panel.');
$check($names($repo->list('funcionarios', ['id_area' => '15']), 'nombre') === ['Clara Propietario'], 'Funcionarios sin áreas propias se filtran por las áreas de sus cargos.');
$julian = array_values(array_filter($people['rows'], static fn(array $row): bool => $row['id_empleado'] === '9'))[0];
$check($julian['user_role'] === 'servicio_arrendatario' && $julian['user_role_label'] === 'Servicio arrendatario', 'Muestra el rol de WordPress (solo lectura).');
$listed = $repo->list('politicas', [])['rows'];
$check(!array_key_exists('politica', $listed[0]) && $listed[0]['extracto'] !== '', 'El listado de políticas trae el extracto, no el texto completo.');
$catalog = $repo->catalog();
$check(count($catalog['funcionarios']) === 3 && $catalog['cargos'][0]['id_area'] !== null && count($catalog['wp_roles']) === 2, 'El catálogo trae funcionarios activos, cargos con sus áreas y roles de WordPress.');

// --- Guardar y eliminar con bitácora --------------------------------------------------------------------------------
$fails(fn() => $repo->save('areas_organizacion', 0, ['nombre' => '  '], $actor), 'Escribe el nombre del área.');
$created = $repo->save('areas_organizacion', 0, ['nombre' => 'Tesorería <b>', 'descripcion' => "Pagos\ny caja"], $actor);
$area = $repo->find('areas_organizacion', $created['id']);
$check($created['created'] && $area['nombre'] === 'Tesorería' && $area['cct_author_id'] === '9' && $area['cct_created'] !== '', 'Crea con cct_author_id = id_empleado de quien crea y limpia el HTML.');
$repo->save('areas_organizacion', $created['id'], ['nombre' => 'Tesorería y caja', 'descripcion' => "Pagos\ny caja"], $actor);
$log = $repo->activityLog()->recent('areas_organizacion', $created['id']);
$check(count($log) === 2 && $log[0]['accion'] === 'editar' && str_contains($log[0]['resumen'], 'nombre') && $log[0]['id_empleado'] === '9', 'La bitácora registra crear y editar con los campos cambiados.');
$cargoSaved = $repo->save('cargos', 7, ['nombre_cargo' => 'Coordinador Contractual', 'id_area' => ['3', '1'], 'cargos_a_cargo' => '4,5,7', 'mision_cargo' => 'Coordinar', 'kpis' => 'Casos al día'], $actor);
$check($repo->find('cargos', 7)['cargos_a_cargo'] === '4,5', 'Un cargo no puede tenerse a cargo a sí mismo.');
$impact = $repo->deleteImpact('areas_organizacion', 3);
$check($impact['name'] === 'Contractual' && in_array('2 cargos', $impact['items'], true) && in_array('1 política', $impact['items'], true), 'El aviso de eliminar nombra el registro y lo que le apunta.');
$deleted = $repo->delete('areas_organizacion', $created['id'], $actor);
$entry = $db->getRow("SELECT * FROM wp_gpo_actividad WHERE accion = 'eliminar'");
$check($repo->find('areas_organizacion', $created['id']) === null && str_contains($deleted['message'], 'Tesorería y caja') && str_contains((string) $entry['detalle'], 'Tesorería y caja') && $entry['nombre'] === 'Julián Coordinador', 'Elimina físicamente y deja quién lo hizo con copia del registro.');
$fails(fn() => $repo->delete('areas_organizacion', $created['id'], $actor), 'El registro ya no existe');

// --- Funcionarios: solo el CCT; se desactivan, no se eliminan --------------------------------------------------------
$fails(fn() => $repo->save('funcionarios', 0, ['nombre' => 'Sin usuario', 'correo' => 'nadie@example.invalid'], $actor), 'No hay un usuario de WordPress con ese correo');
$fails(fn() => $repo->save('funcionarios', 0, ['nombre' => 'Repetido', 'correo' => 'dilsa@example.invalid'], $actor), 'ya es un funcionario');
$newPerson = $repo->save('funcionarios', 0, ['nombre' => 'Nueva Comercial', 'correo' => 'NUEVA@example.invalid', 'id_cargo' => '1', 'id_sucursal' => '1', 'rol' => 'captación y actualización', 'activo' => 'Si'], $actor);
$row = $repo->find('funcionarios', $newPerson['id']);
$check($row['id_empleado'] === '55' && $row['rol'] === 'Comercial' && $row['id_area'] === '9' && $newPerson['warnings'] !== [], 'Enlaza el usuario de WordPress existente por correo, normaliza el rol, toma las áreas del cargo y avisa que WordPress no se sincroniza.');
$check($db->getRow('SELECT id_comercial, correo_comercial FROM wp_jet_cct_sucursales WHERE _ID = 1') === ['id_comercial' => (string) $newPerson['id'], 'correo_comercial' => 'nueva@example.invalid'], 'El funcionario del cargo comercial queda como comercial de la sucursal.');
$check((int) $db->getVar('SELECT COUNT(*) FROM wp_usermeta') === 1, 'No escribe metas de WordPress.');
$fails(fn() => $repo->delete('funcionarios', 10, $actor), 'No puedes desactivarte a ti mismo');
$result = $repo->delete('funcionarios', 11, $actor);
$check($result['action'] === 'desactivar' && $repo->find('funcionarios', 11)['activo'] === 'No' && $repo->activityLog()->recent('funcionarios', 11)[0]['accion'] === 'desactivar', 'Eliminar un funcionario lo desactiva (activo = No) con rastro en la bitácora.');
$db->update('wp_jet_cct_funcionarios', ['activo' => 'Si'], ['_ID' => 11]);

// --- Políticas: texto seguro, versiones e historial ----------------------------------------------------------------
$fails(fn() => $repo->save('politicas', 0, ['titulo' => 'Sin texto', 'politica' => '<p> </p>'], $actor), 'Escribe el texto de la política.');
$fails(fn() => $repo->save('politicas', 0, ['politica' => 'Texto'], $actor), 'Escribe el título de la política.');
$policy = $repo->save('politicas', 0, ['titulo' => 'Llaves', 'politica' => '<p>Guardar <strong>llaves</strong></p><script>alert(1)</script><p onclick="x()">ok</p><a href="javascript:alert(1)">mal</a>', 'id_area' => '3', 'id_cargo' => '7'], $actor);
$stored = $repo->find('politicas', $policy['id']);
$check(!str_contains($stored['politica'], 'script') && !str_contains($stored['politica'], 'onclick') && !str_contains($stored['politica'], 'javascript') && str_contains($stored['politica'], '<strong>llaves</strong>'), 'El texto se guarda como HTML seguro (lista blanca).');
$check($stored['fecha'] !== '' && $stored['fecha_actualizacion'] !== '', 'Al crear pone la fecha y la fecha de actualización.');
$check(OrganizationPolicies::cleanHtml("Uno\n\nDos <b & c") === '<p>Uno</p><p>Dos &lt;b &amp; c</p>', 'El texto plano existente se convierte en párrafos escapados.');
$check($repo->present('politicas', $repo->find('politicas', 47))['html'] === '<p>Primer párrafo.</p><p>Segundo &lt;párrafo&gt; &amp; más.</p>', 'Las políticas actuales (texto plano) se muestran sin inyectar HTML.');
$fails(fn() => $repo->save('politicas', $policy['id'], ['titulo' => 'Llaves', 'politica' => 'x', 'motivo_cambio' => 'Cambio'], $actor, true), 'Falta la columna de versión');

// Columnas de control (lo que hace bin/add-policy-control-fields.php --aplicar).
foreach (OrganizationPolicies::CONTROL_COLUMNS as $column) {
  $pdo->exec("ALTER TABLE wp_jet_cct_politicas ADD COLUMN \"{$column}\" TEXT");
}
$repo = new OrganizationRepository($db);
$check($repo->policies()->availableColumns() === OrganizationPolicies::CONTROL_COLUMNS, 'Detecta las columnas de control cuando existen.');
$second = $repo->save('politicas', 0, ['titulo' => 'Reparaciones', 'politica' => 'Texto v1', 'id_area' => '1', 'id_cargo' => '7,4', 'estado' => 'Vigente', 'audiencia' => 'Empleados', 'vigencia_desde' => '2026-01-15'], $actor);
$row = $repo->find('politicas', $second['id']);
$check($row['codigo'] === 'POL-MAR-001' && $row['version'] === '1' && $row['estado'] === 'Vigente' && OrganizationPolicies::timestampToDate($row['vigencia_desde']) === '2026-01-15', 'Sugiere el código POL-<área>-001, versión 1 y guarda las fechas como timestamp.');
$check($repo->policies()->suggestCode('1') === 'POL-MAR-002', 'El siguiente código del área continúa la numeración.');
$fails(fn() => $repo->save('politicas', 0, ['titulo' => 'Otra', 'politica' => 'x', 'codigo' => 'pol-mar-001'], $actor), 'ya lo tiene otra política');
$fails(fn() => $repo->save('politicas', $second['id'], ['titulo' => 'Reparaciones', 'politica' => 'x', 'fecha_revision' => '2026-05-01', 'fecha_proxima_revision' => '2026-04-01'], $actor), 'La próxima revisión no puede ser anterior');
$repo->save('politicas', $second['id'], ['titulo' => 'Reparaciones', 'politica' => 'Texto v1 corregido', 'id_cargo' => '7,4'], $actor);
$check($repo->find('politicas', $second['id'])['version'] === '1' && $repo->find('politicas', $second['id'])['codigo'] === 'POL-MAR-001', 'Una edición normal conserva la versión y lo que el formulario no envía.');
$readers = $repo->policies()->readers($repo->find('politicas', $second['id']), $repo->activeFuncionarios(), [7 => 'Coordinador', 4 => 'Asistente Arrendatario']);
$check($readers['expected'] === 2 && count($readers['pending']) === 2 && $readers['confirmed'] === [], 'Antes de confirmar, faltan los funcionarios activos con un cargo de la política.');
$first = $repo->policies()->confirmRead($repo->find('politicas', $second['id']), '9', 'Julián Coordinador');
$again = $repo->policies()->confirmRead($repo->find('politicas', $second['id']), '9', 'Julián Coordinador');
$check($first === $again && (int) $db->getVar('SELECT COUNT(*) FROM wp_gpo_politica_lecturas') === 1, '«Leí y entiendo» no se duplica.');
$readers = $repo->policies()->readers($repo->find('politicas', $second['id']), $repo->activeFuncionarios(), [7 => 'Coordinador', 4 => 'Asistente Arrendatario']);
$check(count($readers['confirmed']) === 1 && $readers['confirmed'][0]['asignado'] && $readers['pending'][0]['nombre'] === 'Dilsa Arrendatario' && $readers['pending'][0]['cargo'] === 'Asistente Arrendatario', 'El reporte muestra quién confirmó y quién falta con su cargo.');
$fails(fn() => $repo->save('politicas', $second['id'], ['titulo' => 'Reparaciones', 'politica' => 'Texto v2', 'motivo_cambio' => ''], $actor, true), 'Escribe el motivo del cambio de versión.');
$v2 = $repo->save('politicas', $second['id'], ['titulo' => 'Reparaciones', 'politica' => 'Texto v2', 'motivo_cambio' => 'Cambia el tope de cobro'], $actor, true);
$row = $repo->find('politicas', $second['id']);
$check($v2['record']['version'] === '2' && $row['motivo_cambio'] === 'Cambia el tope de cobro', 'Nueva versión sube a la 2 y guarda el motivo.');
$history = $repo->policies()->history($second['id']);
$check(count($history) === 3 && $history[0]['version'] === 2 && $history[0]['accion'] === 'nueva_version' && $history[0]['motivo'] === 'Cambia el tope de cobro' && $history[2]['accion'] === 'creada' && $history[2]['html'] === '<p>Texto v1</p>' && $history[0]['nombre'] === 'Julián Coordinador', 'El historial guarda cada guardado con versión, texto, autor y motivo.');
$check(($history[0]['datos']['codigo'] ?? '') === 'POL-MAR-001', 'El historial guarda una copia de los campos de control.');
$readers = $repo->policies()->readers($row, $repo->activeFuncionarios());
$check($readers['version'] === 2 && $readers['confirmed'] === [] && count($readers['pending']) === 2, 'Con la nueva versión hay que volver a confirmar la lectura.');
$check($repo->activityLog()->recent('politicas', $second['id'])[0]['accion'] === 'nueva_version', 'La bitácora registra la nueva versión.');
$fails(fn() => $repo->save('politicas', $second['id'], ['titulo' => 'Reparaciones', 'politica' => 'x', 'id_politica_base' => (string) $second['id']], $actor), 'no puede ser su propia política base');
$prov = $repo->save('politicas', 0, ['titulo' => 'Reparaciones', 'politica' => 'Para proveedores', 'audiencia' => 'Proveedores', 'id_politica_base' => (string) $second['id'], 'id_cargo' => '8'], $actor);
$check($repo->deleteImpact('politicas', $second['id'])['items'] === ['1 versión para proveedores'], 'El aviso de eliminar una política cuenta sus versiones para proveedores.');
$db->update('wp_jet_cct_politicas', ['estado' => 'Borrador'], ['_ID' => 48]);

// --- Mi gestión y gestión por cargo ----------------------------------------------------------------------------------
$mine = $repo->managementForEmployee('9');
$titles = array_column($mine['politicas'], 'titulo');
$check($mine['funcionario']['nombre'] === 'Julián Coordinador' && array_column($mine['equipo'], 'nombre_cargo') === ['Asistente Arrendatario', 'Asistente Propietario'], 'Mi gestión trae el funcionario y su equipo (cargos a cargo).');
$check(in_array('Garantía de Servicios Públicos', $titles, true) && in_array('Llaves', $titles, true) && !in_array('Uso del celular', $titles, true), 'Mi gestión trae las políticas de su cargo.');
$check(count($mine['funciones']) === 1 && count($mine['procesos']) === 1 && count($mine['compensaciones']) === 1 && array_column($mine['cargos'][0]['perfil'], 'label') === ['Misión', 'KPIs'], 'Trae funciones, procesos del área, compensaciones y el perfil del cargo.');
$byId = array_column($mine['politicas'], null, 'titulo');
$check($byId['Reparaciones']['leida'] === '' && $byId['Reparaciones']['version'] === '2' && !isset($byId['Reparaciones']['politica']) && $byId['Reparaciones']['html'] === '<p>Texto v2</p>', 'Cada política trae su HTML limpio y si ya se confirmó la versión vigente.');
$sub = $repo->managementForCargo(4, '10');
$check(array_column($sub['politicas'], 'titulo') === ['Reparaciones', 'Garantía de Servicios Públicos'], 'Las políticas en Borrador no aparecen en la gestión del cargo.');
$check($repo->canInspectCargo('9', 7) && $repo->canInspectCargo('9', 4) && !$repo->canInspectCargo('9', 1) && !$repo->canInspectCargo('10', 7), 'Gestión por cargo: el propio cargo o los que tiene a cargo.');
$check($repo->policyAssignedTo($repo->find('politicas', 47), '10') && !$repo->policyAssignedTo($repo->find('politicas', 47), '11'), 'Solo confirma la lectura quien tiene la política en su cargo.');
$check($repo->managementForEmployee('999') === null, 'Sin funcionario asociado no hay Mi gestión.');

// --- PDF ---------------------------------------------------------------------------------------------------------------
$areaNames = array_column($repo->catalog()['areas'], 'nombre', 'id');
$cargoNames = array_column($repo->catalog()['cargos'], 'nombre', 'id');
$pdfHtml = OrganizationPdf::policiesHtml([$repo->find('politicas', 47), $repo->find('politicas', $policy['id']), $repo->find('politicas', 48)], $areaNames, $cargoNames, 'Ana <Gerencia>');
$check(str_contains($pdfHtml, 'SKC SuCasa Inmobiliaria') && str_contains($pdfHtml, 'Sin área asignada') && str_contains($pdfHtml, 'Ana &lt;Gerencia&gt;') && !str_contains($pdfHtml, '<script') && !str_contains($pdfHtml, 'ucursal'), 'El PDF de políticas agrupa por área, usa el nombre corporativo, escapa y no muestra sucursal.');
$check(strpos($pdfHtml, 'Contractual') < strpos($pdfHtml, 'Sin área asignada') && substr_count($pdfHtml, 'class="ord-item"') === 3, 'Una política por bloque y las áreas en orden alfabético.');
$mgmtHtml = OrganizationPdf::managementHtml($mine, 'Julián', 'Julián Coordinador');
$check(str_contains($mgmtHtml, 'Reporte integral de gestión') && str_contains($mgmtHtml, 'Revisar contratos') && !str_contains($mgmtHtml, 'Bono por renovación') && !str_contains($mgmtHtml, 'Renovación de contratos'), 'El reporte integral trae cargo, políticas y funciones; procesos y compensaciones ya no se usan.');

// --- Traslados ---------------------------------------------------------------------------------------------------------
$db->insert('wp_jet_cct_inmuebles', ['_ID' => 1, 'codigo' => '500', 'funcionario' => 'Dilsa Arrendatario', 'id_funcionario' => '10', 'tipo_negocio' => 'Arriendo']);
$db->insert('wp_jet_cct_inmuebles', ['_ID' => 2, 'codigo' => '501', 'funcionario' => 'Dilsa Arrendatario', 'id_funcionario' => '10', 'tipo_negocio' => 'Venta']);
$db->insert('wp_jet_cct_inmuebles', ['_ID' => 3, 'codigo' => '999', 'funcionario' => 'Dilsa Arrendatario', 'id_funcionario' => '10', 'tipo_negocio' => 'Arriendo']);
$db->insert('wp_posts', ['ID' => 500, 'post_author' => 10, 'post_type' => 'inmuebles']);
$db->insert('wp_posts', ['ID' => 501, 'post_author' => 10, 'post_type' => 'inmuebles']);
$db->insert('wp_postmeta', ['post_id' => 500, 'meta_key' => 'id_empleado', 'meta_value' => '10']);
$db->insert('wp_jet_cct_tickets', ['_ID' => 1, 'id_ticket' => '9001', 'id_empleado' => '10', 'empleado' => 'Dilsa', 'estado' => 'Nuevo', 'estado_administrativo' => 'En proceso']);
$db->insert('wp_jet_cct_tickets', ['_ID' => 2, 'id_ticket' => '9002', 'id_empleado' => '10', 'empleado' => 'Dilsa', 'estado' => 'Nuevo', 'estado_administrativo' => 'Pendiente']);
$transfers = new OrganizationTransfers($db, new SchemaInspector($db));
$transfers->ensureSchema();
$preview = $transfers->preview('10', ['inmuebles'], ['modules' => ['inmuebles' => ['filters' => ['tipo_negocio' => 'Arriendo']]]]);
$check($preview['total'] === 2 && $preview['modules'][0]['records'][0]['codigo'] === '999', 'Previsualiza con el filtro de tipo de negocio.');
$fails(fn() => $transfers->preview('777', ['inmuebles']), 'Selecciona un funcionario origen válido.');
$fails(fn() => $transfers->execute('10', '10', ['inmuebles'], '', $actor), 'no pueden ser el mismo');
$fails(fn() => $transfers->execute('10', '12', ['inmuebles'], '', $actor), 'El funcionario destino está inactivo.');
$fails(fn() => $transfers->execute('10', '11', ['tickets'], '', $actor, ['modules' => ['tickets' => ['mode' => 'selected', 'selected_ids' => []]]]), 'Selecciona al menos un registro de Tickets.');
$check((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_tickets WHERE id_empleado = '10'") === 2, 'Un traslado rechazado no mueve nada.');
$done = $transfers->execute('10', '11', ['inmuebles'], 'Retiro de Dilsa', $actor, ['modules' => ['inmuebles' => ['filters' => ['tipo_negocio' => 'Arriendo']]]]);
$check($done['total'] === 2 && $done['postmeta_updated'] === 1 && $done['postmeta_skipped'] === 1, 'Traslada los inmuebles filtrados y su postmeta (sin post en WordPress se cuenta como omitido).');
$check($db->getVar("SELECT funcionario FROM wp_jet_cct_inmuebles WHERE _ID = 1") === 'Clara Propietario' && $db->getVar("SELECT id_funcionario FROM wp_jet_cct_inmuebles WHERE _ID = 2") === '10', 'Cambia el funcionario solo en los inmuebles del filtro.');
$meta = array_column($db->getResults('SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = 500'), 'meta_value', 'meta_key');
$check($meta === ['id_empleado' => '11', 'empleado' => 'Clara Propietario', 'email-empleado' => 'clara@example.invalid', 'celular-empleado' => '30011'] && (int) $db->getVar('SELECT post_author FROM wp_posts WHERE ID = 500') === 11, 'Actualiza los metas del inmueble (sin duplicarlos) y el autor del post.');
$selected = $transfers->execute('10', '11', ['tickets'], '', $actor, ['modules' => ['tickets' => ['mode' => 'selected', 'selected_ids' => [2]]]]);
$check($selected['total'] === 1 && $db->getVar('SELECT id_empleado FROM wp_jet_cct_tickets WHERE _ID = 2') === '11' && $db->getVar('SELECT id_empleado FROM wp_jet_cct_tickets WHERE _ID = 1') === '10', 'Solo los seleccionados.');
$check($db->getVar('SELECT estado_administrativo FROM wp_jet_cct_tickets WHERE _ID = 2') === 'Pendiente' && $db->getVar('SELECT correo_empleado FROM wp_jet_cct_tickets WHERE _ID = 2') === 'clara@example.invalid', 'No cambia el estado administrativo del ticket; sí los datos del funcionario.');
$logs = $transfers->logs();
$check(count($logs) === 2 && $logs[1]['reason'] === 'Retiro de Dilsa' && $logs[1]['details'][0]['values'] === ['Código 999', 'Código 500'] && (int) $db->getVar('SELECT user_id FROM wp_gpo_transfer_log WHERE id = 1') === 9, 'Registra cada traslado en wp_gpo_transfer_log con motivo, detalle y quien lo hizo.');
$check(OrganizationTransfers::logDetails([['label' => 'Inmuebles', 'tables' => [['label' => 'Inmuebles', 'updated' => 4]]]])[0]['legacy'] === true, 'Los traslados antiguos sin detalle se muestran como tal.');

// --- Migración de políticas de proveedores (función pura) ------------------------------------------------------------
$sample = [
  ['_ID' => 1, 'titulo' => 'Revisión Correctiva', 'id_area' => '1', 'id_cargo' => '3,7'],
  ['_ID' => 2, 'titulo' => 'Tiempo de Elaboracion de Cotizaciones', 'id_area' => '1', 'id_cargo' => '3'],
  ['_ID' => 3, 'titulo' => 'Procedencia de Clientes', 'id_area' => '', 'id_cargo' => '1'],
  ['_ID' => 4, 'titulo' => 'Procedencia de Clientes', 'id_area' => '', 'id_cargo' => '17'],
  ['_ID' => 10, 'titulo' => 'Revisión Correctiva ( Prov.)', 'id_area' => '1', 'id_cargo' => '8'],
  ['_ID' => 11, 'titulo' => 'Tiempo de Elaboración de Cotizaciones (Prov,)', 'id_area' => '1', 'id_cargo' => '8'],
  ['_ID' => 12, 'titulo' => 'Revisión  correctiva (Prov)', 'id_area' => '', 'id_cargo' => '17'],
  ['_ID' => 13, 'titulo' => 'Pagos por Servicios Prestados (Prov.)', 'id_area' => '1', 'id_cargo' => '8'],
  ['_ID' => 14, 'titulo' => 'Procedencia de Clientes (Prov.)', 'id_area' => '', 'id_cargo' => '17'],
  ['_ID' => 15, 'titulo' => 'Giro a Proveedores', 'id_area' => '15', 'id_cargo' => '5'],
];
$plan = OrganizationPolicyMigration::plan($sample, [1 => 'Mantenimiento, Aseo y Reparacion', 15 => 'Contable', 3 => 'Contractual']);
$changes = array_column($plan['changes'], 'set', 'id');
$check(OrganizationPolicyMigration::isProvider('X ( Prov.)') && OrganizationPolicyMigration::isProvider('X (Prov,)') && OrganizationPolicyMigration::isProvider('X (Prov)') && !OrganizationPolicyMigration::isProvider('Giro a Proveedores'), 'Reconoce los sufijos irregulares de proveedores y no «Proveedores» en el título.');
$check($changes[10]['titulo'] === 'Revisión Correctiva' && $changes[10]['audiencia'] === 'Proveedores' && $changes[10]['id_politica_base'] === '1', 'Proveedor: quita el sufijo, audiencia Proveedores y base por título normalizado.');
$check($changes[11]['id_politica_base'] === '2' && $changes[12]['id_politica_base'] === '1', 'La comparación ignora tildes, mayúsculas y espacios.');
$check($changes[1]['codigo'] === 'POL-MAR-001' && $changes[10]['codigo'] === 'POL-MAR-001-P' && $changes[12]['codigo'] === 'POL-MAR-001-P2' && $changes[3]['codigo'] === 'POL-GEN-001', 'Códigos POL-<área>-NNN; proveedores heredan el de su base con -P, -P2.');
$check($changes[1]['audiencia'] === 'Empleados' && $changes[1]['estado'] === 'Vigente' && $changes[1]['version'] === '1' && $changes[15]['audiencia'] === 'Empleados', 'Las demás quedan Empleados, Vigente, versión 1.');
$check(array_column($plan['unmatched'], 'id') === [13] && $plan['ambiguous'][0]['id'] === 14 && $plan['ambiguous'][0]['candidates'] === [3, 4] && !isset($changes[13]['id_politica_base']), 'Sin coincidencia o con varias, se reporta y no se adivina la base.');
$check($plan['duplicates'][0]['ids'] === [3, 4] && array_column($plan['provider_like'], 'id') === [4], 'Reporta títulos repetidos y políticas sin sufijo solo para proveedores.');
$check($plan['counts']['proveedores'] === 5 && $plan['counts']['con_base'] === 3, 'Cuenta proveedores y bases encontradas.');
$applied = array_map(static function (array $row) use ($changes): array { return array_merge($row, $changes[(int) $row['_ID']] ?? []); }, $sample);
$rerun = OrganizationPolicyMigration::plan($applied, [1 => 'Mantenimiento, Aseo y Reparacion', 15 => 'Contable']);
$check($rerun['changes'] === [], 'Repetir la migración ya aplicada no cambia nada.');
$check(array_column($rerun['unmatched'], 'id') === [13] && $rerun['ambiguous'][0]['id'] === 14, 'Al repetir siguen reportadas las bases que hay que asignar a mano.');
$check(OrganizationPolicies::areaAbbreviations([3 => 'Contractual', 15 => 'Contable', 2 => 'Facturacion y Cartera', 16 => 'Formación y Capacitación', 6 => 'Comercial- Precaptacion', 8 => 'Comercial- Prospectacion']) === [2 => 'FCA', 3 => 'CON', 6 => 'CPR', 8 => 'CPRO', 15 => 'CONT', 16 => 'FCAP'], 'Las abreviaturas de área son estables y no se repiten.');

// --- CctFieldInstaller: campos sin pestaña (CCT politicas) ----------------------------------------------------------
$metaFields = [
  ['name' => 'fecha', 'object_type' => 'field', 'isNested' => false],
  ['name' => 'titulo', 'object_type' => 'field', 'isNested' => false],
];
$organized = CctFieldInstaller::organize(array_merge($metaFields, [['name' => 'codigo', 'object_type' => 'field', 'isNested' => false]]), [CctFieldInstaller::NO_TAB => ['codigo']]);
$check(array_column($organized, 'name') === ['fecha', 'titulo', 'codigo'], 'Un campo sin pestaña que ya está suelto no se mueve.');
$withTabs = [['name' => 'a', 'object_type' => 'field'], ['name' => 'tab1', 'object_type' => 'accordion'], ['name' => 'nuevo', 'object_type' => 'field', 'isNested' => true], ['name' => 'b', 'object_type' => 'field', 'isNested' => true]];
$organized = CctFieldInstaller::organize($withTabs, [CctFieldInstaller::NO_TAB => ['nuevo']]);
$check(array_column($organized, 'name') === ['a', 'nuevo', 'tab1', 'b'] && $organized[1]['isNested'] === false, 'Un campo pedido sin pestaña va con los campos sueltos del inicio.');
$check(array_column(CctFieldInstaller::organize($withTabs, ['tab1' => ['a']]), 'name') === ['tab1', 'nuevo', 'b', 'a'], 'Mover a una pestaña sigue funcionando igual.');

// --- Permisos ------------------------------------------------------------------------------------------------------------
$class = \SCM\App\SuCasaControlServiciosInmobiliarios::class;
$app = (new ReflectionClass($class))->newInstanceWithoutConstructor();
$settings = (new ReflectionClass(\SCM\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\SCM\Core\App::class, 'settings'))->setValue(null, $settings);
$setSettings = static fn(array $data) => (new ReflectionProperty(\SCM\Core\Settings::class, 'data'))->setValue($settings, $data);
$invoke = static fn(string $method, ...$args) => (new ReflectionMethod($app, $method))->invoke($app, ...$args);
$orgActions = ['org_manager_view', 'org_manager_manage', 'org_manager_delete', 'org_manager_transfer', 'org_manager_export'];
$keys = $invoke('dashboardActionPermissionKeys');
$check(!array_diff($orgActions, $keys) && !array_diff($orgActions, $class::SENSITIVE_DEFAULT_ACTIONS), 'Las acciones del Gestor organizacional están en el catálogo y son sensibles.');
$setSettings(['dashboard_action_permissions' => ['5' => ['quote_edit'], '11' => ['quote_edit']], 'dashboard_action_permissions_known_keys' => array_values(array_diff($keys, $orgActions))]);
$config = $invoke('dashboardActionPermissionsConfig');
$check(!array_intersect($orgActions, $config['5']) && !array_diff($orgActions, $config['11']), 'Hasta guardar permisos solo los cargos administradores las tienen.');
$setSettings([]);
$_SESSION['scm_user_cargo'] = '5';
$check(!array_intersect($orgActions, $invoke('currentDashboardAllowedActions')), 'Un cargo sin permisos configurados no las recibe.');
$_SESSION['scm_user_cargo'] = '14';
$check(!array_diff($orgActions, $invoke('currentDashboardAllowedActions')), 'Un cargo administrador sin configurar sí las tiene.');
$setSettings(['dashboard_tab_permissions' => ['5' => ['mis_tickets']], 'dashboard_tab_permissions_known_keys' => array_keys($invoke('dashboardPermissionTabs'))]);
$_SESSION['scm_user_cargo'] = '5';
$check(in_array('gestor_organizacional', $invoke('currentDashboardAllowedTabs'), true), 'Mi gestión (la opción del menú) la tiene todo funcionario.');
$root = dirname(__DIR__);
$handlers = (string) file_get_contents($root . '/src/App/Concerns/HandlesOrganizationManager.php');
$router = (string) file_get_contents($root . '/src/Http/Api/AuthenticatedActionRouter.php');
preg_match_all('/public function (ajax_handler_org_\w+)/', $handlers, $matches);
foreach ($matches[1] as $handler) $check(str_contains($router, "'{$handler}'"), "La ruta de {$handler} está registrada.");
preg_match_all('/public function (ajax_handler_org_\w+)\(\): void\s*\{(.*?)\n  \}/s', $handlers, $bodies, PREG_SET_ORDER);
$required = [
  'ajax_handler_org_list' => 'org_manager_view', 'ajax_handler_org_record' => 'org_manager_view', 'ajax_handler_org_save' => 'org_manager_manage',
  'ajax_handler_org_delete' => 'org_manager_delete', 'ajax_handler_org_delete_impact' => 'org_manager_delete', 'ajax_handler_org_policy_history' => 'org_manager_view',
  'ajax_handler_org_policy_readers' => 'org_manager_view', 'ajax_handler_org_policy_code' => 'org_manager_manage', 'ajax_handler_org_activity' => 'org_manager_view',
  'ajax_handler_org_transfer_data' => 'org_manager_transfer', 'ajax_handler_org_transfer_preview' => 'org_manager_transfer', 'ajax_handler_org_transfer_execute' => 'org_manager_transfer',
  'ajax_handler_org_policies_pdf' => 'org_manager_export',
];
foreach ($bodies as [, $name, $body]) {
  $check(str_contains($body, 'orgRequireTab()'), "{$name} verifica la sesión (CSRF) y la opción del menú.");
  if (isset($required[$name])) $check(str_contains($body, "requireDashboardAction('{$required[$name]}'"), "{$name} exige {$required[$name]}.");
}
$check(str_contains($handlers, "orgCanInspectCargo") && preg_match('/function ajax_handler_org_cargo_management.*?orgCanInspectCargo/s', $handlers) === 1, 'La gestión de otro cargo exige ser su cargo, tenerlo a cargo u org_manager_view.');

echo "{$checks} verificaciones del Gestor organizacional.\n";
