<?php

declare(strict_types=1);

// Revisión antes de la ocupación nativa: crear, editar, eliminar, sincronización con caso/contrato/inmueble
// e informe público sobre SQLite en memoria con las columnas reales de producción.

require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
foreach (['SCM_BASE_URL' => 'https://panel.test', 'SCM_APP_SECRET' => str_repeat('k', 40), 'SCM_UPLOAD_MAX_BYTES' => 10485760, 'SCM_VERSION' => 'test'] as $name => $value) {
  if (!defined($name)) define($name, $value);
}
if (!function_exists('esc_html')) { function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); } }

final class MysqlishPdo extends PDO
{
  public static function rewrite(string $query): string
  {
    if (str_contains($query, 'information_schema.TABLES')) {
      return "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1";
    }
    if (preg_match('/^DESCRIBE `([^`]+)`$/', trim($query), $m)) {
      return "SELECT name FROM pragma_table_info('" . $m[1] . "')";
    }
    return $query;
  }

  public function prepare(string $query, array $options = []): PDOStatement|false
  {
    return parent::prepare(self::rewrite($query), $options);
  }

  public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
  {
    return $fetchMode === null ? parent::query(self::rewrite($query)) : parent::query(self::rewrite($query), $fetchMode, ...$fetchModeArgs);
  }
}

$pdo = new MysqlishPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Columnas reales de wp_jet_cct_revision_entrega (incluye ac_pago_* y resultado, que el formulario ya no usa, y piso).
$reviewColumns = '_ID cct_status direccion tip_inm resultado fech_linderos conjunto torre parqueadero deposito pano_conjunto pano_torre fachada habitacion sala_comedor_hall bano hab_ser bano_ser cocina garaje patio area_labores'
  . ' num_pol_ag ob_num_pol_agua num_med_ag num_med_igual_ag ob_num_med_agua est_med_ag ob_est_med_agua dir_ag ob_dir_agua fac_ag valor_fac_ag fc_pago_ag ob_fac_agua fc_sus_ag ob_fc_sus_agua pt_hidra_agua ind_pt_agua ob_pt_hidra_agua lec_ag ob_lec_agua ac_pago_ag ob_ac_pago_agua'
  . ' num_ser_gas ob_num_ser_gas num_med_gas num_med_igual_gas ob_num_med_gas est_med_gas ob_est_med_gas dir_gas ob_dir_gas fac_gas valor_fac_gas fc_pago_gas ob_fac_gas fc_sus_gas ob_fc_sus_gas pt_gas ind_pt_gas ob_pt_gas lec_gas ob_lec_gas uso_gas ob_uso_gas est_dd_gas ob_est_dd_gas ac_pago_gas ob_ac_pago_gas'
  . ' num_nic_luz ob_num_nic_luz num_med_luz num_med_igual_luz ob_num_med_luz est_med_luz ob_est_med_luz dir_luz ob_dir_luz fac_luz valor_fac_luz fc_pago_luz ob_fac_luz fc_sus_luz ob_fc_sus_luz pt_luz ind_pt_luz ob_pt_luz lec_luz ob_lec_luz uso_luz ob_uso_luz est_dd_luz ob_est_dd_luz ac_pago_luz ob_ac_pago_luz'
  . ' servicios_adicionales cct_author_id cct_created cct_modified fecha creador id_ticket id_inmueble destinatario celular_destinatario email_destinatario contrato id_empleado id_propietario id_arrendatario id_contrato inmueble_rep inmueble sucursal registro_fotografico cesion servicios_publicos'
  . ' tiene_parqueadero tipo_parqueadero parqueaderos numero_parqueadero tiene_deposito depositos habitaciones banos email_creador celular_creador coordinador email_coordinador celular_coordinador id_estudio_aseguradora numero_solicitud tipo_negocio destinacion piso';
$tables = [
  'jet_cct_revision_entrega' => $reviewColumns,
  'jet_cct_tickets' => '_ID id_ticket tema_ayuda estado estado_administrativo id_revision_entrega estado_rev_entrega estado_rev_correctiva fecha_actualizacion id_contrato contrato id_inmueble inmueble id_inmueble_data id_sucursal propietario arrendatario direccion fecha id_empleado tipo_inmueble registro_fotografico resultado_revision_entrega',
  'jet_cct_contratos_arrendamiento' => '_ID contrato id_inmueble id_inmueble_data inmueble direccion propietario arrendatario correo_propietario celular_propietario id_propietario id_arrendatario sucursal registro_fotografico id_revision_entrega agua medidor_agua lectura_inicial_agua gas medidor_gas lectura_inicial_gas luz medidor_luz lectura_inicial_luz servicios_publicos tiene_parqueadero tipo_parqueadero parqueaderos numero_parqueadero tiene_deposito depositos fecha_linderos conjunto_lin torre_lin inmueble_lin parqueadero_lin deposito_lin tipo_inmueble',
  'jet_cct_inmuebles' => '_ID codigo id_ticket id_contrato_arrendamiento habitaciones banos agua gas luz servicios_publicos tipo_parqueadero parqueaderos numero_parqueadero depositos parqueadero deposito direccion tipo_inmueble',
  'jet_cct_sucursales' => '_ID nombre_contractual celular_contractual correo_contractual',
  'jet_cct_confi_sistema' => '_ID funcion valor',
  'jet_cct_historial_del_ticket' => '_ID cct_status cct_author_id cct_created cct_modified id_ticket fecha nombre correo celular respuesta id_revision_entrega id_empleado',
  'jet_cct_historial_del_inmueble' => '_ID cct_status cct_author_id cct_created cct_modified id_empleado id_inmueble fecha tipo_reporte observacion funcionario id_ticket',
  'jet_cct_funcionarios' => '_ID id_empleado nombre correo celular rol gestion id_cargo',
  'postmeta' => 'meta_id post_id meta_key meta_value',
  'posts' => 'ID post_type',
];
foreach ($tables as $name => $columns) {
  $cols = array_map(static fn(string $c): string => in_array($c, ['_ID', 'meta_id', 'ID'], true) ? "`{$c}` INTEGER PRIMARY KEY AUTOINCREMENT" : "`{$c}` TEXT", explode(' ', $columns));
  $pdo->exec('CREATE TABLE `wp_' . $name . '` (' . implode(', ', $cols) . ')');
}
$innerLin = serialize(['item-0' => ['norte_inmueble' => 'Vacío hacia pasillo', 'sur_inmueble' => 'Apto 5B', 'oriente_inmueble' => '', 'occidente_inmueble' => '', 'cenit_inmueble' => 'Apto 6A', 'nadir_inmueble' => 'Apto 4A']]);
$pdo->exec("INSERT INTO wp_jet_cct_tickets (_ID, id_ticket, tema_ayuda, estado, estado_administrativo, estado_rev_entrega, id_contrato, contrato, id_inmueble, inmueble, id_inmueble_data, id_sucursal, fecha, tipo_inmueble) VALUES (11106, '11106', 'Entrega de inmuebles', 'Nuevo', 'Nuevo', 'No', '796', '859', '150851', '10670', '55', '1', '1791238614', 'Apartamento')");
$stmt = $pdo->prepare("INSERT INTO wp_jet_cct_contratos_arrendamiento (_ID, contrato, id_inmueble, id_inmueble_data, inmueble, direccion, propietario, arrendatario, correo_propietario, celular_propietario, sucursal, registro_fotografico, agua, medidor_agua, gas, tiene_parqueadero, tipo_parqueadero, parqueaderos, numero_parqueadero, tiene_deposito, inmueble_lin, servicios_publicos, fecha_linderos) VALUES (796, '859', '150851', '55', '10670', 'K 22A 29A 108 AP 5A', 'Eliana Carrasquilla', 'Pedro Arrendatario', 'eliana@example.test', '3123033579', '1', 'https://drive.google.com/drive/folders/16zrd23ypbnQljUJGrsymrHAxFJ4bFCf0?usp=sharing', '523279', '014298', 'GAS-OLD', 'Si', 'Privado', '1', '2', 'No', ?, ?, '1792126800')");
$stmt->execute([$innerLin, serialize(['Agua', 'Gas'])]);
$pdo->exec("INSERT INTO wp_jet_cct_inmuebles (_ID, codigo, id_contrato_arrendamiento, habitaciones, banos) VALUES (55, '10670', '796', '2', '1')");
$pdo->exec("INSERT INTO wp_jet_cct_sucursales (_ID, nombre_contractual, celular_contractual, correo_contractual) VALUES (1, 'Coordinador Contractual', '3145965047', 'contractual@example.test')");
$pdo->exec("INSERT INTO wp_jet_cct_funcionarios (_ID, id_empleado, nombre, correo, celular) VALUES (7, '2706', 'Funcionario QA', 'qa@example.invalid', '3001112233')");
$pdo->exec("INSERT INTO wp_posts (ID, post_type) VALUES (150851, 'inmuebles')");
foreach (['habitaciones' => '3', 'banos' => '2', 'piso' => '5', 'sera-cesion' => 'No', 'tiene-parqueadero' => serialize(['No'])] as $key => $value) {
  $pdo->prepare('INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (150851, ?, ?)')->execute([$key, $value]);
}

$db = new \SCM\Core\Database($pdo, 'wp_');
\SCM\Core\App::init($db, new \SCM\Core\Auth($db), new \SCM\Core\Csrf(SCM_APP_SECRET), new \SCM\Core\Settings($db, true));
$_SESSION = ['scm_logged_in' => true, 'scm_user' => 'Funcionario QA', 'scm_user_id' => 7, 'scm_employee_id' => '2706', 'scm_last_activity' => time()];
$app = new \SCM\App\SuCasaControlServiciosInmobiliarios($db);
$call = static function (string $method, ...$args) use ($app) {
  $ref = new ReflectionMethod($app, $method);
  return $ref->invoke($app, ...$args);
};
$assert = static function (bool $ok, string $message): void {
  if (!$ok) { throw new RuntimeException($message); }
};

// Formulario de creación: arranca con lo que tiene la ficha del inmueble y el contrato.
$panel = $call('renderDeliveryReviewPanel', $call('deliveryReviewContext', 11106), 0);
if (getenv('DELIVERY_EMIT_FORM') === '1') {
  echo $panel;
  exit(0);
}
$assert(substr_count($panel, 'data-prev-step=') === 5, 'El asistente debe tener 5 pasos.');
$assert(str_contains($panel, 'name="hab_piso[1]" min="0" max="30" step="1" inputmode="numeric" required value="3"') && str_contains($panel, 'name="ban_piso[1]" min="0" max="30" step="1" inputmode="numeric" required value="2"'), 'Habitaciones y baños deben venir del inmueble registrado.');
$assert(str_contains($panel, 'name="piso" min="1" max="10" step="1" inputmode="numeric" required value="1"'), 'Piso es la cantidad de pisos (numérica) y no se toma del meta "piso" del inmueble.');
$assert(str_contains($panel, 'name="espacios[habitacion_p1][2][tipo]"') && str_contains($panel, '<option value="Principal" selected>') && str_contains($panel, '<option value="De servicio">'), 'Debe haber una ficha por habitación con el tipo en una lista.');
$assert(!str_contains($panel, 'data-delivery-list="esp-hab_ser"') && !str_contains($panel, 'data-delivery-list="esp-bano_ser"'), 'Habitación y baño de servicio se eligen como tipo, no como sección aparte.');
$assert(str_contains($panel, 'Vacío hacia pasillo') && str_contains($panel, 'value="523279"'), 'Linderos y póliza deben venir del contrato.');
$assert(!str_contains($panel, 'ac_pago') && !str_contains($panel, 'resultado'), 'Acuerdo de pago y resultado de la revisión ya no se piden.');

$space = static fn(array $contains, string $observation = '', string $type = ''): array => array_filter(['tipo' => $type, 'contiene' => $contains, 'observacion' => $observation], static fn($v) => $v !== '');
$post = [
  'piso' => '2',
  'hab_piso' => [1 => '1', 2 => '2'],
  'ban_piso' => [1 => '1', 2 => '0'],
  'tiene_parqueadero' => 'Si',
  'tipo_parqueadero' => 'Privado',
  'parqueaderos' => '1',
  'numero_parqueadero' => '14',
  'tiene_deposito' => 'No',
  'registro_fotografico' => 'https://drive.google.com/drive/folders/16zrd23ypbnQljUJGrsymrHAxFJ4bFCf0?usp=sharing',
  'fech_linderos' => '2026-10-15',
  'linderos' => [
    'inmueble_rep' => [['norte' => 'Vacío hacia pasillo', 'sur' => 'Apto 5B', 'cenit' => 'Apto 6A']],
    'conjunto' => [['norte' => '', 'sur' => '']],
    'parqueadero' => [['num' => '14', 'norte' => 'Muro', 'sur' => 'Vía interna']],
    'deposito' => [['norte' => 'No debe guardarse']],
  ],
  'espacios' => [
    'habitacion_p1' => [$space(['Paredes', 'Pisos', 'Closet'], 'Ninguna', 'Principal')],
    'habitacion_p2' => [$space(['Paredes'], 'Rayón en la puerta', 'Secundaria'), $space(['Lampara'], '', 'De servicio'), $space([])],
    'habitacion_p3' => [$space(['Paredes'], 'Piso que no existe', 'Secundaria')],
    'bano_p1' => [$space(['Ducha', 'Espejos'], '', 'Principal')],
    'cocina' => [$space(['Estufa', 'Valor que ya no está en el glosario'])],
  ],
  'servicios_publicos' => ['Agua', 'Energia'],
  'servicios' => [
    'num_pol_ag' => '523279', 'num_med_ag' => '014298', 'num_med_igual_ag' => 'Igual', 'est_med_ag' => 'Detenido por comprobación de no fuga', 'dir_ag' => 'Coincide', 'lec_ag' => '4724.29',
    'valor_fac_ag' => '$186.440', 'fc_pago_ag' => '2026-09-19', 'ob_fac_agua' => 'Factura al día',
    'num_nic_luz' => '1187772', 'num_med_luz' => '1402010480', 'num_med_igual_luz' => 'Igual', 'est_med_luz' => 'Detenido por comprobación de no fuga', 'dir_luz' => 'Coincide', 'lec_luz' => '32516.8', 'pt_luz' => 'Si',
    'num_ser_gas' => 'NO-DEBE-GUARDARSE',
  ],
  'servicios_adicionales' => ['Tiene internet', 'Algo raro'],
];

// Validaciones.
$_POST = array_replace($post, ['registro_fotografico' => 'no es un enlace']);
try { $call('deliveryReviewCreate', 11106); $assert(false, 'Debió exigir el enlace del registro fotográfico.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'registro fotográfico'), $e->getMessage()); }
$_POST = $post;
$_POST['servicios'] = array_replace($post['servicios'], ['num_pol_ag' => '']);
try { $call('deliveryReviewCreate', 11106); $assert(false, 'Debió exigir la póliza de agua.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'póliza') && str_contains($e->getMessage(), 'agua'), $e->getMessage()); }
$_POST = array_replace($post, ['linderos' => ['inmueble_rep' => [['norte' => '']]]]);
try { $call('deliveryReviewCreate', 11106); $assert(false, 'Debió exigir los linderos del inmueble.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'linderos'), $e->getMessage()); }
$_POST = array_replace($post, ['tipo_parqueadero' => '']);
try { $call('deliveryReviewCreate', 11106); $assert(false, 'Debió exigir los datos del parqueadero.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'parqueadero'), $e->getMessage()); }
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_revision_entrega') === 0, 'Una validación fallida no debe guardar nada.');

// Crear.
$_POST = $post;
$created = $call('deliveryReviewCreate', 11106);
$reviewId = (int) $created['review_id'];
$review = $db->getRow('SELECT * FROM wp_jet_cct_revision_entrega WHERE _ID = ?', [$reviewId]);
$assert($reviewId > 0 && $review['habitaciones'] === '3' && $review['banos'] === '1' && $review['piso'] === '2', 'No guardó habitaciones y baños por piso ni la cantidad de pisos.');
$assert($review['cct_author_id'] === '2706' && $review['id_empleado'] === '2706' && $review['coordinador'] === 'Coordinador Contractual', 'cct_author_id debe ser el id_empleado real y el coordinador el de la sucursal.');
$assert($review['destinatario'] === 'Eliana Carrasquilla' && $review['cesion'] === 'No' && $review['tip_inm'] === 'Apartamento', 'Faltan datos del propietario o del inmueble.');
$rooms = unserialize($review['habitacion']);
$assert(count($rooms) === 2 && $rooms['item-0']['tipo_habitacion'] === 'Principal' && $rooms['item-0']['piso_habitacion'] === '1' && $rooms['item-0']['contiene_habitacion'] === ['Paredes', 'Pisos', 'Closet'] && $rooms['item-0']['ninguna_hab'] === ['Ninguna'] && $rooms['item-1']['observacion_habitacion'] === 'Rayón en la puerta' && $rooms['item-1']['piso_habitacion'] === '2', 'Las habitaciones no se guardaron con el formato de JetEngine y su piso.');
$serviceRooms = unserialize($review['hab_ser']);
$assert(count($serviceRooms) === 1 && $serviceRooms['item-0']['contiene_hab_servicio'] === ['Lampara'] && $serviceRooms['item-0']['piso_hab_servicio'] === '2' && $review['bano_ser'] === '', 'La habitación de servicio debe guardarse en hab_ser.');
$assert(!str_contains($review['habitacion'], 'Piso que no existe'), 'Un piso por encima de la cantidad de pisos no se guarda.');
$assert(in_array('Valor que ya no está en el glosario', unserialize($review['cocina'])['item-0']['contiene_cocina'], true), 'Al editar no se deben perder opciones antiguas.');
$assert($review['conjunto'] === '' && $review['deposito'] === '' && unserialize($review['inmueble_rep'])['item-0']['cenit_inmueble'] === 'Apto 6A' && unserialize($review['parqueadero'])['item-0']['num_parqueadero'] === '14', 'Los linderos no se guardaron bien.');
$assert(unserialize($review['servicios_publicos']) === ['Agua', 'Energia'] && $review['num_ser_gas'] === '' && $review['ob_num_ser_gas'] === '', 'Un servicio inactivo no debe guardar datos.');
$assert($review['valor_fac_ag'] === '186440' && $review['fc_pago_ag'] === '2026-09-19' && $review['ob_fac_agua'] === 'Factura al día' && $review['ob_lec_agua'] === 'Ninguna', 'Los datos del agua no se guardaron.');
$assert(($review['ac_pago_ag'] ?? '') === '' && ($review['resultado'] ?? '') === '', 'Acuerdo de pago y resultado no se diligencian.');
$assert(unserialize($review['servicios_adicionales']) === ['Tiene internet'], 'Servicios adicionales fuera del glosario no se guardan.');
$assert((int) $review['fech_linderos'] === strtotime('2026-10-15 00:00:00'), 'La fecha de linderos se guarda como timestamp.');

$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 11106');
$assert($ticket['id_revision_entrega'] === (string) $reviewId && $ticket['estado_rev_entrega'] === 'Si' && $ticket['estado'] === 'En proceso' && $ticket['estado_administrativo'] === 'Inspeccionado', 'El caso no se actualizó como en JetForm.');
$assert(($ticket['resultado_revision_entrega'] ?? '') === '', 'El caso ya no recibe resultado de la revisión.');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 796');
$assert($contract['id_revision_entrega'] === (string) $reviewId && $contract['lectura_inicial_agua'] === '4724.29' && $contract['luz'] === '1187772' && $contract['gas'] === 'GAS-OLD' && $contract['numero_parqueadero'] === '14' && $contract['inmueble_lin'] === $review['inmueble_rep'], 'El contrato no se sincronizó (o se borró un servicio inactivo).');
$property = $db->getRow('SELECT * FROM wp_jet_cct_inmuebles WHERE _ID = 55');
$assert($property['habitaciones'] === '3' && $property['banos'] === '1' && $property['parqueadero'] === 'Si', 'El inmueble (CCT) no se sincronizó.');
$meta = static fn(string $key): string => (string) $db->getVar('SELECT meta_value FROM wp_postmeta WHERE post_id = 150851 AND meta_key = ?', [$key]);
$assert($meta('piso') === '5' && $meta('habitaciones') === '3' && $meta('id_revision_entrega') === (string) $reviewId && $meta('tiene-parqueadero') === serialize(['Si']) && $meta('servicios-publicos') === serialize(['Agua', 'Energia']), 'La ficha del inmueble en WordPress no se sincronizó.');
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_revision_entrega = ?', [(string) $reviewId]) === 1, 'Falta el historial del caso.');
$assert((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_inmueble WHERE tipo_reporte = 'Revision de entrega'") === 1, 'Falta el historial del inmueble.');
try { $call('deliveryReviewCreate', 11106); $assert(false, 'No debe permitir una segunda revisión en el caso.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'ya tiene'), $e->getMessage()); }

// Panel: lista y edición.
$context = $call('deliveryReviewContext', 11106);
$panel = $call('renderDeliveryReviewPanel', $context, 0);
$assert(str_contains($panel, 'data-delivery-edit-review="' . $reviewId . '"') && str_contains($panel, 'data-delivery-delete-review="' . $reviewId . '"') && str_contains($panel, 'revision-entrega.php?numero=' . $reviewId), 'El panel no lista la revisión.');
$editForm = $call('renderDeliveryReviewPanel', $context, $reviewId);
$assert(str_contains($editForm, 'data-delivery-review-edit') && str_contains($editForm, 'Rayón en la puerta') && str_contains($editForm, 'name="espacios[habitacion_p2][1][tipo]"') && str_contains($editForm, 'name="hab_piso[2]" min="0" max="30" step="1" inputmode="numeric" required value="2"') && !str_contains($editForm, 'name="espacios[habitacion_p2][2][tipo]"'), 'El formulario de edición no carga lo guardado.');

$_POST = $post;
$_POST['ban_piso'] = [1 => '1', 2 => '1'];
$_POST['espacios']['bano_p2'] = [$space(['Ducha'], '', 'Social')];
$_POST['servicios_publicos'] = ['Agua'];
$_POST['review_id'] = (string) $reviewId;
$call('deliveryReviewUpdate', 11106, $reviewId);
$review = $db->getRow('SELECT * FROM wp_jet_cct_revision_entrega WHERE _ID = ?', [$reviewId]);
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 796');
$assert($review['banos'] === '2' && unserialize($review['bano'])['item-1']['piso_bano'] === '2' && $review['num_nic_luz'] === '' && $contract['luz'] === '1187772', 'La edición no actualizó o borró datos del contrato.');
$_POST['piso'] = 'dos';
try { $call('deliveryReviewUpdate', 11106, $reviewId); $assert(false, 'La cantidad de pisos debe ser numérica.'); } catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'cantidad de pisos'), $e->getMessage()); }
$assert((int) $db->getVar('SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE id_revision_entrega = ?', [(string) $reviewId]) === 2, 'La edición debe dejar historial.');

// Informe público nativo.
$public = (new \SCM\Modules\DeliveryReview\DeliveryReviewPublicView($db))->render($reviewId);
$content = $public['content'];
$assert($public['status'] === 200 && str_contains($content, 'Revisión antes de la ocupación #' . $reviewId) && str_contains($content, 'Habitación · Principal · Piso 1') && str_contains($content, 'Habitación 1 · Secundaria · Piso 2') && str_contains($content, 'Habitación 2 · De servicio · Piso 2') && str_contains($content, 'Pisos') && str_contains($content, 'Rayón en la puerta'), 'El informe público no muestra los espacios.');
$assert(str_contains($content, 'Linderos') && str_contains($content, 'Apto 6A') && str_contains($content, '$186.440') && str_contains($content, 'Internet'), 'El informe no muestra linderos o servicios.');
$assert(str_contains($content, 'embeddedfolderview?id=16zrd23ypbnQljUJGrsymrHAxFJ4bFCf0') && str_contains($content, 'Realizado por') && !str_contains($content, 'Sucursal') && !str_contains($content, 'target="_blank"'), 'El informe debe abrir las fotos en la misma página, cerrar con "Realizado por" y no mostrar sucursal.');
$url = \SCM\App\SuCasaControlServiciosInmobiliarios::signedDeliveryReviewPublicUrl($reviewId);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$assert(str_starts_with($url, 'https://panel.test/revision-entrega.php?numero=' . $reviewId . '&expires='), 'URL pública inesperada.');
$assert(\SCM\App\SuCasaControlServiciosInmobiliarios::deliveryReviewPublicSignatureValid($reviewId, (int) $q['expires'], (string) $q['sig']), 'La firma pública no valida.');
$assert(!\SCM\App\SuCasaControlServiciosInmobiliarios::deliveryReviewPublicSignatureValid($reviewId + 1, (int) $q['expires'], (string) $q['sig']), 'La firma no debe servir para otro número.');
$assert(!\SCM\App\SuCasaControlServiciosInmobiliarios::preventiveReviewPublicSignatureValid($reviewId, (int) $q['expires'], (string) $q['sig']), 'La firma de entrega no debe abrir la preventiva.');

// Enlaces del historial, línea de tiempo y contrato apuntan a la vista nativa.
$assert(\SCM\Support\HistoryLinkMap::idButtons()['id_revision_entrega']['base'] === 'https://panel.test/revision-entrega.php?numero=', 'El historial sigue con el enlace de WordPress.');
$timeline = array_column(\SCM\Support\TimelineMaps\EntregaTimelineMap::steps(), 'link_url', 'key');
$assert($timeline['revision_antes_entrega'] === 'https://panel.test/revision-entrega.php?numero={id_revision_entrega}', 'La línea de tiempo sigue con el enlace de WordPress.');

// Eliminar.
$call('deliveryReviewDelete', 11106, $reviewId);
$ticket = $db->getRow('SELECT * FROM wp_jet_cct_tickets WHERE _ID = 11106');
$contract = $db->getRow('SELECT * FROM wp_jet_cct_contratos_arrendamiento WHERE _ID = 796');
$assert(!$db->getRow('SELECT * FROM wp_jet_cct_revision_entrega WHERE _ID = ?', [$reviewId]), 'No eliminó la revisión.');
$assert($ticket['id_revision_entrega'] === '' && $ticket['estado_rev_entrega'] === 'No' && $contract['id_revision_entrega'] === '' && $meta('id_revision_entrega') === '', 'Eliminar debe desvincular la revisión del caso, contrato e inmueble.');
$assert((int) $db->getVar("SELECT COUNT(*) FROM wp_jet_cct_historial_del_ticket WHERE respuesta LIKE '%Se eliminó la revisión antes de la ocupación%'") === 1, 'Eliminar debe dejar trazabilidad.');

echo "delivery-review-check: OK\n";
