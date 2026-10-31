<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Core\Database;
use SCM\Modules\ClosingSheets\ClosingSheetDocuments;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\HtmlPdfRenderer;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;
use SCM\Support\StoredFileService;

/**
 * Reemplaza el formulario JetForm "Crear contrato de arrendamiento": convierte un contrato "No asignado"
 * en contrato real a partir de su hoja de cierre y actualiza todos los registros que el formulario tocaba.
 */
final class LeaseContractCreationService
{
  public const INTERNAL_ACTION = 'contrato_arrendamiento_creado';
  public const TEMPLATE_TENANT = 'scm_notificacion_ingreso_arrendatario_v1';
  public const TEMPLATE_BUILDING = 'scm_presentacion_inquilino_copropiedad_v1';

  /** Glosarios JetEngine que usa el formulario original. */
  private const GLOSSARIES = [
    'tipo_inmueble' => 783, 'destinacion_inmueble' => 785, 'gestion_inmueble' => 784, 'servicios_publicos' => 1003,
    'si_no' => 175, 'tipo_parqueadero' => 1011, 'tipo_mascota' => 1016, 'tipo_vehiculo' => 992,
    'parentesco' => 991, 'ocupacion' => 990, 'acuerdos_precontractuales' => 853,
  ];
  private const CODEBTOR_OPTIONS = ['Ninguno', '1', '2', '3', '4'];
  private const SCHEDULES = ['horario_mudanza_inicio_1', 'horario_mudanza_final_1', 'horario_mudanza_inicio_2', 'horario_mudanza_final_2', 'horario_mudanza_inicio_sabado', 'horario_mudanza_final_sabado', 'horario_zonas_inicio', 'horario_zonas_final', 'horario_gym_inicio', 'horario_gym_final', 'horario_piscina_inicio', 'horario_piscina_final', 'horario_canchas_inicio', 'horario_canchas_final'];
  private const AGREEMENTS = ['Propietario' => 'acuerdos_precontractuales_pro', 'Arrendatario' => 'acuerdos_precontractuales_arre', 'Copropiedad' => 'acuerdos_precontractuales_co', 'Inmobiliaria' => 'acuerdos_precontractuales_inm', 'Externo' => 'acuerdos_precontractuales_ext'];

  private SchemaInspector $schema;
  private LeaseAnnexesService $annexes;

  public function __construct(private Database $db)
  {
    $this->schema = new SchemaInspector($db);
    $this->annexes = new LeaseAnnexesService($db);
  }

  public static function isUnassigned(array $contract): bool
  {
    return strtolower(trim((string) ($contract['contrato'] ?? ''))) === 'no asignado';
  }

  /** @return array<string,string[]> */
  public function options(): array
  {
    $options = [];
    foreach (self::GLOSSARIES as $key => $id) {
      $options[$key] = $this->glossary($id);
    }
    $zones = $this->db->table('jet_cct_zonas_sociales');
    $options['zonas_sociales'] = $this->schema->tableExists($zones)
      ? array_values(array_filter(array_map('trim', $this->db->getCol("SELECT `valor` FROM `{$zones}` WHERE COALESCE(`cct_status`, 'publish') = 'publish' ORDER BY `valor` ASC"))))
      : [];
    return $options;
  }

  /** @param array<string,mixed> $contract @return array<string,mixed> valores iniciales del formulario */
  public function prefill(array $contract): array
  {
    $closing = $this->closing($contract);
    $owner = $this->row('jet_cct_propietarios', $closing['id_propietario'] ?? $contract['id_propietario'] ?? '');
    $tenant = $this->row('jet_cct_arrendatarios', $closing['id_arrendatario'] ?? '');
    $building = $this->row('jet_cct_copropiedades', $closing['id_copropiedad'] ?? '');
    $pick = static function (array ...$sources): \Closure {
      return static function (string ...$keys) use ($sources): string {
        foreach ($sources as $source) {
          foreach ($keys as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '' && strtolower($value) !== 'no asignado') {
              return $value;
            }
          }
        }
        return '';
      };
    };
    $fromClosing = $pick($closing, $contract);
    $values = [
      'contrato' => '',
      'inmueble' => $pick($contract)('inmueble'),
      'direccion' => $fromClosing('direccion'),
      'registro_fotografico' => $pick($contract)('registro_fotografico'),
      'incremento_pactado' => $pick($closing, $contract)('porcentaje_incremento', 'porcentaje_incremento_canon'),
      'propietario' => $pick($owner, $closing, $contract)('nombre', 'propietario'),
      'documento_propietario' => $pick($owner, $closing, $contract)('documento', 'documento_propietario'),
      'correo_propietario' => $pick($owner, $closing, $contract)('correo', 'correo_propietario'),
      'celular_propietario' => $pick($owner, $closing, $contract)('celular', 'celular_propietario'),
      'arrendatario' => $pick($tenant, $closing)('nombre', 'arrendatario'),
      'documento_arrendatario' => $pick($tenant, $closing)('documento', 'documento_arrendatario'),
      'correo_arrendatario' => $pick($tenant, $closing)('correo', 'correo_arrendatario'),
      'celular_arrendatario' => $pick($tenant, $closing)('celular', 'celular_arrendatario'),
      'copropiedad' => $pick($building, $closing)('copropiedad'),
      'nit_copropiedad' => $pick($building, $closing)('nit', 'nit_copropiedad'),
      'administrador' => $pick($building, $closing)('administrador'),
      'correo_copropiedad' => $pick($building, $closing)('correo', 'correo_copropiedad'),
      'celular_copropiedad' => $pick($building, $closing)('contacto', 'celular_copropiedad'),
      'vehiculos' => $this->list($closing['vehiculo'] ?? ''),
      '_closing_id' => (string) ($closing['_ID'] ?? ''),
      '_closing_state' => (string) ($closing['estado'] ?? ''),
    ];
    foreach (['tipo_inmueble', 'destinacion_inmueble', 'gestion_inmueble', 'tasa_administracion', 'meses_contrato', 'valor_canon', 'valor_administracion', 'derechos_inmobiliarios', 'dias_gracia', 'luz', 'agua', 'gas', 'propiedad_horizontal', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero', 'tiene_deposito', 'depositos', 'tiene_mascotas', 'tiene_vehiculo', 'convive_personas', 'num_codeudores'] as $field) {
      $values[$field] = $fromClosing($field);
    }
    foreach (self::SCHEDULES as $field) {
      $values[$field] = $fromClosing($field);
    }
    foreach (array_values(self::AGREEMENTS) as $field) {
      $values[$field] = $fromClosing($field);
    }
    foreach (['servicios_publicos', 'zonas_sociales', 'acuerdos_precontractuales'] as $field) {
      $values[$field] = $this->list($closing[$field] ?? $contract[$field] ?? '');
    }
    $values['mascotas'] = $this->list($closing['mascotas'] ?? '');
    $values['personas'] = $this->list($closing['personas'] ?? '');
    for ($i = 1; $i <= 4; $i++) {
      foreach (['', 'documento_', 'correo_', 'celular_', 'dir_'] as $prefix) {
        $values[$prefix . 'codeudor_' . $i] = $fromClosing($prefix . 'codeudor_' . $i);
      }
    }
    return $values;
  }

  /**
   * @param array<string,mixed> $input
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   * @return array<string,mixed>
   */
  public function create(int $contractId, array $input, array $sender): array
  {
    $contract = $this->annexes->contract($contractId);
    if (!self::isUnassigned($contract)) {
      throw new \DomainException('Este contrato ya tiene número asignado; no se puede crear de nuevo.');
    }
    $closing = $this->closing($contract);
    $data = $this->validate($input, $contractId);
    $now = time();
    $nowSql = date('Y-m-d H:i:s', $now);
    $ph = $data['propiedad_horizontal'] === 'Si';

    $ids = [
      'id_propietario' => $this->firstId([$closing['id_propietario'] ?? '', $contract['id_propietario'] ?? '']),
      'id_arrendatario' => $this->firstId([$closing['id_arrendatario'] ?? '', $contract['id_arrendatario'] ?? '']),
      'id_copropiedad' => $ph ? $this->firstId([$closing['id_copropiedad'] ?? '', $contract['id_copropiedad'] ?? '']) : '',
      'id_contrato_mandato' => $this->firstId([$closing['id_contrato'] ?? '', $contract['id_contrato_mandato'] ?? '']),
      'id_cierre' => $this->firstId([$closing['_ID'] ?? '', $contract['id_cierre'] ?? '']),
      'id_pph' => $this->firstId([$closing['id_pph'] ?? '', $contract['id_pph'] ?? '']),
      'id_ticket_cliente' => $this->firstId([$closing['id_ticket'] ?? '', $contract['id_ticket_cliente'] ?? '']),
      'id_estudio_aseguradora' => $this->firstId([$contract['id_estudio_aseguradora'] ?? '', $closing['id_estudio_aseguradora'] ?? '']),
    ];
    $pph = $this->row('jet_cct_club_pph', $ids['id_pph']);
    $study = $this->row('jet_cct_estudios_aseguradoras', $ids['id_estudio_aseguradora']);
    $ticket = $this->row('jet_cct_tickets', $ids['id_ticket_cliente']);
    $staff = $this->annexes->staff();
    $context = array_merge($contract, $data, $ids, [
      'fecha' => $now, 'ciudad' => 'Cartagena de Indias',
      'realizado_por' => $sender['name'], 'realizado_por_cargo' => $sender['cargo'], 'realizado_por_telefono' => $sender['phone'],
      'nombre_contractual' => $staff['contractual']['nombre'] ?? '', 'celular_contractual' => $staff['contractual']['celular'] ?? '', 'correo_contractual' => $staff['contractual']['correo'] ?? '',
      'nombre_propietarios' => $staff['propietarios']['nombre'] ?? '', 'celular_propietarios' => $staff['propietarios']['celular'] ?? '', 'correo_propietarios' => $staff['propietarios']['correo'] ?? '',
      'nombre_arrendatarios' => $staff['arrendatarios']['nombre'] ?? '', 'celular_arrendatarios' => $staff['arrendatarios']['celular'] ?? '', 'correo_arrendatarios' => $staff['arrendatarios']['correo'] ?? '',
      'representante_legal' => $staff['legal']['nombre'] ?? '', 'celular_legal' => $staff['legal']['celular'] ?? '', 'correo_legal' => $staff['legal']['correo'] ?? '',
      'cargo_legal' => $staff['legal']['cargo'] ?? '', 'firma_legal' => $staff['legal']['firma'] ?? '',
    ]);
    if ($ph && $context['representante_legal'] === '') {
      throw new \DomainException('No hay un funcionario activo con el cargo 14 (representante legal) para firmar las actas de la copropiedad.');
    }

    $documents = [];
    try {
      if ($ph) {
        $documents['notificacion'] = $this->pdf(LeaseContractActs::tenantNotice($context, true), 'Acta de notificacion de ingreso - ' . $data['arrendatario']);
        $documents['administracion'] = $this->pdf(LeaseContractActs::buildingLetter($context, true), 'Carta de presentacion del inquilino - ' . $data['copropiedad']);
      }
      $actaAdministracion = $documents['administracion']['url'] ?? '';
      $actaNotificacion = $documents['notificacion']['url'] ?? '';

      $pdo = $this->db->pdo();
      $pdo->beginTransaction();
      try {
        $locked = $this->db->getRow('SELECT `contrato` FROM `' . $this->db->table('jet_cct_contratos_arrendamiento') . '` WHERE `_ID` = ? FOR UPDATE', [$contractId]);
        if (!is_array($locked) || !self::isUnassigned($locked)) {
          throw new \DomainException('El contrato cambió mientras lo creabas. Actualiza el listado.');
        }
        $serialize = static fn(array $items): string => $items !== [] ? serialize(array_values($items)) : '';
        $contractData = [
          'contrato' => $data['contrato'], 'inmueble' => $data['inmueble'], 'direccion' => $data['direccion'], 'registro_fotografico' => $data['registro_fotografico'],
          'fecha' => $now, 'estado' => 'Por entregar', 'tipo' => 'Nuevo', 'tuvo_revision' => 'No', 'tuvo_preventiva' => 'No', 'se_envio' => 'No',
          'tipo_inmueble' => $data['tipo_inmueble'], 'destinacion_inmueble' => $data['destinacion_inmueble'], 'gestion_inmueble' => $data['gestion_inmueble'],
          'tasa_administracion' => $data['tasa_administracion'], 'porcentaje_incremento_canon' => $data['incremento_pactado'], 'meses_contrato' => $data['meses_contrato'],
          'valor_canon' => $data['valor_canon'], 'valor_administracion' => $data['valor_administracion'], 'derechos_inmobiliarios' => $data['derechos_inmobiliarios'], 'dias_gracia' => $data['dias_gracia'],
          'servicios_publicos' => $serialize($data['servicios_publicos']), 'luz' => $data['luz'], 'agua' => $data['agua'], 'gas' => $data['gas'],
          'propiedad_horizontal' => $data['propiedad_horizontal'], 'copropiedad' => $data['copropiedad'], 'nit_copropiedad' => $data['nit_copropiedad'], 'administrador' => $data['administrador'],
          'correo_copropiedad' => $data['correo_copropiedad'], 'celular_copropiedad' => $data['celular_copropiedad'],
          'tiene_parqueadero' => $data['tiene_parqueadero'], 'tipo_parqueadero' => $data['tipo_parqueadero'], 'parqueaderos' => $data['parqueaderos'], 'numero_parqueadero' => $data['numero_parqueadero'],
          'tiene_deposito' => $data['tiene_deposito'], 'depositos' => $data['depositos'],
          'num_codeudores' => $data['num_codeudores'], 'acuerdos_precontractuales' => $serialize($data['acuerdos_precontractuales']),
          'acta_administracion' => $actaAdministracion, 'acta_notificacion_arrendatario' => $actaNotificacion,
          'nombre_pph' => (string) ($pph['nombre'] ?? ''), 'tarjeta_pph' => (string) ($pph['tarjeta_bienvenida'] ?? ''), 'bandera' => $ids['id_pph'] !== '' ? 'No' : (string) ($contract['bandera'] ?? ''),
          'medio' => trim((string) ($ticket['medio'] ?? $contract['medio'] ?? '')),
          'numero_solicitud' => trim((string) ($study['num_solicitud'] ?? $contract['numero_solicitud'] ?? '')), 'aseguradora' => trim((string) ($study['aseguradora'] ?? $contract['aseguradora'] ?? '')),
          'cct_modified' => $nowSql,
        ] + $ids;
        foreach (array_values(self::AGREEMENTS) as $field) {
          $contractData[$field] = $data[$field];
        }
        foreach (['propietario', 'arrendatario'] as $role) {
          foreach (['', 'documento_', 'correo_', 'celular_'] as $prefix) {
            $contractData[$prefix . $role] = $data[$prefix . $role];
          }
        }
        for ($i = 1; $i <= 4; $i++) {
          foreach (['', 'documento_', 'correo_', 'celular_', 'dir_'] as $prefix) {
            $contractData[$prefix . 'codeudor_' . $i] = $i <= $data['codebtors'] ? $data[$prefix . 'codeudor_' . $i] : '';
          }
        }
        // Autoría original del contrato: solo se completa si estaba vacía.
        foreach (['id_empleado' => $closing['id_empleado'] ?? '', 'realizado_por' => $closing['creador'] ?? '', 'creador' => $context['nombre_contractual'] ?: 'Administrador'] as $field => $value) {
          if (trim((string) ($contract[$field] ?? '')) === '' && trim((string) $value) !== '') {
            $contractData[$field] = (string) $value;
          }
        }
        // Carpeta de documentos del negocio (los de la hoja de cierre quedan en ella).
        $this->update('jet_cct_contratos_arrendamiento', $contractId, $contractData + [ClosingSheetDocuments::LINK_COLUMN => ClosingSheetDocuments::folderUrl($contractId)]);

        $parties = [
          'id_propietario' => $ids['id_propietario'], 'id_arrendatario' => $ids['id_arrendatario'], 'contrato' => $data['contrato'], 'inmueble' => $data['inmueble'],
          'registro_fotografico' => $data['registro_fotografico'], 'propietario' => $data['propietario'], 'correo_propietario' => $data['correo_propietario'], 'celular_propietario' => $data['celular_propietario'],
          'arrendatario' => $data['arrendatario'], 'correo_arrendatario' => $data['correo_arrendatario'], 'celular_arrendatario' => $data['celular_arrendatario'],
        ];
        $actaAdministracionId = 0;
        if ($ph) {
          $actaAdministracionId = $this->insert('jet_cct_actas_administracion', $sender, $nowSql, [
            'fecha' => $now, 'estado' => 'Enviado', 'contrato' => $data['contrato'], 'inmueble' => $data['inmueble'], 'direccion' => $data['direccion'], 'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''),
            'arrendatario' => $data['arrendatario'], 'celular_arrendatario' => $data['celular_arrendatario'], 'correo_arrendatario' => $data['correo_arrendatario'],
            'copropiedad' => $data['copropiedad'], 'nit_copropiedad' => $data['nit_copropiedad'], 'administrador' => $data['administrador'], 'correo_copropiedad' => $data['correo_copropiedad'], 'celular_copropiedad' => $data['celular_copropiedad'],
            'nombre_rp' => $context['representante_legal'], 'celular_rp' => $context['celular_legal'], 'correo_rp' => $context['correo_legal'],
            'nombre_contractual' => $context['nombre_contractual'], 'celular_contractual' => $context['celular_contractual'], 'correo_contractual' => $context['correo_contractual'],
            'nombre_arrendatarios' => $context['nombre_arrendatarios'], 'celular_arrendatarios' => $context['celular_arrendatarios'], 'correo_arrendatarios' => $context['correo_arrendatarios'],
            'nombre_propietarios' => $context['nombre_propietarios'], 'celular_propietarios' => $context['celular_propietarios'], 'correo_propietarios' => $context['correo_propietarios'],
            'sucursal' => (string) ($contract['sucursal'] ?? ''), 'tiene_mascotas' => $data['tiene_mascotas'], 'mascotas' => $serialize($data['mascotas']),
            'tiene_vehiculo' => $data['tiene_vehiculo'], 'vehiculo' => $serialize($data['vehiculos']), 'convive_personas' => $data['convive_personas'], 'personas' => $serialize($data['personas']),
            'id_contrato_arrendamiento' => (string) $contractId, 'id_contrato_mandato' => $ids['id_contrato_mandato'], 'id_cierre' => $ids['id_cierre'], 'id_copropiedad' => $ids['id_copropiedad'],
            'zonas_sociales' => $serialize($data['zonas_sociales']),
            'tiene_parqueadero' => $data['tiene_parqueadero'], 'tipo_parqueadero' => $data['tipo_parqueadero'], 'parqueaderos' => $data['parqueaderos'], 'numero_parqueadero' => $data['numero_parqueadero'],
            'tiene_deposito' => $data['tiene_deposito'], 'depositos' => $data['depositos'], 'acta_administracion' => $actaAdministracion, 'acta_notificacion' => $actaNotificacion,
            'tipo_inmueble' => $data['tipo_inmueble'], 'destinacion' => $data['destinacion_inmueble'], 'tipo_negocio' => $data['gestion_inmueble'],
          ] + array_intersect_key($data, array_flip(self::SCHEDULES)));
        }
        if ($ids['id_cierre'] !== '') {
          $this->update('jet_cct_cierres', (int) $ids['id_cierre'], ['estado' => 'Realizada', 'nombre_pph' => $contractData['nombre_pph'], 'tarjeta_pph' => $contractData['tarjeta_pph'],
            'acuerdos_precontractuales' => $contractData['acuerdos_precontractuales'], 'id_estudio_aseguradora' => $ids['id_estudio_aseguradora'],
            'numero_solicitud' => $contractData['numero_solicitud'], 'aseguradora' => $contractData['aseguradora'], 'cct_modified' => $nowSql] + array_intersect_key($data, array_flip(array_values(self::AGREEMENTS))));
        }
        if ($ids['id_contrato_mandato'] !== '') {
          $this->update('jet_cct_contrato_mandato', (int) $ids['id_contrato_mandato'], ['estado_hoja_cierre' => 'Realizada', 'cct_modified' => $nowSql]);
        }
        foreach (['id_ticket_entrega', 'id_ticket_danos_entrega'] as $field) {
          if (ctype_digit(trim((string) ($contract[$field] ?? '')))) {
            $this->update('jet_cct_tickets', (int) $contract[$field], $parties + ['direccion' => $data['direccion'], 'acta_administracion_copropiedad' => $actaAdministracion, 'acta_administracion_arrendatario' => $actaNotificacion, 'id_cierre' => $ids['id_cierre']]);
          }
        }
        if (ctype_digit(trim((string) ($contract['id_revision_entrega'] ?? '')))) {
          $this->update('jet_cct_revision_entrega', (int) $contract['id_revision_entrega'], [
            'id_propietario' => $ids['id_propietario'], 'id_arrendatario' => $ids['id_arrendatario'], 'destinatario' => $data['propietario'], 'email_destinatario' => $data['correo_propietario'],
            'celular_destinatario' => $data['celular_propietario'], 'contrato' => $data['contrato'], 'inmueble' => $data['inmueble'], 'registro_fotografico' => $data['registro_fotografico'],
          ]);
        }
        if (ctype_digit(trim((string) ($contract['id_inventario'] ?? '')))) {
          $this->update('jet_cct_inventario', (int) $contract['id_inventario'], $parties + ['cedula_arrendatario' => $data['documento_arrendatario']]);
        }
        for ($i = 1; $i <= $data['codebtors']; $i++) {
          $this->insert('jet_cct_codeudores', $sender, $nowSql, [
            'id_contrato' => (string) $contractId, 'sucursal' => (string) ($contract['sucursal'] ?? ''), 'nombre' => $data['codeudor_' . $i], 'documento' => $data['documento_codeudor_' . $i],
            'correo' => $data['correo_codeudor_' . $i], 'celular' => $data['celular_codeudor_' . $i], 'direccion' => $data['dir_codeudor_' . $i],
          ]);
        }
        $property = (int) ($contract['id_inmueble'] ?? 0);
        $this->relate(864, $ids['id_propietario'], (string) $contractId);
        $this->relate(865, $ids['id_arrendatario'], (string) $contractId);
        $this->relate(658, $ids['id_propietario'], (string) $property);
        $this->relate(863, $ids['id_arrendatario'], (string) $property);
        $this->propertyMeta($property, [
          'id_propietario' => $ids['id_propietario'], 'contrato' => $data['contrato'], 'inmueble' => $data['inmueble'], 'propietario' => $data['propietario'],
          'documento-propietario' => $data['documento_propietario'], 'email-propietario' => $data['correo_propietario'], 'celular-propietario' => $data['celular_propietario'],
          'arrendatario' => $data['arrendatario'], 'documento-arrendatario' => $data['documento_arrendatario'], 'email-arrendatario' => $data['correo_arrendatario'], 'celular-arrendatario' => $data['celular_arrendatario'],
          'id_contrato_arrendamiento' => (string) $contractId, 'id_arrendatario' => $ids['id_arrendatario'], 'servicios-publicos' => $contractData['servicios_publicos'],
          'luz' => $data['luz'], 'agua' => $data['agua'], 'gas' => $data['gas'], 'tiene-parqueadero' => $data['tiene_parqueadero'], 'tipo-parqueadero' => $data['tipo_parqueadero'],
          'parqueaderos' => $data['parqueaderos'], 'parqueadero' => $data['numero_parqueadero'], 'tiene-deposito' => $data['tiene_deposito'], 'depositos' => $data['depositos'],
        ]);
        $summary = 'Se creó el contrato de arrendamiento #' . $data['contrato'] . ' (inmueble ' . $data['inmueble'] . ') para el arrendatario ' . $data['arrendatario'] . '.'
          . ($ids['id_cierre'] !== '' ? ' Hoja de cierre #' . $ids['id_cierre'] . ' marcada como realizada.' : '')
          . ($ph ? ' Se generaron el acta de notificación de ingreso y la carta de presentación para ' . $data['copropiedad'] . '.' : '');
        $this->history($contract, $data, $summary, $sender, $now, $nowSql);
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

    $queued = $this->notify($context, $documents, $ph, $actaAdministracionId, $contractId, $now);
    return ['contrato' => $data['contrato'], 'queued' => $queued, 'documents' => array_map(static fn(array $d): string => $d['url'], $documents)];
  }

  /** @param array<string,mixed> $input @return array<string,mixed> */
  private function validate(array $input, int $contractId): array
  {
    $text = static fn(string $key): string => trim((string) ($input[$key] ?? ''));
    $options = $this->options();
    $oneOf = function (string $key, string $optionKey, string $label, bool $required = true) use ($text, $options): string {
      $value = $text($key);
      if ($value === '' && !$required) {
        return '';
      }
      if (!in_array($value, $options[$optionKey], true)) {
        throw new \DomainException('Selecciona ' . $label . '.');
      }
      return $value;
    };
    $required = static function (string $value, string $label): string {
      if ($value === '') {
        throw new \DomainException('Completa ' . $label . '.');
      }
      return $value;
    };
    $email = static function (string $value, string $label): string {
      if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new \DomainException('El correo ' . $label . ' no es válido.');
      }
      return strtolower($value);
    };
    $list = static fn(string $key, array $allowed): array => array_values(array_intersect(array_map('strval', (array) ($input[$key] ?? [])), $allowed));

    $data = [
      'contrato' => $required($text('contrato'), 'el número de contrato'),
      'inmueble' => $required($text('inmueble'), 'el número de inmueble'),
      'direccion' => $required($text('direccion'), 'la dirección'),
      'registro_fotografico' => $text('registro_fotografico'),
      'tipo_inmueble' => $oneOf('tipo_inmueble', 'tipo_inmueble', 'el tipo de inmueble'),
      'destinacion_inmueble' => $oneOf('destinacion_inmueble', 'destinacion_inmueble', 'la destinación'),
      'gestion_inmueble' => $oneOf('gestion_inmueble', 'gestion_inmueble', 'la gestión'),
      'servicios_publicos' => $list('servicios_publicos', $options['servicios_publicos']),
      'propiedad_horizontal' => $oneOf('propiedad_horizontal', 'si_no', 'si pertenece a propiedad horizontal'),
      'tiene_parqueadero' => $oneOf('tiene_parqueadero', 'si_no', 'si tiene parqueadero'),
      'tiene_deposito' => $oneOf('tiene_deposito', 'si_no', 'si tiene depósito'),
      'acuerdos_precontractuales' => $list('acuerdos_precontractuales', $options['acuerdos_precontractuales']),
    ];
    if (strtolower($data['contrato']) === 'no asignado' || preg_match('/^[0-9A-Za-z-]+$/', $data['contrato']) !== 1) {
      throw new \DomainException('Escribe un número de contrato válido (solo números, letras o guiones).');
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if ((int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE TRIM(`contrato`) = ? AND `_ID` <> ?", [$data['contrato'], $contractId]) > 0) {
      throw new \DomainException('Ya existe otro contrato con el número #' . $data['contrato'] . '.');
    }
    if (!filter_var($data['registro_fotografico'], FILTER_VALIDATE_URL)) {
      throw new \DomainException('El registro fotográfico debe ser un enlace válido (https://...).');
    }
    foreach (['tasa_administracion' => 'la tasa de administración', 'incremento_pactado' => 'el incremento pactado', 'meses_contrato' => 'los meses de contrato', 'valor_canon' => 'el canon', 'valor_administracion' => 'la administración', 'derechos_inmobiliarios' => 'los derechos inmobiliarios'] as $field => $label) {
      $data[$field] = $required($text($field), $label);
    }
    foreach (['dias_gracia', 'luz', 'agua', 'gas'] as $field) {
      $data[$field] = $text($field);
    }
    $data['dias_gracia'] = $data['dias_gracia'] !== '' ? $data['dias_gracia'] : '0';

    $ph = $data['propiedad_horizontal'] === 'Si';
    foreach (['copropiedad' => 'el nombre de la copropiedad', 'nit_copropiedad' => 'el NIT de la copropiedad', 'administrador' => 'el administrador', 'celular_copropiedad' => 'el celular de la copropiedad'] as $field => $label) {
      $data[$field] = $ph ? $required($text($field), $label) : '';
    }
    $data['correo_copropiedad'] = $ph ? $email($text('correo_copropiedad'), 'de la copropiedad') : '';
    $data['zonas_sociales'] = $ph ? $list('zonas_sociales', $options['zonas_sociales']) : [];
    foreach (self::SCHEDULES as $field) {
      $value = $ph ? $text($field) : '';
      if ($value !== '' && preg_match('/^\d{2}:\d{2}$/', $value) !== 1) {
        throw new \DomainException('Revisa el formato de los horarios (HH:MM).');
      }
      $data[$field] = $value;
    }
    if ($ph) {
      foreach (['horario_mudanza_inicio_1', 'horario_mudanza_final_1', 'horario_mudanza_inicio_2', 'horario_mudanza_final_2'] as $field) {
        $required($data[$field], 'los horarios de mudanza (franjas 1 y 2)');
      }
    }
    $data['tipo_parqueadero'] = $data['tiene_parqueadero'] === 'Si' ? $oneOf('tipo_parqueadero', 'tipo_parqueadero', 'el tipo de parqueadero') : '';
    $data['parqueaderos'] = $data['tiene_parqueadero'] === 'Si' ? $required($text('parqueaderos'), 'la cantidad de parqueaderos') : '';
    $data['numero_parqueadero'] = $data['tiene_parqueadero'] === 'Si' ? $required($text('numero_parqueadero'), 'el número de parqueadero') : '';
    $data['depositos'] = $data['tiene_deposito'] === 'Si' ? $required($text('depositos'), 'la cantidad de depósitos') : '';

    foreach (['propietario', 'arrendatario'] as $role) {
      $data[$role] = $required($text($role), 'el nombre del ' . $role);
      $data['documento_' . $role] = $required($text('documento_' . $role), 'el documento del ' . $role);
      $data['correo_' . $role] = $email($text('correo_' . $role), 'del ' . $role);
      $data['celular_' . $role] = $required(preg_replace('/[^\d+]/', '', $text('celular_' . $role)) ?? '', 'el celular del ' . $role);
    }

    foreach (['tiene_mascotas' => 'si tiene mascotas', 'tiene_vehiculo' => 'si tiene vehículo', 'convive_personas' => 'si convive con personas'] as $field => $label) {
      $data[$field] = $ph ? $oneOf($field, 'si_no', $label) : '';
    }
    $rows = static function (string $key, array $fields) use ($input): array {
      $out = [];
      foreach ((array) ($input[$key] ?? []) as $row) {
        $clean = [];
        foreach ($fields as $field) {
          $clean[$field] = trim(strip_tags((string) ($row[$field] ?? '')));
        }
        if (implode('', $clean) !== '') {
          $out[] = $clean;
        }
      }
      return $out;
    };
    $data['mascotas'] = $data['tiene_mascotas'] === 'Si' ? $rows('mascotas', ['tipo', 'raza']) : [];
    $data['vehiculos'] = $data['tiene_vehiculo'] === 'Si' ? $rows('vehiculos', ['tipo', 'placa']) : [];
    $data['personas'] = $data['convive_personas'] === 'Si' ? $rows('personas', ['nombre', 'edad', 'identificacion', 'parentesco', 'correo', 'celular', 'ocupacion']) : [];

    $codebtors = $text('num_codeudores');
    if (!in_array($codebtors, self::CODEBTOR_OPTIONS, true)) {
      throw new \DomainException('Selecciona el número de codeudores.');
    }
    $data['num_codeudores'] = $codebtors;
    $data['codebtors'] = $codebtors === 'Ninguno' ? 0 : (int) $codebtors;
    for ($i = 1; $i <= 4; $i++) {
      $active = $i <= $data['codebtors'];
      $data['codeudor_' . $i] = $active ? $required($text('codeudor_' . $i), 'el nombre del codeudor ' . $i) : '';
      $data['documento_codeudor_' . $i] = $active ? $required($text('documento_codeudor_' . $i), 'el documento del codeudor ' . $i) : '';
      $data['correo_codeudor_' . $i] = $active ? $email($text('correo_codeudor_' . $i), 'del codeudor ' . $i) : '';
      $data['celular_codeudor_' . $i] = $active ? $required(preg_replace('/[^\d+]/', '', $text('celular_codeudor_' . $i)) ?? '', 'el celular del codeudor ' . $i) : '';
      $data['dir_codeudor_' . $i] = $active ? $required($text('dir_codeudor_' . $i), 'la dirección del codeudor ' . $i) : '';
    }
    foreach (self::AGREEMENTS as $option => $field) {
      $data[$field] = in_array($option, $data['acuerdos_precontractuales'], true) ? trim(strip_tags($text($field))) : '';
    }
    return $data;
  }

  /**
   * @param array<string,mixed> $context
   * @param array<string,array{url:string,path:string,name:string}> $documents
   * @return array{email:int,whatsapp:int,internal:int}
   */
  private function notify(array $context, array $documents, bool $ph, int $actaId, int $contractId, int $now): array
  {
    $queued = ['email' => 0, 'whatsapp' => 0, 'internal' => 0];
    $email = new EmailQueue($this->db);
    $sms = new SmsQueue($this->db);
    $company = mb_strtoupper(trim((string) ($this->annexes->branch($context)['nombre'] ?? '')) ?: 'Soluciones Comerciales y Constructivas SAS', 'UTF-8');
    $signature = trim(($context['representante_legal'] ?? '') . (($context['celular_legal'] ?? '') !== '' ? ' | ' . $context['celular_legal'] : '')) . ' - Representante legal';
    $dedupe = 'contrato_creado_' . $contractId . '_' . $now;
    $p = static fn(string $text): string => '<p style="margin:0 0 14px;line-height:1.65;">' . $text . '</p>';
    $e = static fn($value): string => EmailTemplate::e((string) $value);

    if ($ph) {
      foreach ([$this->sendTenantNotice($context, $documents['notificacion'], $dedupe, $contractId), $this->sendBuildingLetter($context, $documents['administracion'], $dedupe, $contractId, $actaId)] as [$mails, $messages]) {
        $queued['email'] += $mails;
        $queued['whatsapp'] += $messages;
      }
    }

    $internal = InternalNotificationRecipients::emailsForAction($this->db, self::INTERNAL_ACTION);
    if ($internal !== []) {
      $subject = 'Contrato de arrendamiento #' . $context['contrato'] . ' creado';
      $body = $p('Se creó el contrato <b>#' . $e($context['contrato']) . '</b> del inmueble <b>' . $e($context['inmueble']) . '</b>, ubicado en <b>' . $e($context['direccion']) . '</b>, para el arrendatario <b>' . $e($context['arrendatario']) . '</b>.')
        . ($ph ? $p('Se enviaron el acta de notificación de ingreso al arrendatario y la carta de presentación a la copropiedad <b>' . $e($context['copropiedad']) . '</b>; ambas van adjuntas.') : $p('El inmueble no pertenece a propiedad horizontal; no se generaron actas de copropiedad.'))
        . $p('Registrado por <b>' . $e($context['realizado_por']) . '</b>.');
      $queued['internal'] = $email->enqueue($internal, $subject, EmailTemplate::render($subject, $body), [
        'source_module' => 'contratos_arrendamiento', 'internal_cc' => [], 'destination_name' => 'Equipo interno', 'dedupe_key' => $dedupe . '_interno',
        'payload' => ['attachments' => array_values(array_map(static fn(array $d): array => ['path' => $d['path'], 'name' => $d['name']], $documents))],
        'meta' => ['contract_id' => $contractId, 'internal_action' => self::INTERNAL_ACTION],
      ]);
    }
    return $queued;
  }

  /**
   * Reenvía el acta de notificación de ingreso y/o la carta de presentación ya generadas.
   *
   * @param string[] $targets 'arrendatario' y/o 'copropiedad'
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   * @return array{email:int,whatsapp:int,message:string}
   */
  public function resendBuildingActs(int $contractId, array $targets, array $sender): array
  {
    $contract = $this->annexes->contract($contractId);
    $acts = $this->buildingActs($contract);
    $targets = array_values(array_intersect($targets, ['arrendatario', 'copropiedad']));
    if ($targets === []) {
      throw new \DomainException('Selecciona a quién reenviar.');
    }
    $context = $this->notifyContext($contract, $sender);
    $now = time();
    $dedupe = 'contrato_actas_reenvio_' . $contractId . '_' . $now;
    $queued = ['email' => 0, 'whatsapp' => 0];
    $sent = [];
    $generated = [];
    foreach ($targets as $target) {
      $key = $target === 'arrendatario' ? 'notificacion' : 'administracion';
      $document = $acts[$key];
      if ($document === null) {
        // Muchos contratos con copropiedad nunca tuvieron las actas (en JetForm esa acción estaba desactivada): se generan ahora.
        $document = $this->generateBuildingAct($contract, $key, $sender, $now);
        $generated[] = $document['path'];
      }
      if ($target === 'arrendatario') {
        if (!filter_var($context['correo_arrendatario'], FILTER_VALIDATE_EMAIL) && trim($context['celular_arrendatario']) === '') {
          throw new \DomainException('El arrendatario no tiene correo ni celular registrado.');
        }
        [$mails, $messages] = $this->sendTenantNotice($context, $document, $dedupe, $contractId);
        $sent[] = 'notificación de ingreso al arrendatario';
      } else {
        if (!filter_var($context['correo_copropiedad'], FILTER_VALIDATE_EMAIL) && trim($context['celular_copropiedad']) === '') {
          throw new \DomainException('La copropiedad no tiene correo ni celular registrado.');
        }
        [$mails, $messages] = $this->sendBuildingLetter($context, $document, $dedupe, $contractId, 0);
        $sent[] = 'presentación del nuevo inquilino a la copropiedad';
      }
      $queued['email'] += $mails;
      $queued['whatsapp'] += $messages;
    }
    $summary = 'Se reenvió la ' . implode(' y la ', $sent) . ' del contrato #' . (string) ($contract['contrato'] ?? $contractId) . '.' . ($generated !== [] ? ' Las actas que faltaban se generaron en este envío.' : '');
    $this->history($contract, ['contrato' => (string) ($contract['contrato'] ?? ''), 'inmueble' => (string) ($contract['inmueble'] ?? '')], $summary, $sender, $now, date('Y-m-d H:i:s', $now), 'Reenvío de actas de copropiedad');
    return $queued + ['message' => $summary];
  }

  /**
   * Genera el acta que falta con los datos del contrato, su hoja de cierre y el acta de administración, y la guarda en el contrato.
   *
   * @param array<string,mixed> $contract
   * @param array{employee_id:string,name:string,cargo:string,phone:string} $sender
   * @return array{url:string,path:string,name:string}
   */
  private function generateBuildingAct(array $contract, string $key, array $sender, int $now): array
  {
    if (strtolower(trim((string) ($contract['propiedad_horizontal'] ?? ''))) !== 'si') {
      throw new \DomainException('El contrato no pertenece a una copropiedad; no aplica esta acta.');
    }
    $closing = $this->closing($contract);
    $record = [];
    $table = $this->db->table('jet_cct_actas_administracion');
    if ($this->schema->tableExists($table)) {
      $record = $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_contrato_arrendamiento`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [(string) ($contract['_ID'] ?? '')]) ?? [];
    }
    $first = static function (string $field, array ...$sources): string {
      foreach ($sources as $source) {
        $value = trim((string) ($source[$field] ?? ''));
        if ($value !== '') {
          return $value;
        }
      }
      return '';
    };
    $data = [];
    foreach (array_merge(self::SCHEDULES, ['tiene_mascotas', 'tiene_vehiculo', 'convive_personas', 'copropiedad', 'administrador', 'direccion', 'arrendatario', 'tiene_parqueadero', 'tipo_parqueadero', 'parqueaderos', 'numero_parqueadero']) as $field) {
      $data[$field] = $first($field, $contract, $record, $closing);
    }
    $data['zonas_sociales'] = $this->list($first('zonas_sociales', $record, $closing));
    $data['mascotas'] = $this->list($first('mascotas', $record, $closing));
    $data['vehiculos'] = $this->list($first('vehiculo', $record, $closing));
    $data['personas'] = $this->list($first('personas', $record, $closing));
    if ($data['copropiedad'] === '' || $data['arrendatario'] === '') {
      throw new \DomainException('Faltan la copropiedad o el arrendatario del contrato para generar el acta.');
    }
    $staff = $this->annexes->staff();
    if (($staff['legal']['nombre'] ?? '') === '') {
      throw new \DomainException('No hay un funcionario activo con el cargo 14 (representante legal) para firmar el acta.');
    }
    $context = array_merge($contract, $data, [
      'fecha' => $now, 'ciudad' => 'Cartagena de Indias',
      'realizado_por' => $sender['name'], 'realizado_por_cargo' => $sender['cargo'], 'realizado_por_telefono' => $sender['phone'],
      'nombre_contractual' => $staff['contractual']['nombre'] ?? '', 'celular_contractual' => $staff['contractual']['celular'] ?? '', 'correo_contractual' => $staff['contractual']['correo'] ?? '',
      'nombre_propietarios' => $staff['propietarios']['nombre'] ?? '', 'celular_propietarios' => $staff['propietarios']['celular'] ?? '', 'correo_propietarios' => $staff['propietarios']['correo'] ?? '',
      'nombre_arrendatarios' => $staff['arrendatarios']['nombre'] ?? '', 'celular_arrendatarios' => $staff['arrendatarios']['celular'] ?? '', 'correo_arrendatarios' => $staff['arrendatarios']['correo'] ?? '',
      'representante_legal' => $staff['legal']['nombre'], 'celular_legal' => $staff['legal']['celular'] ?? '', 'cargo_legal' => $staff['legal']['cargo'] ?? '', 'firma_legal' => $staff['legal']['firma'] ?? '',
    ]);
    $document = $key === 'notificacion'
      ? $this->pdf(LeaseContractActs::tenantNotice($context, true), 'Acta de notificacion de ingreso - ' . $data['arrendatario'])
      : $this->pdf(LeaseContractActs::buildingLetter($context, true), 'Carta de presentacion del inquilino - ' . $data['copropiedad']);
    $field = $key === 'notificacion' ? 'acta_notificacion_arrendatario' : 'acta_administracion';
    $this->update('jet_cct_contratos_arrendamiento', (int) $contract['_ID'], [$field => $document['url'], 'cct_modified' => date('Y-m-d H:i:s', $now)]);
    if ($record !== []) {
      $this->update('jet_cct_actas_administracion', (int) $record['_ID'], [$key === 'notificacion' ? 'acta_notificacion' : 'acta_administracion' => $document['url'], 'cct_modified' => date('Y-m-d H:i:s', $now)]);
    }
    return $document;
  }

  /**
   * Actas de copropiedad ya generadas del contrato (las nativas y las que dejó el formulario de WordPress).
   *
   * @param array<string,mixed> $contract
   * @return array{notificacion:?array{url:string,path:string,name:string},administracion:?array{url:string,path:string,name:string}}
   */
  public function buildingActs(array $contract): array
  {
    $doc = function (string $field, string $name) use ($contract): ?array {
      $url = trim(html_entity_decode((string) ($contract[$field] ?? ''), ENT_QUOTES, 'UTF-8'));
      if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
      }
      parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
      $path = isset($query['n']) ? (string) (StoredFileService::fromRuntime()->pathFor((string) $query['n']) ?? '') : '';
      // Actas antiguas de JetForm: viven en wp-content/uploads del WordPress que aloja el panel.
      $urlPath = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));
      $pos = stripos($urlPath, '/wp-content/uploads/');
      if ($path === '' && $pos !== false) {
        foreach (array_unique([dirname((string) SCM_ROOT), dirname((string) SCM_ROOT, 2), trim((string) getenv('SCM_WORDPRESS_ROOT'))]) as $wpRoot) {
          $candidate = rtrim($wpRoot, '/\\') . substr($urlPath, $pos);
          if ($wpRoot !== '' && is_file($candidate) && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'pdf') {
            $path = $candidate;
            break;
          }
        }
      }
      return ['url' => $url, 'path' => $path, 'name' => $name . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($contract['contrato'] ?? '')) . '.pdf'];
    };
    return [
      'notificacion' => $doc('acta_notificacion_arrendatario', 'Acta-de-notificacion-de-ingreso'),
      'administracion' => $doc('acta_administracion', 'Carta-de-presentacion-del-inquilino'),
    ];
  }

  /** @param array<string,mixed> $contract @param array<string,string> $sender @return array<string,mixed> */
  private function notifyContext(array $contract, array $sender): array
  {
    $staff = $this->annexes->staff();
    return array_merge($contract, [
      'realizado_por' => $sender['name'],
      'correo_contractual' => $staff['contractual']['correo'] ?? '',
      'representante_legal' => $staff['legal']['nombre'] ?? '',
      'celular_legal' => $staff['legal']['celular'] ?? '',
      'arrendatario' => trim((string) ($contract['arrendatario'] ?? '')),
      'correo_arrendatario' => strtolower(trim((string) ($contract['correo_arrendatario'] ?? ''))),
      'celular_arrendatario' => trim((string) ($contract['celular_arrendatario'] ?? '')),
      'copropiedad' => trim((string) ($contract['copropiedad'] ?? '')),
      'administrador' => trim((string) ($contract['administrador'] ?? '')),
      'correo_copropiedad' => strtolower(trim((string) ($contract['correo_copropiedad'] ?? ''))),
      'celular_copropiedad' => trim((string) ($contract['celular_copropiedad'] ?? '')),
    ]);
  }

  private function company(array $context): string
  {
    return mb_strtoupper(trim((string) ($this->annexes->branch($context)['nombre'] ?? '')) ?: 'Soluciones Comerciales y Constructivas SAS', 'UTF-8');
  }

  private function signature(array $context): string
  {
    return trim(($context['representante_legal'] ?? '') . (($context['celular_legal'] ?? '') !== '' ? ' | ' . $context['celular_legal'] : '')) . ' - Representante legal';
  }

  /** @param array<string,mixed> $context @param array{url:string,path:string,name:string} $document @return array{0:int,1:int} */
  private function sendTenantNotice(array $context, array $document, string $dedupe, int $contractId): array
  {
    $p = static fn(string $text): string => '<p style="margin:0 0 14px;line-height:1.65;">' . $text . '</p>';
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $company = $this->company($context);
    $signature = $this->signature($context);
    $html = $p('Apreciado(a) <b>' . $e($context['arrendatario']) . '</b>:')
      . $p('Por medio de la presente le damos la más cordial bienvenida a <b>' . $e($company) . '</b>, donde lo hemos incorporado como <b>ARRENDATARIO</b>. Ya que el inmueble al cual ingresará pertenece a la copropiedad <b>' . $e($context['copropiedad']) . '</b>, hemos realizado el acta de notificación para que la copropiedad esté enterada de su ingreso.')
      . $p('<b>Nota:</b> el acta también se adjunta a este correo electrónico.')
      . $p('Le recordamos que tiene acceso al portal de arrendatario usando su <b>número de documento como usuario y contraseña</b>.')
      . $p('Cordialmente: <b>' . $e($signature) . '</b>.');
    $subject = 'Te notificamos la bienvenida ' . $context['arrendatario'];
    $mails = (new EmailQueue($this->db))->enqueue((string) $context['correo_arrendatario'], $subject, EmailTemplate::render($subject, $html, ['buttons' => [
      ['url' => $document['url'], 'label' => 'Ver acta'], ['url' => 'https://sucasainmobiliaria.com.co/arrendatario', 'label' => 'Entrar a mi cuenta'], ['url' => 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'],
    ]]), [
      'source_module' => 'contratos_arrendamiento', 'internal_cc' => [], 'destination_name' => $context['arrendatario'], 'dedupe_key' => $dedupe . '_arrendatario',
      'payload' => ['attachments' => $document['path'] !== '' ? [['path' => $document['path'], 'name' => $document['name']]] : [], 'reply_to' => (string) ($context['correo_contractual'] ?? '')],
      'meta' => ['contract_id' => $contractId, 'recipient_role' => 'arrendatario'],
    ]);
    $messages = $this->whatsapp(new SmsQueue($this->db), (string) $context['celular_arrendatario'], (string) $context['arrendatario'], self::TEMPLATE_TENANT, $document, $dedupe . '_wa_arrendatario', $contractId,
      [$context['arrendatario'], $company, $context['contrato'], $context['direccion'], $context['copropiedad'], $signature]) ? 1 : 0;
    return [$mails, $messages];
  }

  /** @param array<string,mixed> $context @param array{url:string,path:string,name:string} $document @return array{0:int,1:int} */
  private function sendBuildingLetter(array $context, array $document, string $dedupe, int $contractId, int $actaId): array
  {
    $p = static fn(string $text): string => '<p style="margin:0 0 14px;line-height:1.65;">' . $text . '</p>';
    $e = static fn($value): string => EmailTemplate::e((string) $value);
    $signature = $this->signature($context);
    $html = $p('Apreciado(a) Sr./Sra. <b>' . $e($context['administrador']) . '</b> de la copropiedad <b>' . $e($context['copropiedad']) . '</b>:')
      . $p('Me dirijo a usted por medio de la presente para informarle que el inmueble ubicado en <b>' . $e($context['direccion']) . '</b> será ocupado por el nuevo inquilino <b>' . $e($context['arrendatario']) . '</b>, bajo el contrato número <b>#' . $e($context['contrato']) . '</b>.')
      . $p('Agradecemos de antemano su colaboración para que la experiencia del nuevo inquilino en la copropiedad sea lo más positiva posible.')
      . $p('<b>Nota:</b> el acta también se adjunta a este correo electrónico.')
      . $p('Cordialmente: <b>' . $e($signature) . '</b>.');
    $subject = 'Presentación nuevo inquilino · ' . $context['direccion'];
    $mails = (new EmailQueue($this->db))->enqueue((string) $context['correo_copropiedad'], $subject, EmailTemplate::render($subject, $html, ['buttons' => [
      ['url' => $document['url'], 'label' => 'Ver acta'], ['url' => 'https://sucasainmobiliaria.com.co/revista/', 'label' => 'Ver revista'],
    ]]), [
      'source_module' => 'contratos_arrendamiento', 'internal_cc' => [], 'destination_name' => $context['administrador'], 'dedupe_key' => $dedupe . '_copropiedad',
      'payload' => ['attachments' => $document['path'] !== '' ? [['path' => $document['path'], 'name' => $document['name']]] : [], 'reply_to' => (string) ($context['correo_contractual'] ?? '')],
      'meta' => ['contract_id' => $contractId, 'recipient_role' => 'copropiedad', 'acta_administracion_id' => $actaId],
    ]);
    $building = $this->row('jet_cct_copropiedades', $context['id_copropiedad'] ?? '');
    $phone = (string) $context['celular_copropiedad'];
    $indicative = preg_replace('/\D+/', '', (string) ($building['indicativo'] ?? '')) ?? '';
    if ($indicative !== '' && $phone !== '' && !str_starts_with($phone, '+')) {
      $phone = '+' . $indicative . ltrim($phone, '0');
    }
    $messages = $this->whatsapp(new SmsQueue($this->db), $phone, (string) $context['administrador'], self::TEMPLATE_BUILDING, $document, $dedupe . '_wa_copropiedad', $contractId,
      [$context['administrador'], $context['copropiedad'], $context['arrendatario'], $context['direccion'], $context['contrato'], $signature]) ? 1 : 0;
    return [$mails, $messages];
  }

  /** @param array{url:string,name:string} $document @param array<int,mixed> $params */
  private function whatsapp(SmsQueue $sms, string $phone, string $name, string $template, array $document, string $dedupe, int $contractId, array $params): bool
  {
    $parts = parse_url($document['url']);
    $suffix = ltrim((string) ($parts['path'] ?? ''), '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return $sms->enqueue($phone, $name, 'Acta del contrato de arrendamiento: ' . $document['url'], [
      'source_module' => 'contratos_arrendamiento',
      'dedupe_key' => $dedupe,
      'template_name' => $template,
      'template_language' => 'es_CO',
      'template_components' => [
        ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['link' => $document['url'], 'filename' => $document['name']]]]],
        ['type' => 'body', 'parameters' => array_map(static function ($value): array {
          $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8')) ?? '');
          return ['type' => 'text', 'text' => mb_substr($text !== '' ? $text : '-', 0, 1024, 'UTF-8')];
        }, $params)],
        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $suffix]]],
      ],
      'contract_id' => $contractId,
      'document_url' => $document['url'],
    ]);
  }

  /** @return array{url:string,path:string,name:string} */
  private function pdf(string $html, string $title): array
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render($html, $title, [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css', $root . '/public/assets/css/lease-annexes.css'],
      'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
      'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
    ]);
    if ($bytes === null) {
      throw new \DomainException('No se pudo generar "' . $title . '". Verifica el servicio de PDF e intenta nuevamente.');
    }
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
      throw new \RuntimeException('No fue posible guardar el acta en PDF.');
    }
    $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: 'acta'), '-');
    return ['url' => StoredFileService::fromRuntime()->urlFor($basename), 'path' => $path, 'name' => ($slug !== '' ? $slug : 'acta') . '.pdf'];
  }

  /** @param array<string,mixed> $contract @param array<string,mixed> $data @param array<string,string> $sender */
  private function history(array $contract, array $data, string $summary, array $sender, int $now, string $nowSql, string $type = 'Creación de contrato'): void
  {
    $common = [
      'cct_status' => 'publish', 'cct_author_id' => $sender['employee_id'], 'cct_created' => $nowSql, 'cct_modified' => $nowSql, 'fecha' => $now,
      'id_empleado' => $sender['employee_id'], 'funcionario' => $sender['name'],
      'id_inmueble' => (string) ($contract['id_inmueble'] ?? ''), 'id_inmueble_data' => (string) ($contract['id_inmueble_data'] ?? ''),
    ];
    $contractHistory = $this->db->table('jet_cct_historial_contrato_arrendamiento');
    if ($this->schema->tableExists($contractHistory)) {
      $this->db->insert($contractHistory, $this->schema->filterTableData($contractHistory, $common + [
        'tipo_reporte' => $type, 'observacion' => $summary, 'inmueble' => $data['inmueble'], 'contrato' => $data['contrato'], 'id_contrato' => (string) ($contract['_ID'] ?? ''),
      ]));
    }
    $propertyHistory = $this->db->table('jet_cct_historial_del_inmueble');
    if ($this->schema->tableExists($propertyHistory)) {
      $this->db->insert($propertyHistory, $this->schema->filterTableData($propertyHistory, $common + ['tipo_reporte' => 'Contractual', 'observacion' => $summary]));
    }
  }

  /** @param array<string,mixed> $data */
  private function update(string $name, int $id, array $data): void
  {
    $table = $this->db->table($name);
    if ($id <= 0 || !$this->schema->tableExists($table)) {
      return;
    }
    $data = $this->schema->filterTableData($table, $data);
    if ($data !== []) {
      $this->db->update($table, $data, ['_ID' => $id]);
    }
  }

  /** @param array<string,string> $sender @param array<string,mixed> $data */
  private function insert(string $name, array $sender, string $nowSql, array $data): int
  {
    $table = $this->db->table($name);
    if (!$this->schema->tableExists($table)) {
      return 0;
    }
    $data = $this->schema->filterTableData($table, ['cct_status' => 'publish', 'cct_author_id' => $sender['employee_id'], 'cct_created' => $nowSql, 'cct_modified' => $nowSql] + $data);
    if ($data === [] || !$this->db->insert($table, $data)) {
      throw new \RuntimeException('No se pudo guardar en ' . $name . '.');
    }
    return (int) $this->db->lastInsertId();
  }

  /** Relación JetEngine (rel_id, padre, hijo) sin duplicar. */
  private function relate(int $relation, string $parent, string $child): void
  {
    $table = $this->db->table('jet_rel_' . $relation);
    if (!ctype_digit($parent) || !ctype_digit($child) || (int) $parent <= 0 || (int) $child <= 0 || !$this->schema->tableExists($table)) {
      return;
    }
    $exists = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE `parent_object_id` = ? AND `child_object_id` = ?", [(int) $parent, (int) $child]);
    if ($exists === 0) {
      $this->db->insert($table, ['rel_id' => (string) $relation, 'parent_rel' => 0, 'parent_object_id' => (int) $parent, 'child_object_id' => (int) $child]);
    }
  }

  /** Metadatos del inmueble en WordPress (post type "inmuebles"), como hacía la acción insert_post del formulario. @param array<string,string> $meta */
  private function propertyMeta(int $postId, array $meta): void
  {
    $posts = $this->db->table('posts');
    $postmeta = $this->db->table('postmeta');
    if ($postId <= 0 || !$this->schema->tableExists($postmeta)
      || (string) $this->db->getVar("SELECT `post_type` FROM `{$posts}` WHERE `ID` = ? LIMIT 1", [$postId]) !== 'inmuebles') {
      return;
    }
    foreach ($meta as $key => $value) {
      $ids = $this->db->getCol("SELECT `meta_id` FROM `{$postmeta}` WHERE `post_id` = ? AND `meta_key` = ?", [$postId, $key]);
      if ($ids === []) {
        $this->db->insert($postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => (string) $value]);
      } else {
        $this->db->update($postmeta, ['meta_value' => (string) $value], ['post_id' => $postId, 'meta_key' => $key]);
      }
    }
  }

  /** @param array<string,mixed> $contract @return array<string,mixed> */
  public function closing(array $contract): array
  {
    $table = $this->db->table('jet_cct_cierres');
    $id = (string) ($contract['_ID'] ?? '');
    if ($id === '' || !$this->schema->tableExists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE TRIM(COALESCE(`id_contrato_arrendamiento`, '')) = ? ORDER BY `_ID` DESC LIMIT 1", [$id]) ?? [];
  }

  /** @return array<string,mixed> */
  private function row(string $name, mixed $id): array
  {
    $id = trim((string) $id);
    $table = $this->db->table($name);
    if (!ctype_digit($id) || (int) $id <= 0 || !$this->schema->tableExists($table)) {
      return [];
    }
    return $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [(int) $id]) ?? [];
  }

  /** @param array<int,mixed> $values */
  private function firstId(array $values): string
  {
    foreach ($values as $value) {
      $value = trim((string) $value);
      if (ctype_digit($value) && (int) $value > 0) {
        return $value;
      }
    }
    return '';
  }

  /** @return array<int,mixed> */
  private function list(mixed $value): array
  {
    if (is_array($value)) {
      return array_values($value);
    }
    $text = trim((string) $value);
    if ($text === '') {
      return [];
    }
    $decoded = preg_match('/^a:\d+:\{/', $text) === 1 ? @unserialize($text, ['allowed_classes' => false]) : json_decode($text, true);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /** @return string[] */
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
