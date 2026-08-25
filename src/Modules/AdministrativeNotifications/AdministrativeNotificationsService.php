<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeNotifications;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class AdministrativeNotificationsService
{
  public const PROJECT_CODE = 'control-servicios-inmobiliarios';
  public const SOURCE_MODULE = 'admin_notifications';
  public const QUEUE_TABLE = 'skc_notification_queue';
  public const SMS_MAX = 160;
  public const SMS_PREFIX = 'SKC SuCasa Inmobiliaria ';
  public const DEFAULT_EMAIL_TEMPLATE = 'scm_email_generica_v1';
  public const DEFAULT_WHATSAPP_TEMPLATE = 'scm_notificacion_general_v1';

  private Database $db;
  private SchemaInspector $schema;
  /** @var array{name:string,cargo:string,phone:string,signature:string}|null */
  private ?array $senderProfile = null;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->schema = new SchemaInspector($db);
  }

  /** @return array<string,array<string,mixed>> */
  public function types(): array
  {
    $propietarios = [
      'role' => 'Propietario',
      'table' => $this->db->table('jet_cct_propietarios'),
      'contract_actor_column' => 'id_propietario',
      'name' => ['nombre', 'nombre_juridico', 'titular'],
      'email' => ['correo', 'correo_cuenta'],
      'phone' => ['celular', 'telefono', 'contacto'],
      'document' => ['documento', 'cedula', 'identificacion', 'nit'],
      'indicator' => ['indicativo'],
      'search' => ['nombre', 'nombre_juridico', 'documento', 'correo', 'celular', 'ciudad'],
    ];
    $arrendatarios = [
      'role' => 'Arrendatario',
      'table' => $this->db->table('jet_cct_arrendatarios'),
      'contract_actor_column' => 'id_arrendatario',
      'name' => ['nombre', 'nombre_juridico'],
      'email' => ['correo'],
      'phone' => ['celular', 'telefono', 'contacto'],
      'document' => ['documento', 'cedula', 'identificacion', 'nit'],
      'indicator' => ['indicativo'],
      'search' => ['nombre', 'nombre_juridico', 'documento', 'correo', 'celular', 'ciudad'],
    ];
    $copropiedades = [
      'role' => 'Copropiedad',
      'table' => $this->db->table('jet_cct_copropiedades'),
      'contract_actor_column' => 'id_copropiedad',
      'contract_match_columns' => [
        'nit' => 'nit_copropiedad',
        'copropiedad' => 'copropiedad',
        'id_inmueble' => 'id_inmueble',
      ],
      'name' => ['copropiedad', 'nombre', 'administrador'],
      'email' => ['correo'],
      'phone' => ['contacto', 'celular', 'telefono'],
      'document' => ['nit', 'documento', 'identificacion'],
      'indicator' => ['indicativo'],
      'search' => ['copropiedad', 'administrador', 'nit', 'correo', 'contacto', 'barrio', 'direccion'],
    ];

    return [
      'propietarios_activos' => $propietarios + ['label' => 'Propietarios activos', 'contract_status_fixed' => 'activos'],
      'propietarios_no_activos' => $propietarios + ['label' => 'Propietarios no activos', 'contract_status_fixed' => 'no_activos'],
      'arrendatarios_activos' => $arrendatarios + ['label' => 'Arrendatarios activos', 'contract_status_fixed' => 'activos'],
      'arrendatarios_no_activos' => $arrendatarios + ['label' => 'Arrendatarios no activos', 'contract_status_fixed' => 'no_activos'],
      'copropiedades_activas' => $copropiedades + ['label' => 'Copropiedades activas', 'contract_status_fixed' => 'activos'],
      'copropiedades_no_activas' => $copropiedades + ['label' => 'Copropiedades no activas', 'contract_status_fixed' => 'no_activos'],
      'proveedores' => [
        'label' => 'Proveedores',
        'role' => 'Proveedor',
        'table' => $this->db->table('jet_cct_proveedores'),
        'name' => ['proveedor', 'titular_proveedor', 'nombre'],
        'email' => ['correo_proveedor', 'correo_pago_proveedor', 'correo'],
        'phone' => ['celular_proveedor', 'celular', 'telefono', 'contacto'],
        'document' => ['identificacion_proveedor', 'documento', 'nit'],
        'indicator' => ['indicativo'],
        'search' => ['proveedor', 'titular_proveedor', 'identificacion_proveedor', 'correo_proveedor', 'celular_proveedor', 'direccion_proveedor'],
      ],
      'funcionarios' => [
        'label' => 'Funcionarios',
        'role' => 'Funcionario',
        'table' => $this->db->table('jet_cct_funcionarios'),
        'name' => ['nombre', 'empleado', 'nombre_funcionario'],
        'email' => ['correo', 'correo_dian'],
        'phone' => ['celular', 'celular_empleado', 'telefono', 'whatsapp'],
        'document' => ['documento', 'cedula', 'identificacion', 'id_empleado'],
        'indicator' => ['indicativo'],
        'search' => ['nombre', 'id_empleado', 'documento', 'correo', 'celular', 'rol', 'gestion'],
        'only_active' => true,
      ],
    ];
  }

  /** @return array<string,array<string,int|string>> */
  public function stats(): array
  {
    $out = [];
    foreach ($this->types() as $type => $config) {
      $table = (string) $config['table'];
      if (!$this->schema->tableExists($table)) {
        $out[$type] = ['total' => 0, 'email' => 0, 'phone' => 0, 'label' => (string) $config['label']];
        continue;
      }
      $emailColumn = $this->detect($table, (array) $config['email']);
      $phoneColumn = $this->detect($table, (array) $config['phone']);
      $where = $this->baseWhere($config);
      $args = [];
      $this->applyContractFilters($where, $args, $type, $config, (string) ($config['contract_status_fixed'] ?? ''), '', '');
      $out[$type] = [
        'total' => (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where}", $args),
        'email' => $emailColumn !== '' ? (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where} AND TRIM(COALESCE(`{$emailColumn}`, '')) <> ''", $args) : 0,
        'phone' => $phoneColumn !== '' ? (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where} AND TRIM(COALESCE(`{$phoneColumn}`, '')) <> ''", $args) : 0,
        'label' => (string) $config['label'],
      ];
    }
    return $out;
  }

  /** @return array<string,array{name:string,label:string,language:string,description:string,body:string,variables:array<int,string>}> */
  public function whatsappTemplates(): array
  {
    $propietarios = ['propietarios_activos', 'propietarios_no_activos'];
    $arrendatarios = ['arrendatarios_activos', 'arrendatarios_no_activos'];
    $copropiedades = ['copropiedades_activas', 'copropiedades_no_activas'];

    return [
      self::DEFAULT_WHATSAPP_TEMPLATE => [
        'name' => self::DEFAULT_WHATSAPP_TEMPLATE,
        'label' => 'Notificacion general',
        'language' => 'es_CO',
        'description' => 'Plantilla oficial para avisos generales. Incluye la firma del funcionario.',
        'body' => "Buen dia, {{1}}.\n\nLe compartimos la siguiente informacion desde Su Casa Inmobiliaria:\n\n{{2}}\n\nAtentamente,\n{{3}}\n\nGracias por elegirnos y confiar en nuestro equipo.",
        'variables' => ['Nombre del destinatario', 'Mensaje escrito en esta pantalla', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => [],
        'email_template' => self::DEFAULT_EMAIL_TEMPLATE,
        'parameter_mode' => 'name_message_signature',
      ],
      'scm_propietario_arriendo_consignado_v1' => [
        'name' => 'scm_propietario_arriendo_consignado_v1',
        'label' => 'Arriendo consignado',
        'language' => 'es_CO',
        'description' => 'Aviso para propietarios cuando el arriendo fue consignado a la cuenta registrada.',
        'body' => "Buen dia, {{1}}.\n\nLe informamos que el pago del arriendo correspondiente a su inmueble en administracion fue consignado a la cuenta registrada.\n\nDetalle del abono:\n\n{{2}}\n\nAtentamente,\n{{3}}\n\nGracias por elegirnos y confiar en nuestro equipo.",
        'variables' => ['Nombre del propietario', 'Detalle del pago, inmueble, mes o valor', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => $propietarios,
        'email_template' => 'scm_email_propietario_arriendo_consignado_v1',
        'parameter_mode' => 'name_message_signature',
      ],
      'scm_copropiedad_soportes_pago_v1' => [
        'name' => 'scm_copropiedad_soportes_pago_v1',
        'label' => 'Soportes de pago',
        'language' => 'es_CO',
        'description' => 'Envio de soportes de pago a copropiedades para verificacion y recibo de caja.',
        'body' => "Buen dia, {{1}}.\n\nAdjuntamos los soportes de pago correspondientes para su respectiva verificacion.\n\nDetalle de los soportes:\n\n{{2}}\n\nAgradecemos su colaboracion con el envio del recibo de caja correspondiente a estos pagos.\n\nAtentamente,\n{{3}}\n\nGracias por su atencion.",
        'variables' => ['Nombre de la copropiedad o contacto', 'Mes, inmueble y detalle de los soportes', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => $copropiedades,
        'email_template' => 'scm_email_copropiedad_soportes_pago_v1',
        'parameter_mode' => 'name_message_signature',
      ],
      'scm_arrendatario_aviso_pago_canon_v1' => [
        'name' => 'scm_arrendatario_aviso_pago_canon_v1',
        'label' => 'Aviso pago canon',
        'language' => 'es_CO',
        'description' => 'Aviso formal para arrendatarios por incumplimiento en el pago del canon.',
        'body' => "Estimado(a) {{1}}, reciba un cordial saludo.\n\nLe recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.\n\nUna vez activado el proceso con la aseguradora, se puede generar un recargo adicional del 50% sobre el valor del canon adeudado, ademas de los costos y gestiones asociados al tramite.\n\nPara evitar mayores consecuencias economicas y administrativas, le solicitamos realizar el pago de manera inmediata.\n\nAtentamente,\n{{2}}\n\nGracias por su atencion.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => $arrendatarios,
        'email_template' => 'scm_email_arrendatario_aviso_pago_canon_v1',
        'parameter_mode' => 'name_signature',
      ],
      'scm_factura_disponible_v1' => [
        'name' => 'scm_factura_disponible_v1',
        'label' => 'Factura disponible',
        'language' => 'es_CO',
        'description' => 'Aviso para arrendatarios cuando tienen una factura nueva disponible.',
        'body' => "Estimado(a) {{1}}, tienes una nueva factura disponible.\n\nTu factura ya se encuentra lista para revision. Te invitamos a consultarla y realizar el pago oportunamente para mantenerte al dia.\n\nSi ya realizaste el pago, puedes hacer caso omiso a este mensaje.\n\nAtentamente,\n{{2}}\n\nGracias por elegirnos y confiar en nuestro equipo.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => $arrendatarios,
        'email_template' => 'scm_email_factura_disponible_v1',
        'parameter_mode' => 'name_signature',
      ],
      'scm_mes_generado_pago_v1' => [
        'name' => 'scm_mes_generado_pago_v1',
        'label' => 'Mes generado para pago',
        'language' => 'es_CO',
        'description' => 'Aviso para arrendatarios cuando el mes fue generado y queda disponible para pago.',
        'body' => "Hola {{1}}, tu mes ha sido generado exitosamente.\n\nPuedes realizar el pago a traves del boton de pago disponible o por ventanilla en Banco Caja Social, ingresando tu cedula o NIT sin digito de verificacion.\n\nCuando realices el pago, por favor envianos el soporte para validarlo en el sistema.\n\nEstoy para servirte.\n\nAtentamente,\n{{2}}",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => $arrendatarios,
        'email_template' => 'scm_email_mes_generado_pago_v1',
        'parameter_mode' => 'name_signature',
      ],
    ];
  }

  /** @return array<string,array{name:string,label:string,subject:string,body:string,description:string,source:string,message_only:bool,editable_message:string}> */
  public function emailTemplates(): array
  {
    return [
      self::DEFAULT_EMAIL_TEMPLATE => [
        'name' => self::DEFAULT_EMAIL_TEMPLATE,
        'label' => 'Generica',
        'subject' => 'Informacion importante',
        'description' => 'Plantilla base reutilizable: solo cambia el mensaje escrito por el funcionario.',
        'body' => '<p><strong>Hola {{nombre}}</strong>,</p><div>{{mensaje}}</div><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => true,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'preview_excerpt' => 'Base corporativa con banner, saludo en negrita, mensaje editable y firma en formato Nombre - Cargo - Celular.',
        'requires_message' => true,
      ],
      'scm_email_arrendatario_aviso_pago_canon_v1' => [
        'name' => 'scm_email_arrendatario_aviso_pago_canon_v1',
        'label' => 'Aviso pago canon',
        'subject' => 'Aviso importante sobre canon de arrendamiento',
        'description' => 'Email formal para arrendatarios por incumplimiento en el pago del canon.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>,</p><p>Reciba un cordial saludo.</p><p>Le recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.</p><p>Una vez activado el proceso con la aseguradora, se puede generar un recargo adicional del <strong>50%</strong> sobre el valor del canon adeudado, adem&aacute;s de los costos y gestiones asociados al tr&aacute;mite.</p><p>Para evitar mayores consecuencias econ&oacute;micas y administrativas, le solicitamos realizar el pago de manera inmediata.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso formal de canon vencido con firma del funcionario.',
      ],
      'scm_email_factura_disponible_v1' => [
        'name' => 'scm_email_factura_disponible_v1',
        'label' => 'Factura disponible',
        'subject' => 'Tienes una nueva factura disponible',
        'description' => 'Email para arrendatarios con factura nueva disponible.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>,</p><p>Tienes una nueva factura disponible.</p><p>Tu factura ya se encuentra lista para revisi&oacute;n. Te invitamos a consultarla y realizar el pago oportunamente para mantenerte al d&iacute;a.</p><p>Si ya realizaste el pago, puedes hacer caso omiso a este mensaje.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso de factura disponible con banner corporativo y firma.',
      ],
      'scm_email_mes_generado_pago_v1' => [
        'name' => 'scm_email_mes_generado_pago_v1',
        'label' => 'Mes generado para pago',
        'subject' => 'Tu mes ha sido generado exitosamente',
        'description' => 'Email para arrendatarios con mes generado y opciones de pago.',
        'body' => '<p><strong>Hola {{nombre}}</strong>,</p><p>Tu mes ha sido generado exitosamente.</p><p>Puedes realizar el pago a trav&eacute;s del enlace de pago disponible o por ventanilla en Banco Caja Social, ingresando tu c&eacute;dula o NIT sin d&iacute;gito de verificaci&oacute;n.</p><p><a href="https://www.mipagoamigo.com/MPA_WebSite/ServicePayments/StartPayment?id=6329&amp;searchedCategoryId=&amp;searchedAgreementName=SOLUCIONES%20COMERCIALES%20Y%20CONSTRUCTIVAS%20SAS" style="display:inline-block;background:#f59120;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:8px;">Pagar ahora</a></p><p>Cuando realices el pago, por favor env&iacute;anos el soporte para validarlo en el sistema.</p><p>Estoy para servirte.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso de mes generado con boton de pago y firma.',
      ],
      'scm_email_propietario_arriendo_consignado_v1' => [
        'name' => 'scm_email_propietario_arriendo_consignado_v1',
        'label' => 'Arriendo consignado',
        'subject' => 'Pago de arriendo consignado',
        'description' => 'Email para propietarios cuando el arriendo fue consignado.',
        'body' => '<p><strong>Buen d&iacute;a, {{nombre}}</strong>.</p><p>Le informamos que el pago del arriendo correspondiente a su inmueble en administraci&oacute;n fue consignado a la cuenta registrada.</p><div>{{mensaje}}</div><p>Agradecemos verificar la transacci&oacute;n y comunicarnos cualquier novedad.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => true,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'requires_message' => true,
        'preview_excerpt' => 'Aviso de arriendo consignado con detalle editable.',
      ],
      'scm_email_copropiedad_soportes_pago_v1' => [
        'name' => 'scm_email_copropiedad_soportes_pago_v1',
        'label' => 'Soportes de pago',
        'subject' => 'Envio de soportes de pago',
        'description' => 'Email para copropiedades con soportes de pago.',
        'body' => '<p><strong>Buen d&iacute;a, {{nombre}}</strong>.</p><p>Adjuntamos los soportes de pago correspondientes para su respectiva verificaci&oacute;n.</p><div>{{mensaje}}</div><p>Agradecemos su colaboraci&oacute;n con el env&iacute;o del recibo de caja correspondiente a estos pagos.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => true,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'requires_message' => true,
        'preview_excerpt' => 'Envio de soportes de pago con detalle editable.',
      ],
    ];
  }

  /** @param array<string,string> $fieldFilters @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,type:string,type_label:string,contract_status:string,inmueble_simi:string,contract_number:string,field_filters:array<string,string>} */
  public function search(string $type, string $query, int $page = 1, int $perPage = 20, string $contractStatus = '', string $inmuebleSimi = '', string $contractNumber = '', array $fieldFilters = []): array
  {
    $config = $this->typeConfig($type);
    $contractStatus = $this->effectiveContractStatus($config, $contractStatus);
    $inmuebleSimi = trim($inmuebleSimi);
    $contractNumber = trim($contractNumber);
    $fieldFilters = $this->normalizeFieldFilters($fieldFilters);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table)) {
      return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $perPage, 'type' => $type, 'type_label' => (string) $config['label'], 'contract_status' => $contractStatus, 'inmueble_simi' => $inmuebleSimi, 'contract_number' => $contractNumber, 'field_filters' => $fieldFilters];
    }

    $columns = $this->resolveColumns($config);
    $where = $this->baseWhere($config);
    $args = [];
    if (trim($query) !== '') {
      $searchColumns = $this->searchColumns($table, $config);
      if ($searchColumns !== []) {
        $like = '%' . $this->db->escapeLike(trim($query)) . '%';
        $parts = [];
        foreach ($searchColumns as $column) {
          $parts[] = "`{$column}` LIKE ?";
          $args[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
      }
    }
    $this->applyFieldTextFilters($where, $args, $table, $config, $fieldFilters);
    $this->applyContractFilters($where, $args, $type, $config, $contractStatus, $inmuebleSimi, $contractNumber);

    $total = (int) $this->db->getVar("SELECT COUNT(1) FROM `{$table}` WHERE {$where}", $args);
    $page = max(1, $page);
    $perPage = min(100, max(10, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $select = [
      '`_ID`',
      $columns['name'] !== '' ? "`{$columns['name']}` AS nombre" : "CAST(`_ID` AS CHAR) AS nombre",
      $columns['email'] !== '' ? "`{$columns['email']}` AS correo" : "'' AS correo",
      $columns['phone'] !== '' ? "`{$columns['phone']}` AS celular" : "'' AS celular",
      $columns['indicator'] !== '' ? "`{$columns['indicator']}` AS indicativo" : "'' AS indicativo",
    ];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if ($contractActorColumn !== '' && $this->schema->columnExists($table, $contractActorColumn)) {
      $select[] = "`{$contractActorColumn}` AS contrato_actor_ref";
    } else {
      $select[] = "'' AS contrato_actor_ref";
    }
    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      if ($actorColumn !== '' && $this->schema->columnExists($table, $actorColumn)) {
        $select[] = "`{$actorColumn}` AS `contrato_ref_{$actorColumn}`";
      }
    }

    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT ? OFFSET ?",
      array_merge($args, [$perPage, $offset])
    );

    foreach ($rows as &$row) {
      $row['tipo_actor'] = $type;
      $row['tipo_label'] = (string) $config['label'];
      $row['rol_persona'] = (string) $config['role'];
      $row['celular_normalizado'] = $this->normalizePhone((string) ($row['celular'] ?? ''), (string) ($row['indicativo'] ?? ''), true);
      $contractInfo = $this->contractActivityInfo($type, $config, $row, $contractStatus, $inmuebleSimi, $contractNumber);
      $row['contrato_arrendamiento_estado'] = $contractInfo['label'];
      $row['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
    }
    unset($row);

    return [
      'rows' => $rows,
      'total' => $total,
      'page' => $page,
      'pages' => $pages,
      'per_page' => $perPage,
      'type' => $type,
      'type_label' => (string) $config['label'],
      'contract_status' => $contractStatus,
      'inmueble_simi' => $inmuebleSimi,
      'contract_number' => $contractNumber,
      'field_filters' => $fieldFilters,
    ];
  }

  /** @return int[] */
  public function idsForFilter(string $type, string $query, int $limit = 5000, string $contractStatus = '', string $inmuebleSimi = '', string $contractNumber = '', array $fieldFilters = []): array
  {
    $config = $this->typeConfig($type);
    $contractStatus = $this->effectiveContractStatus($config, $contractStatus);
    $inmuebleSimi = trim($inmuebleSimi);
    $contractNumber = trim($contractNumber);
    $fieldFilters = $this->normalizeFieldFilters($fieldFilters);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $where = $this->baseWhere($config);
    $args = [];
    if (trim($query) !== '') {
      $searchColumns = $this->searchColumns($table, $config);
      if ($searchColumns !== []) {
        $like = '%' . $this->db->escapeLike(trim($query)) . '%';
        $parts = [];
        foreach ($searchColumns as $column) {
          $parts[] = "`{$column}` LIKE ?";
          $args[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $parts) . ')';
      }
    }
    $this->applyFieldTextFilters($where, $args, $table, $config, $fieldFilters);
    $this->applyContractFilters($where, $args, $type, $config, $contractStatus, $inmuebleSimi, $contractNumber);
    $rows = $this->db->getResults("SELECT `_ID` FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT ?", array_merge($args, [max(1, $limit)]));
    return array_values(array_map(static fn(array $row): int => (int) ($row['_ID'] ?? 0), $rows));
  }

  /**
   * @param array<string,mixed> $file
   * @return array{rows:array<int,array<string,mixed>>,payload:array<string,array<string,string>>,total_rows:int,usable_rows:int,matched:int,unmatched:int,duplicates:int,type:string,type_label:string}
   */
  public function importRecipientsFromFile(string $type, array $file): array
  {
    $config = $this->typeConfig($type);
    if (empty($config['contract_actor_column'])) {
      throw new \RuntimeException('Este tipo de destinatario no se puede cruzar por contrato o inmueble SIMI.');
    }

    $rows = $this->parseImportFile($file);
    if ($rows === []) {
      throw new \RuntimeException('El archivo no tiene filas para importar.');
    }

    [$headers, $dataRows] = $this->normalizeImportRows($rows);
    if ($headers === [] || $dataRows === []) {
      throw new \RuntimeException('No se encontraron encabezados validos. Usa columnas como contrato, inmueble_simi y canon.');
    }

    $outRows = [];
    $payload = [];
    $seen = [];
    $usable = 0;
    $unmatched = 0;
    $duplicates = 0;

    foreach ($dataRows as $row) {
      $meta = $this->importMetaForRow($headers, $row);
      if (($meta['contrato_excel'] ?? '') === '' && ($meta['inmueble_simi_excel'] ?? '') === '') {
        continue;
      }
      $usable++;
      $result = $this->search($type, '', 1, 100, '', (string) ($meta['inmueble_simi_excel'] ?? ''), (string) ($meta['contrato_excel'] ?? ''));
      $matches = (array) ($result['rows'] ?? []);
      if ($matches === []) {
        $unmatched++;
        continue;
      }
      foreach ($matches as $recipient) {
        $id = (int) ($recipient['_ID'] ?? 0);
        if ($id <= 0) {
          continue;
        }
        if (isset($seen[$id])) {
          $duplicates++;
          continue;
        }
        $seen[$id] = true;
        $recipient['_scm_import_meta'] = $meta;
        $outRows[] = $recipient;
        $payload[(string) $id] = $meta;
      }
    }

    return [
      'rows' => $outRows,
      'payload' => $payload,
      'total_rows' => count($dataRows),
      'usable_rows' => $usable,
      'matched' => count($outRows),
      'unmatched' => $unmatched,
      'duplicates' => $duplicates,
      'type' => $type,
      'type_label' => (string) ($config['label'] ?? $type),
    ];
  }

  /**
   * @param int[] $ids
   * @param string[] $channels
   * @return array{queued:int,failed:int,invalid:int,filtered:int,selected:int,channels:array<int,string>,type:string}
   */
  public function enqueue(string $type, array $ids, array $channels, string $subject, string $message, string $whatsappTemplate = '', string $emailTemplate = '', array $recipientMetaMap = []): array
  {
    $config = $this->typeConfig($type);
    $channels = $this->sanitizeChannels($channels);
    $whatsappTemplateConfig = $this->whatsappTemplateConfig($whatsappTemplate);
    $emailTemplateConfig = $this->emailTemplateConfig($emailTemplate);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    $subject = trim($subject);
    $message = trim($message);
    $messageText = $this->plainText($message);

    if ($ids === []) {
      throw new \RuntimeException('Selecciona al menos un destinatario.');
    }
    if ($channels === []) {
      throw new \RuntimeException('Selecciona al menos un canal.');
    }
    $recipientMetaMap = $this->sanitizeImportMetaMap($recipientMetaMap);
    $canUseImportDetail = $this->templateCanUseImportDetail($whatsappTemplateConfig, $emailTemplateConfig);
    $hasImportedDetail = $canUseImportDetail && $recipientMetaMap !== [];
    $messageRequired = in_array('sms', $channels, true)
      || (in_array('whatsapp', $channels, true) && $this->whatsappTemplateNeedsMessage($whatsappTemplateConfig) && !$hasImportedDetail)
      || (in_array('email', $channels, true) && $this->emailTemplateNeedsMessage($emailTemplateConfig) && !$hasImportedDetail);
    if ($messageRequired && $messageText === '') {
      throw new \RuntimeException('El mensaje no puede estar vacio.');
    }
    if (in_array('email', $channels, true) && $subject === '') {
      $subject = trim((string) ($emailTemplateConfig['subject'] ?? ''));
    }
    if (in_array('email', $channels, true) && $subject === '') {
      throw new \RuntimeException('El asunto es obligatorio para Email.');
    }
    if (in_array('sms', $channels, true) && mb_strlen(self::SMS_PREFIX . $messageText) > self::SMS_MAX) {
      throw new \RuntimeException('El SMS supera ' . self::SMS_MAX . ' caracteres.');
    }
    if (in_array('whatsapp', $channels, true) && $whatsappTemplateConfig === []) {
      throw new \RuntimeException('Selecciona una plantilla valida para WhatsApp.');
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $recipients = $this->recipients($type, $ids, $recipientMetaMap);
    $batchId = bin2hex(random_bytes(8));
    $queued = 0;
    $failed = 0;
    $invalid = 0;
    $filtered = 0;

    foreach ($recipients as $recipient) {
      foreach ($channels as $channel) {
        if ($this->isBlockedByPreference($recipient, $channel, $type)) {
          $filtered++;
          continue;
        }
        $destination = $this->destination($recipient, $channel);
        if ($destination === '') {
          $invalid++;
          continue;
        }
        $baseMessage = $this->messageForRecipient($message, $recipient, $whatsappTemplateConfig, $emailTemplateConfig);
        $channelNeedsMessage = $channel === 'sms'
          || ($channel === 'whatsapp' && $this->whatsappTemplateNeedsMessage($whatsappTemplateConfig))
          || ($channel === 'email' && $this->emailTemplateNeedsMessage($emailTemplateConfig));
        if ($channelNeedsMessage && $this->plainText($baseMessage) === '') {
          $invalid++;
          continue;
        }
        $resolvedMessage = $this->resolveVariables($baseMessage, $recipient, $config);
        $resolvedSubject = $this->resolveVariables($subject, $recipient, $config);
        if ($channel === 'email') {
          $resolvedMessage = $this->renderEmailTemplate($emailTemplateConfig, $resolvedMessage, $recipient, $config);
        } elseif ($channel === 'sms') {
          $resolvedMessage = self::SMS_PREFIX . $this->plainText($resolvedMessage);
          if (mb_strlen($resolvedMessage) > self::SMS_MAX) {
            $invalid++;
            continue;
          }
        } else {
          $resolvedMessage = $this->plainText($resolvedMessage);
        }
        if ($this->insertQueueRow($channel, $destination, $recipient, $resolvedSubject, $resolvedMessage, $batchId, $whatsappTemplateConfig, $emailTemplateConfig)) {
          $queued++;
        } else {
          $failed++;
        }
      }
    }

    return [
      'queued' => $queued,
      'failed' => $failed,
      'invalid' => $invalid,
      'filtered' => $filtered,
      'selected' => count($ids),
      'channels' => $channels,
      'type' => $type,
    ];
  }

  /**
   * @param array<string,mixed> $file
   * @return array<int,array<int,string>>
   */
  private function parseImportFile(array $file): array
  {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      throw new \RuntimeException('No se pudo subir el archivo.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = (string) ($file['name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) {
      throw new \RuntimeException('El archivo temporal no se puede leer.');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['csv', 'txt'], true)) {
      return $this->parseCsvImportFile($tmp);
    }
    if ($ext === 'xlsx') {
      return $this->parseXlsxImportFile($tmp);
    }
    throw new \RuntimeException('Formato no soportado. Sube un archivo .xlsx o .csv.');
  }

  /** @return array<int,array<int,string>> */
  private function parseCsvImportFile(string $path): array
  {
    $sample = (string) file_get_contents($path, false, null, 0, 4096);
    $semicolon = substr_count($sample, ';');
    $comma = substr_count($sample, ',');
    $delimiter = $semicolon > $comma ? ';' : ',';
    $handle = fopen($path, 'rb');
    if (!is_resource($handle)) {
      throw new \RuntimeException('No se pudo abrir el CSV.');
    }
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
      $rows[] = array_map(static function ($value): string {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return trim($value);
      }, $row);
      if (count($rows) > 3000) {
        break;
      }
    }
    fclose($handle);
    return $rows;
  }

  /** @return array<int,array<int,string>> */
  private function parseXlsxImportFile(string $path): array
  {
    $entries = $this->readZipEntries($path);
    $shared = $this->xlsxSharedStrings($entries);
    $sheetXml = $entries['xl/worksheets/sheet1.xml'] ?? '';
    if (!is_string($sheetXml) || trim($sheetXml) === '') {
      throw new \RuntimeException('No se encontro la primera hoja del XLSX.');
    }
    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet instanceof \SimpleXMLElement) {
      throw new \RuntimeException('No se pudo leer la primera hoja del XLSX.');
    }
    $mainNs = $this->xlsxMainNamespace($sheet);
    $sheetRoot = $mainNs !== '' ? $sheet->children($mainNs) : $sheet;
    $sheetData = $sheetRoot->sheetData ?? null;

    $rows = [];
    foreach (($sheetData ? ($mainNs !== '' ? $sheetData->children($mainNs)->row : $sheetData->row) : []) as $xmlRow) {
      $cells = [];
      $max = -1;
      foreach (($mainNs !== '' ? $xmlRow->children($mainNs)->c : $xmlRow->c) ?? [] as $cell) {
        $ref = (string) ($cell['r'] ?? '');
        $index = $this->xlsxColumnIndex($ref);
        if ($index < 0) {
          $index = count($cells);
        }
        $cells[$index] = $this->xlsxCellValue($cell, $shared);
        $max = max($max, $index);
      }
      if ($max >= 0) {
        $row = [];
        for ($i = 0; $i <= $max; $i++) {
          $row[] = trim((string) ($cells[$i] ?? ''));
        }
        $rows[] = $row;
      }
      if (count($rows) > 3000) {
        break;
      }
    }
    return $rows;
  }

  /** @return array<string,string> */
  private function readZipEntries(string $path): array
  {
    $data = file_get_contents($path);
    if (!is_string($data) || $data === '') {
      throw new \RuntimeException('No se pudo leer el XLSX.');
    }
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) {
      throw new \RuntimeException('El archivo XLSX no tiene estructura ZIP valida.');
    }
    $total = unpack('v', substr($data, $eocd + 10, 2))[1] ?? 0;
    $centralOffset = unpack('V', substr($data, $eocd + 16, 4))[1] ?? 0;
    $entries = [];
    $pos = (int) $centralOffset;
    $length = strlen($data);
    for ($i = 0; $i < (int) $total && $pos + 46 <= $length; $i++) {
      if (substr($data, $pos, 4) !== "PK\x01\x02") {
        break;
      }
      $method = unpack('v', substr($data, $pos + 10, 2))[1] ?? 0;
      $compressedSize = unpack('V', substr($data, $pos + 20, 4))[1] ?? 0;
      $nameLength = unpack('v', substr($data, $pos + 28, 2))[1] ?? 0;
      $extraLength = unpack('v', substr($data, $pos + 30, 2))[1] ?? 0;
      $commentLength = unpack('v', substr($data, $pos + 32, 2))[1] ?? 0;
      $localOffset = unpack('V', substr($data, $pos + 42, 4))[1] ?? 0;
      $name = str_replace('\\', '/', substr($data, $pos + 46, (int) $nameLength));
      $pos += 46 + (int) $nameLength + (int) $extraLength + (int) $commentLength;
      if ($name === '' || substr($data, (int) $localOffset, 4) !== "PK\x03\x04") {
        continue;
      }
      $localNameLength = unpack('v', substr($data, (int) $localOffset + 26, 2))[1] ?? 0;
      $localExtraLength = unpack('v', substr($data, (int) $localOffset + 28, 2))[1] ?? 0;
      $dataStart = (int) $localOffset + 30 + (int) $localNameLength + (int) $localExtraLength;
      $compressed = substr($data, $dataStart, (int) $compressedSize);
      if ((int) $method === 0) {
        $entries[$name] = $compressed;
      } elseif ((int) $method === 8) {
        $inflated = @gzinflate($compressed);
        if (is_string($inflated)) {
          $entries[$name] = $inflated;
        }
      }
    }
    return $entries;
  }

  /** @param array<string,string> $entries @return array<int,string> */
  private function xlsxSharedStrings(array $entries): array
  {
    $xml = $entries['xl/sharedStrings.xml'] ?? '';
    if (!is_string($xml) || trim($xml) === '') {
      return [];
    }
    $sharedXml = simplexml_load_string($xml);
    if (!$sharedXml instanceof \SimpleXMLElement) {
      return [];
    }
    $mainNs = $this->xlsxMainNamespace($sharedXml);
    $root = $mainNs !== '' ? $sharedXml->children($mainNs) : $sharedXml;
    $out = [];
    foreach ($root->si ?? [] as $si) {
      $siChildren = $mainNs !== '' ? $si->children($mainNs) : $si;
      $text = '';
      if (isset($siChildren->t)) {
        $text = (string) $siChildren->t;
      } elseif (isset($siChildren->r)) {
        foreach ($siChildren->r as $run) {
          $runChildren = $mainNs !== '' ? $run->children($mainNs) : $run;
          $text .= (string) ($runChildren->t ?? '');
        }
      }
      $out[] = trim($text);
    }
    return $out;
  }

  /** @param array<int,string> $shared */
  private function xlsxCellValue(\SimpleXMLElement $cell, array $shared): string
  {
    $type = (string) ($cell['t'] ?? '');
    $mainNs = $this->xlsxMainNamespace($cell);
    $children = $mainNs !== '' ? $cell->children($mainNs) : $cell;
    if ($type === 's') {
      $index = (int) ($children->v ?? -1);
      return trim((string) ($shared[$index] ?? ''));
    }
    if ($type === 'inlineStr') {
      $inline = $children->is ?? null;
      $inlineChildren = $inline instanceof \SimpleXMLElement && $mainNs !== '' ? $inline->children($mainNs) : $inline;
      return trim((string) ($inlineChildren->t ?? ''));
    }
    return trim((string) ($children->v ?? ''));
  }

  private function xlsxMainNamespace(\SimpleXMLElement $xml): string
  {
    $namespaces = $xml->getNamespaces(true);
    return (string) ($namespaces['x'] ?? $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  }

  private function xlsxColumnIndex(string $cellRef): int
  {
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
      return -1;
    }
    $letters = strtoupper($m[1]);
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
      $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return $index - 1;
  }

  /**
   * @param array<int,array<int,string>> $rows
   * @return array{0:array<int,string>,1:array<int,array<int,string>>}
   */
  private function normalizeImportRows(array $rows): array
  {
    $headerIndex = -1;
    $headers = [];
    foreach (array_slice($rows, 0, 20, true) as $i => $row) {
      $normalized = array_map(fn($value): string => $this->normalizeImportHeader((string) $value), $row);
      $score = 0;
      foreach ($normalized as $header) {
        if ($this->isImportAlias($header, ['contrato', 'contrato_arrendamiento', 'numero_contrato', 'nro_contrato'])) {
          $score += 2;
        }
        if ($this->isImportAlias($header, ['inmueble', 'inmueble_simi', 'codigo_inmueble', 'id_inmueble', 'simi'])) {
          $score += 2;
        }
        if ($this->isImportAlias($header, ['canon', 'valor_canon', 'canon_arrendamiento', 'valor_arriendo', 'renta'])) {
          $score++;
        }
      }
      if ($score >= 2) {
        $headerIndex = (int) $i;
        $headers = $normalized;
        break;
      }
    }
    if ($headerIndex < 0) {
      return [[], []];
    }
    $dataRows = [];
    foreach (array_slice($rows, $headerIndex + 1) as $row) {
      $hasData = false;
      foreach ($row as $value) {
        if (trim((string) $value) !== '') {
          $hasData = true;
          break;
        }
      }
      if ($hasData) {
        $dataRows[] = $row;
      }
    }
    return [$headers, $dataRows];
  }

  private function normalizeImportHeader(string $value): string
  {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
    return trim($value, '_');
  }

  /** @param string[] $aliases */
  private function isImportAlias(string $header, array $aliases): bool
  {
    return in_array($header, $aliases, true);
  }

  /** @param array<int,string> $headers @param array<int,string> $row @param string[] $aliases */
  private function importValue(array $headers, array $row, array $aliases): string
  {
    foreach ($headers as $index => $header) {
      if ($this->isImportAlias($header, $aliases)) {
        return trim((string) ($row[$index] ?? ''));
      }
    }
    return '';
  }

  /** @param array<int,string> $headers @param array<int,string> $row @return array<string,string> */
  private function importMetaForRow(array $headers, array $row): array
  {
    $contract = $this->normalizeImportIdentifier($this->importValue($headers, $row, ['contrato', 'contrato_arrendamiento', 'numero_contrato', 'nro_contrato']));
    $property = $this->normalizeImportIdentifier($this->importValue($headers, $row, ['inmueble', 'inmueble_simi', 'codigo_inmueble', 'id_inmueble', 'simi']));
    $canonRaw = $this->importValue($headers, $row, ['canon', 'valor_canon', 'canon_arrendamiento', 'valor_arriendo', 'renta']);
    $meta = [
      'contrato_excel' => $contract,
      'inmueble_simi_excel' => $property,
      'canon_excel' => $this->formatImportMoney($canonRaw),
      'canon_excel_raw' => trim($canonRaw),
      'mes_excel' => trim($this->importValue($headers, $row, ['mes', 'periodo', 'mes_canon', 'periodo_canon'])),
      'direccion_excel' => trim($this->importValue($headers, $row, ['direccion', 'direccion_inmueble'])),
    ];
    $meta['detalle_excel'] = $this->importCanonSummary($meta);
    return $meta;
  }

  private function normalizeImportIdentifier(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if (preg_match('/^\d+(?:[.,]0+)?$/', $value) === 1) {
      return (string) ((int) ((float) str_replace(',', '.', $value)));
    }
    return $value;
  }

  private function formatImportMoney(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return 'Sin canon en Excel';
    }
    $clean = preg_replace('/[^\d,.\-]/', '', $value) ?? $value;
    if (preg_match('/,\d{1,2}$/', $clean) === 1) {
      $clean = str_replace('.', '', $clean);
      $clean = str_replace(',', '.', $clean);
    } else {
      $clean = str_replace(',', '', $clean);
    }
    if (is_numeric($clean)) {
      return '$' . number_format((float) $clean, 0, ',', '.');
    }
    return $value;
  }

  /** @param array<string,string> $meta */
  private function importCanonSummary(array $meta): string
  {
    $parts = [];
    $canon = trim((string) ($meta['canon_excel'] ?? ''));
    if ($canon !== '' && $canon !== 'Sin canon en Excel') {
      $parts[] = 'Canon: ' . $canon;
    }
    $contract = trim((string) ($meta['contrato_excel'] ?? ''));
    if ($contract !== '') {
      $parts[] = 'Contrato: #' . $contract;
    }
    $property = trim((string) ($meta['inmueble_simi_excel'] ?? ''));
    if ($property !== '') {
      $parts[] = 'Inmueble SIMI: ' . $property;
    }
    $month = trim((string) ($meta['mes_excel'] ?? ''));
    if ($month !== '') {
      $parts[] = 'Periodo: ' . $month;
    }
    return implode("\n", $parts);
  }

  /** @param array<mixed> $metaMap @return array<string,array<string,string>> */
  private function sanitizeImportMetaMap(array $metaMap): array
  {
    $out = [];
    foreach ($metaMap as $id => $meta) {
      $id = (string) ((int) $id);
      if ($id === '0' || !is_array($meta)) {
        continue;
      }
      $clean = [];
      foreach (['contrato_excel', 'inmueble_simi_excel', 'canon_excel', 'canon_excel_raw', 'mes_excel', 'direccion_excel', 'detalle_excel'] as $key) {
        $clean[$key] = trim(mb_substr((string) ($meta[$key] ?? ''), 0, 500, 'UTF-8'));
      }
      if (($clean['detalle_excel'] ?? '') === '') {
        $clean['detalle_excel'] = $this->importCanonSummary($clean);
      }
      $out[$id] = $clean;
    }
    return $out;
  }

  /** @param array<string,array<string,string>> $metaMap @return array<int,array<string,mixed>> */
  private function recipients(string $type, array $ids, array $metaMap = []): array
  {
    $config = $this->typeConfig($type);
    $table = (string) $config['table'];
    if (!$this->schema->tableExists($table) || $ids === []) {
      return [];
    }
    $columns = $this->resolveColumns($config);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $select = [
      '`_ID`',
      $columns['name'] !== '' ? "`{$columns['name']}` AS nombre" : "CAST(`_ID` AS CHAR) AS nombre",
      $columns['email'] !== '' ? "`{$columns['email']}` AS correo" : "'' AS correo",
      $columns['phone'] !== '' ? "`{$columns['phone']}` AS celular" : "'' AS celular",
      $columns['indicator'] !== '' ? "`{$columns['indicator']}` AS indicativo" : "'' AS indicativo",
    ];
    foreach (['bloqueo_email', 'bloqueo_sms', 'bloqueo_whatsapp'] as $preferenceColumn) {
      if ($this->schema->columnExists($table, $preferenceColumn)) {
        $select[] = "`{$preferenceColumn}`";
      }
    }
    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE `_ID` IN ({$placeholders})",
      $ids
    );
    foreach ($rows as &$row) {
      $row['tipo_actor'] = $type;
      $row['tipo_label'] = (string) $config['label'];
      $row['rol_persona'] = (string) $config['role'];
      $id = (string) ((int) ($row['_ID'] ?? 0));
      if ($id !== '0' && isset($metaMap[$id])) {
        $row['_scm_import_meta'] = $metaMap[$id];
      }
    }
    unset($row);
    return $rows;
  }

  private function isBlockedByPreference(array $recipient, string $channel, string $type): bool
  {
    if ($type === 'funcionarios') {
      return false;
    }
    $field = match ($channel) {
      'sms' => 'bloqueo_sms',
      'whatsapp' => 'bloqueo_whatsapp',
      default => 'bloqueo_email',
    };
    return array_key_exists($field, $recipient) && (int) ($recipient[$field] ?? 0) === 1;
  }

  private function sanitizeContractStatus(string $status): string
  {
    $status = strtolower(trim($status));
    return in_array($status, ['activos', 'no_activos'], true) ? $status : '';
  }

  /** @param array<string,mixed> $config */
  private function effectiveContractStatus(array $config, string $status): string
  {
    $fixed = $this->sanitizeContractStatus((string) ($config['contract_status_fixed'] ?? ''));
    return $fixed !== '' ? $fixed : $this->sanitizeContractStatus($status);
  }

  /** @param array<string,string> $fieldFilters @return array{name:string,email:string,phone:string,document:string} */
  private function normalizeFieldFilters(array $fieldFilters): array
  {
    return [
      'name' => trim((string) ($fieldFilters['name'] ?? '')),
      'email' => trim((string) ($fieldFilters['email'] ?? '')),
      'phone' => trim((string) ($fieldFilters['phone'] ?? '')),
      'document' => trim((string) ($fieldFilters['document'] ?? '')),
    ];
  }

  /**
   * @param array<int,mixed> $args
   * @param array<string,mixed> $config
   * @param array<string,string> $fieldFilters
   */
  private function applyFieldTextFilters(string &$where, array &$args, string $table, array $config, array $fieldFilters): void
  {
    $map = [
      'name' => (array) ($config['name'] ?? []),
      'email' => (array) ($config['email'] ?? []),
      'phone' => (array) ($config['phone'] ?? []),
      'document' => (array) ($config['document'] ?? []),
    ];

    foreach ($map as $filterKey => $candidates) {
      $value = trim((string) ($fieldFilters[$filterKey] ?? ''));
      if ($value === '') {
        continue;
      }
      $columns = $this->detectMany($table, $candidates);
      if ($columns === []) {
        continue;
      }
      $parts = [];
      $like = '%' . $this->db->escapeLike($value) . '%';
      foreach ($columns as $column) {
        $parts[] = $this->collatedTextSql("COALESCE(`{$column}`, '')") . ' LIKE ?';
        $args[] = $like;
      }
      $where .= ' AND (' . implode(' OR ', $parts) . ')';
    }
  }

  /** @param array<string,mixed> $config @param array<int,mixed> $args */
  private function applyContractFilters(string &$where, array &$args, string $type, array $config, string $contractStatus, string $inmuebleSimi = '', string $contractNumber = ''): void
  {
    $inmuebleSimi = trim($inmuebleSimi);
    $contractNumber = trim($contractNumber);
    if (($contractStatus === '' && $inmuebleSimi === '' && $contractNumber === '') || empty($config['contract_actor_column'])) {
      return;
    }
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $actorTable = (string) $config['table'];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if (
      $contractActorColumn === ''
      || !$this->schema->tableExists($contractTable)
      || !$this->schema->columnExists($contractTable, $contractActorColumn)
      || !$this->schema->columnExists($contractTable, 'estado')
    ) {
      return;
    }

    $existsConditions = [
      $this->contractActorComparisonSql($actorTable, $contractActorColumn, $config),
    ];
    $existsArgs = [];
    if ($contractStatus !== '') {
      $existsConditions[] = "LOWER(TRIM(COALESCE(ca.`estado`, ''))) = ?";
      $existsArgs[] = $contractStatus === 'activos' ? 'entregado' : 'recibido';
    }
    if ($inmuebleSimi !== '') {
      $inmuebleParts = [];
      foreach (['inmueble', 'id_inmueble', 'id_inmueble_data'] as $column) {
        if ($this->schema->columnExists($contractTable, $column)) {
          $inmuebleParts[] = $this->collatedTextSql("COALESCE(ca.`{$column}`, '')") . " LIKE ?";
          $existsArgs[] = '%' . $this->db->escapeLike($inmuebleSimi) . '%';
        }
      }
      if ($inmuebleParts !== []) {
        $existsConditions[] = '(' . implode(' OR ', $inmuebleParts) . ')';
      }
    }
    if ($contractNumber !== '') {
      $contractParts = [];
      foreach (['contrato', 'contrato_arrendamiento', '_ID'] as $column) {
        if ($this->schema->columnExists($contractTable, $column)) {
          $contractParts[] = $this->collatedTextSql("COALESCE(ca.`{$column}`, '')") . " LIKE ?";
          $existsArgs[] = '%' . $this->db->escapeLike($contractNumber) . '%';
        }
      }
      if ($contractParts !== []) {
        $existsConditions[] = '(' . implode(' OR ', $contractParts) . ')';
      }
    }

    $where .= " AND EXISTS (
      SELECT 1
        FROM `{$contractTable}` ca
       WHERE " . implode(' AND ', $existsConditions) . "
       LIMIT 1
    )";
    array_push($args, ...$existsArgs);

    if ($contractStatus === 'no_activos') {
      $where .= " AND NOT EXISTS (
        SELECT 1
          FROM `{$contractTable}` ca
         WHERE {$this->contractActorComparisonSql($actorTable, $contractActorColumn, $config)}
           AND LOWER(TRIM(COALESCE(ca.`estado`, ''))) = ?
         LIMIT 1
      )";
      $args[] = 'entregado';
    }
  }

  /**
   * @param array<string,mixed> $config
   * @param array<string,mixed> $recipient
   * @return array{label:string,summary:string}
   */
  private function contractActivityInfo(string $type, array $config, array $recipient, string $contractStatus = '', string $inmuebleSimi = '', string $contractNumber = ''): array
  {
    if (empty($config['contract_actor_column'])) {
      return ['label' => '', 'summary' => ''];
    }
    $contractStatus = $this->sanitizeContractStatus($contractStatus);
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    if (
      $contractActorColumn === ''
      || !$this->schema->tableExists($contractTable)
      || !$this->schema->columnExists($contractTable, $contractActorColumn)
      || !$this->schema->columnExists($contractTable, 'estado')
    ) {
      return ['label' => '', 'summary' => ''];
    }

    [$matchWhere, $matchArgs] = $this->contractRecipientMatch($config, $recipient);
    if ($matchWhere === '') {
      return ['label' => 'Sin contrato', 'summary' => ''];
    }
    $extraWhere = '';
    $extraArgs = [];
    $inmuebleSimi = trim($inmuebleSimi);
    if ($inmuebleSimi !== '') {
      $inmuebleParts = [];
      foreach (['inmueble', 'id_inmueble', 'id_inmueble_data'] as $column) {
        if ($this->schema->columnExists($contractTable, $column)) {
          $inmuebleParts[] = $this->collatedTextSql("COALESCE(`{$column}`, '')") . " LIKE ?";
          $extraArgs[] = '%' . $this->db->escapeLike($inmuebleSimi) . '%';
        }
      }
      if ($inmuebleParts !== []) {
        $extraWhere .= ' AND (' . implode(' OR ', $inmuebleParts) . ')';
      }
    }
    $contractNumber = trim($contractNumber);
    if ($contractNumber !== '') {
      $contractParts = [];
      foreach (['contrato', 'contrato_arrendamiento', '_ID'] as $column) {
        if ($this->schema->columnExists($contractTable, $column)) {
          $contractParts[] = $this->collatedTextSql("COALESCE(`{$column}`, '')") . " LIKE ?";
          $extraArgs[] = '%' . $this->db->escapeLike($contractNumber) . '%';
        }
      }
      if ($contractParts !== []) {
        $extraWhere .= ' AND (' . implode(' OR ', $contractParts) . ')';
      }
    }

    $rows = $this->db->getResults(
      "SELECT LOWER(TRIM(COALESCE(`estado`, ''))) AS estado,
              COUNT(1) AS total,
              GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(`contrato`), ''), NULLIF(TRIM(`contrato_arrendamiento`), ''), CAST(`_ID` AS CHAR)) ORDER BY `_ID` DESC SEPARATOR ', ') AS contratos,
              GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(`inmueble`), ''), NULLIF(TRIM(`id_inmueble`), ''), NULLIF(TRIM(`id_inmueble_data`), '')) ORDER BY `_ID` DESC SEPARATOR ', ') AS inmuebles
         FROM `{$contractTable}`
        WHERE ({$matchWhere})
          {$extraWhere}
          AND LOWER(TRIM(COALESCE(`estado`, ''))) IN ('entregado', 'recibido')
        GROUP BY LOWER(TRIM(COALESCE(`estado`, '')))",
      array_merge($matchArgs, $extraArgs)
    );

    $hasDelivered = false;
    $hasReceived = false;
    $contracts = [];
    $properties = [];
    foreach ($rows as $row) {
      $state = trim((string) ($row['estado'] ?? ''));
      $hasDelivered = $hasDelivered || $state === 'entregado';
      $hasReceived = $hasReceived || $state === 'recibido';
      foreach (explode(',', (string) ($row['contratos'] ?? '')) as $contract) {
        $contract = trim($contract);
        if ($contract !== '') {
          $contracts[$contract] = $contract;
        }
      }
      foreach (explode(',', (string) ($row['inmuebles'] ?? '')) as $property) {
        $property = trim($property);
        if ($property !== '') {
          $properties[$property] = $property;
        }
      }
    }
    $summary = $this->contractSummary(array_values($contracts), array_values($properties));
    if ($hasDelivered) {
      return ['label' => 'Activo', 'summary' => $summary];
    }
    if ($hasReceived) {
      return ['label' => 'No activo', 'summary' => $summary];
    }
    return ['label' => 'Sin contrato', 'summary' => ''];
  }

  /**
   * @param array<string,mixed> $config
   * @param array<string,mixed> $recipient
   * @return array{0:string,1:array<int,mixed>}
   */
  private function contractRecipientMatch(array $config, array $recipient): array
  {
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    $parts = [];
    $args = [];
    $ids = array_values(array_unique(array_filter(array_map(
      static fn($value): int => (int) preg_replace('/\D+/', '', (string) $value),
      [$recipient['_ID'] ?? '', $recipient['contrato_actor_ref'] ?? '']
    ), static fn(int $id): bool => $id > 0)));
    if ($contractActorColumn !== '' && $this->schema->columnExists($contractTable, $contractActorColumn) && $ids !== []) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $parts[] = "CAST(`{$contractActorColumn}` AS UNSIGNED) IN ({$placeholders})";
      array_push($args, ...$ids);
    }

    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      $contractColumn = trim((string) $contractColumn);
      $value = trim((string) ($recipient["contrato_ref_{$actorColumn}"] ?? ''));
      if (
        $actorColumn === ''
        || $contractColumn === ''
        || $value === ''
        || !$this->schema->columnExists($contractTable, $contractColumn)
      ) {
        continue;
      }
      if (preg_match('/^id_/', $actorColumn) === 1 || preg_match('/^id_/', $contractColumn) === 1) {
        $numeric = (int) preg_replace('/\D+/', '', $value);
        if ($numeric <= 0) {
          continue;
        }
        $parts[] = "CAST(`{$contractColumn}` AS UNSIGNED) = ?";
        $args[] = $numeric;
      } else {
        $parts[] = "LOWER(" . $this->collatedTextSql("TRIM(COALESCE(`{$contractColumn}`, ''))") . ") = LOWER(CONVERT(TRIM(?) USING utf8mb4) COLLATE utf8mb4_unicode_ci)";
        $args[] = $value;
      }
    }

    return $parts === [] ? ['', []] : ['(' . implode(' OR ', $parts) . ')', $args];
  }

  /** @param string[] $contracts @param string[] $properties */
  private function contractSummary(array $contracts, array $properties = []): string
  {
    $contracts = array_values(array_unique(array_filter(array_map(
      static fn($contract): string => trim((string) $contract),
      $contracts
    ), static fn(string $contract): bool => $contract !== '')));
    $properties = array_values(array_unique(array_filter(array_map(
      static fn($property): string => trim((string) $property),
      $properties
    ), static fn(string $property): bool => $property !== '')));
    $visible = array_slice($contracts, 0, 4);
    $summary = $visible !== [] ? 'Contrato: #' . implode(', #', $visible) : '';
    $remaining = count($contracts) - count($visible);
    if ($remaining > 0) {
      $summary .= ' +' . $remaining . ' mas';
    }
    if ($properties !== []) {
      $summary .= ($summary !== '' ? ' · ' : '') . 'Inmueble SIMI: ' . implode(', ', array_slice($properties, 0, 3));
      $remainingProperties = count($properties) - min(3, count($properties));
      if ($remainingProperties > 0) {
        $summary .= ' +' . $remainingProperties . ' mas';
      }
    }
    return $summary;
  }

  /** @param array<string,mixed> $config */
  private function contractActorComparisonSql(string $actorTable, string $contractActorColumn, array $config = []): string
  {
    $parts = [
      "CAST(ca.`{$contractActorColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`_ID` AS UNSIGNED)",
    ];
    if ($this->schema->columnExists($actorTable, $contractActorColumn)) {
      $parts[] = "CAST(ca.`{$contractActorColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`{$contractActorColumn}` AS UNSIGNED)";
    }
    foreach ((array) ($config['contract_match_columns'] ?? []) as $actorColumn => $contractColumn) {
      $actorColumn = trim((string) $actorColumn);
      $contractColumn = trim((string) $contractColumn);
      if (
        $actorColumn === ''
        || $contractColumn === ''
        || !$this->schema->columnExists($actorTable, $actorColumn)
        || !$this->schema->columnExists($this->db->table('jet_cct_contratos_arrendamiento'), $contractColumn)
      ) {
        continue;
      }
      if (preg_match('/^id_/', $actorColumn) === 1 || preg_match('/^id_/', $contractColumn) === 1) {
        $parts[] = "CAST(ca.`{$contractColumn}` AS UNSIGNED) = CAST(`{$actorTable}`.`{$actorColumn}` AS UNSIGNED)";
      } else {
        $caExpr = $this->collatedTextSql("TRIM(COALESCE(ca.`{$contractColumn}`, ''))");
        $actorExpr = $this->collatedTextSql("TRIM(COALESCE(`{$actorTable}`.`{$actorColumn}`, ''))");
        $parts[] = "TRIM(COALESCE(ca.`{$contractColumn}`, '')) <> '' AND TRIM(COALESCE(`{$actorTable}`.`{$actorColumn}`, '')) <> '' AND LOWER({$caExpr}) = LOWER({$actorExpr})";
      }
    }
    return '(' . implode(' OR ', $parts) . ')';
  }

  private function collatedTextSql(string $expression): string
  {
    return "CONVERT({$expression} USING utf8mb4) COLLATE utf8mb4_unicode_ci";
  }

  /** @param array<string,mixed> $whatsappTemplateConfig */
  private function insertQueueRow(string $channel, string $destination, array $recipient, string $subject, string $message, string $batchId, array $whatsappTemplateConfig = [], array $emailTemplateConfig = []): bool
  {
    $now = gmdate('Y-m-d H:i:s');
    $actorId = (int) ($recipient['_ID'] ?? 0);
    $type = (string) ($recipient['tipo_actor'] ?? '');
    $name = trim((string) ($recipient['nombre'] ?? ''));
    $provider = match ($channel) {
      'sms' => 'sms_onurix',
      'whatsapp' => 'whatsapp_official',
      default => 'email_smtp',
    };
    $templateName = '';
    $templateLanguage = '';
    $payload = [];
    if ($channel === 'whatsapp') {
      $templateName = trim((string) ($whatsappTemplateConfig['name'] ?? ''));
      $templateLanguage = trim((string) ($whatsappTemplateConfig['language'] ?? 'es_CO')) ?: 'es_CO';
      $components = [
        [
          'type' => 'body',
          'parameters' => $this->whatsappTemplateParameters($whatsappTemplateConfig, $name, $message, $recipient),
        ],
      ];
      $payload = [
        'type' => 'template',
        'template_name' => $templateName,
        'template_language' => $templateLanguage,
        'components' => $components,
      ];
    } elseif ($channel === 'email') {
      $templateName = trim((string) ($emailTemplateConfig['name'] ?? ''));
    }
    $meta = [
      'admin_notifications' => [
        'batch_id' => $batchId,
        'id_actor' => $actorId,
        'tipo_actor' => $type,
        'tipo_label' => (string) ($recipient['tipo_label'] ?? ''),
        'rol_persona' => (string) ($recipient['rol_persona'] ?? ''),
        'id_funcionario' => Auth::userId(),
        'nombre_funcionario' => Auth::user(),
        'source_module' => self::SOURCE_MODULE,
        'whatsapp_template' => $channel === 'whatsapp' ? $templateName : '',
        'email_template' => $channel === 'email' ? $templateName : '',
        'import_meta' => is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [],
      ],
    ];
    $data = [
      'project_code' => self::PROJECT_CODE,
      'source_module' => self::SOURCE_MODULE,
      'channel' => $channel,
      'provider' => $provider,
      'destination' => $destination,
      'destination_name' => $name,
      'subject' => $channel === 'email' ? $subject : '',
      'message_html' => $channel === 'email' ? $this->queueEmailHtml($subject, $message) : '',
      'message_text' => trim(html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8')),
      'template_name' => $templateName,
      'template_language' => $templateLanguage,
      'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'status' => 'pending',
      'priority' => 100,
      'max_attempts' => 3,
      'scheduled_at' => $now,
      'created_at' => $now,
      'updated_at' => $now,
      'created_by' => self::PROJECT_CODE,
      'dedupe_key' => implode(':', [self::PROJECT_CODE, self::SOURCE_MODULE, $batchId, $channel, $type, (string) $actorId]),
    ];

    try {
      $filtered = $this->schema->filterTableData(self::QUEUE_TABLE, $data);
      return $filtered !== [] && $this->db->insert(self::QUEUE_TABLE, $filtered);
    } catch (\Throwable) {
      return false;
    }
  }

  /** @return array<string,mixed> */
  private function whatsappTemplateConfig(string $templateName): array
  {
    $templateName = trim($templateName);
    if ($templateName === '') {
      $templateName = self::DEFAULT_WHATSAPP_TEMPLATE;
    }
    $templates = $this->whatsappTemplates();
    return is_array($templates[$templateName] ?? null) ? $templates[$templateName] : [];
  }

  /** @return array<string,mixed> */
  private function emailTemplateConfig(string $templateName): array
  {
    $templateName = trim($templateName);
    if ($templateName === '') {
      $templateName = self::DEFAULT_EMAIL_TEMPLATE;
    }
    $templates = $this->emailTemplates();
    return is_array($templates[$templateName] ?? null) ? $templates[$templateName] : $templates[self::DEFAULT_EMAIL_TEMPLATE];
  }

  /** @param array<string,mixed> $templateConfig */
  private function whatsappTemplateNeedsMessage(array $templateConfig): bool
  {
    return (string) ($templateConfig['parameter_mode'] ?? 'name_message_signature') === 'name_message_signature';
  }

  /** @param array<string,mixed> $whatsappTemplateConfig @param array<string,mixed> $emailTemplateConfig */
  private function templateCanUseImportDetail(array $whatsappTemplateConfig, array $emailTemplateConfig): bool
  {
    return (string) ($whatsappTemplateConfig['name'] ?? '') === 'scm_propietario_arriendo_consignado_v1'
      || (string) ($emailTemplateConfig['name'] ?? '') === 'scm_email_propietario_arriendo_consignado_v1';
  }

  /** @param array<string,mixed> $recipient @param array<string,mixed> $whatsappTemplateConfig @param array<string,mixed> $emailTemplateConfig */
  private function messageForRecipient(string $message, array $recipient, array $whatsappTemplateConfig, array $emailTemplateConfig): string
  {
    if (!$this->templateCanUseImportDetail($whatsappTemplateConfig, $emailTemplateConfig)) {
      return $message;
    }
    $meta = is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [];
    if ($meta === []) {
      return $message;
    }
    $detail = $this->importCanonSummary($this->sanitizeImportMetaMap(['1' => $meta])['1'] ?? []);
    return $detail !== '' ? $detail : $message;
  }

  /** @param array<string,mixed> $templateConfig @return array<int,array{type:string,text:string}> */
  private function whatsappTemplateParameters(array $templateConfig, string $recipientName, string $message, array $recipient = []): array
  {
    $name = trim($recipientName) !== '' ? trim($recipientName) : 'Cliente';
    $signature = (string) ($this->senderProfile()['signature_line'] ?? 'Control Servicios Inmobiliarios');
    $mode = (string) ($templateConfig['parameter_mode'] ?? 'name_message_signature');
    if ($mode === 'name_signature') {
      return [
        ['type' => 'text', 'text' => $name],
        ['type' => 'text', 'text' => $signature],
      ];
    }
    return [
      ['type' => 'text', 'text' => $name],
      ['type' => 'text', 'text' => $message],
      ['type' => 'text', 'text' => $signature],
    ];
  }

  /**
   * @return array<string,array{name:string,label:string,subject:string,body:string,description:string,source:string,message_only:bool,editable_message:string}>
   */
  private function storedEmailTemplates(): array
  {
    $table = $this->db->table('jet_cct_plantillas');
    if (!$this->schema->tableExists($table)) {
      return [];
    }

    $columns = array_flip($this->schema->getTableColumns($table));
    $select = ['_ID'];
    foreach (['nombre', 'tipo', 'asunto', 'contenido', 'cct_status'] as $column) {
      if (isset($columns[$column])) {
        $select[] = $column;
      }
    }

    $where = '1=1';
    $args = [];
    if (isset($columns['tipo'])) {
      $where .= " AND LOWER(TRIM(COALESCE(`tipo`, 'email'))) = 'email'";
    }
    if (isset($columns['cct_status'])) {
      $where .= " AND LOWER(TRIM(COALESCE(`cct_status`, 'publish'))) <> 'trash'";
    }

    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', $select) . "` FROM `{$table}` WHERE {$where} ORDER BY `_ID` DESC LIMIT 200",
      $args
    );

    $out = [];
    foreach ($rows as $row) {
      $id = (int) ($row['_ID'] ?? 0);
      $label = trim((string) ($row['nombre'] ?? ''));
      $body = $this->normalizeEmailTemplateBody(trim((string) ($row['contenido'] ?? '')));
      if ($id <= 0 || $label === '' || $body === '') {
        continue;
      }
      $hasMessageSlot = str_contains($body, '{{mensaje}}') || str_contains($body, '{{custom_message}}');
      $isFullDocument = $this->isFullEmailHtml($body);
      $plainExcerpt = preg_replace('/\s+/', ' ', $this->plainText($body)) ?? '';
      $plainExcerpt = trim(mb_substr($plainExcerpt, 0, 220, 'UTF-8'));
      $name = 'tpl_email_' . $id;
      $out[$name] = [
        'name' => $name,
        'label' => $label,
        'subject' => trim((string) ($row['asunto'] ?? '')),
        'description' => $hasMessageSlot
          ? 'Plantilla guardada con zona editable {{mensaje}}.'
          : ($isFullDocument ? 'Plantilla HTML completa: se usa su diseno guardado y no se pega codigo en el mensaje.' : 'Plantilla guardada en el gestor de plantillas.'),
        'body' => $body,
        'source' => 'jet_cct_plantillas',
        'message_only' => $hasMessageSlot,
        'editable_message' => ($hasMessageSlot || $isFullDocument) ? '' : $body,
        'is_html' => $body !== strip_tags($body),
        'is_full_document' => $isFullDocument,
        'preview_excerpt' => $plainExcerpt !== '' ? $plainExcerpt : 'Plantilla HTML guardada.',
      ];
    }

    return $out;
  }

  private function normalizeEmailTemplateBody(string $body): string
  {
    if ($body === '') {
      return '';
    }

    $replacements = [
      "Atentamente,\nControl Servicios Inmobiliarios" => '{{firma_funcionario}}',
      "Atentamente,\r\nControl Servicios Inmobiliarios" => '{{firma_funcionario}}',
      "Atentamente,<br>Control Servicios Inmobiliarios" => '{{firma_funcionario}}',
      "Atentamente,<br />Control Servicios Inmobiliarios" => '{{firma_funcionario}}',
      "Atentamente,<br/>Control Servicios Inmobiliarios" => '{{firma_funcionario}}',
      "Atentamente, Control Servicios Inmobiliarios" => '{{firma_funcionario}}',
    ];

    return strtr($body, $replacements);
  }

  /** @param array<string,mixed> $templateConfig @param array<string,mixed> $config */
  private function renderEmailTemplate(array $templateConfig, string $message, array $recipient, array $config): string
  {
    $body = $this->normalizeEmailTemplateBody(trim((string) ($templateConfig['body'] ?? '')));
    if ($body === '') {
      $body = "{{mensaje}}";
    }
    if (!empty($templateConfig['fixed_body'])) {
      return $this->resolveVariables($body, $recipient, $config);
    }
    if (!str_contains($body, '{{mensaje}}') && !str_contains($body, '{{custom_message}}')) {
      if (!empty($templateConfig['is_full_document']) || $this->isFullEmailHtml($body)) {
        return $this->resolveVariables($body, $recipient, $config);
      }
      return $this->resolveVariables($message !== '' ? $message : $body, $recipient, $config);
    }
    $messageForEmail = $body !== strip_tags($body) ? $this->emailContentHtml($message) : $message;
    $body = str_replace(['{{mensaje}}', '{{custom_message}}'], $messageForEmail, $body);
    return $this->resolveVariables($body, $recipient, $config);
  }

  /** @param array<string,mixed> $templateConfig */
  private function emailTemplateNeedsMessage(array $templateConfig): bool
  {
    if (array_key_exists('requires_message', $templateConfig)) {
      return (bool) $templateConfig['requires_message'];
    }
    $body = trim((string) ($templateConfig['body'] ?? ''));
    if ($body === '') {
      return true;
    }
    if (str_contains($body, '{{mensaje}}') || str_contains($body, '{{custom_message}}')) {
      return true;
    }
    return !(!empty($templateConfig['is_full_document']) || $this->isFullEmailHtml($body));
  }

  private function queueEmailHtml(string $subject, string $message): string
  {
    return $this->isFullEmailHtml($message)
      ? $this->sanitizeFullEmailHtml($message)
      : EmailTemplate::render($subject, $this->emailContentHtml($message));
  }

  private function isFullEmailHtml(string $value): bool
  {
    $value = trim($value);
    if ($value === '') {
      return false;
    }
    return preg_match('/<!doctype\s+html|<html[\s>]|<body[\s>]/i', $value) === 1;
  }

  private function sanitizeFullEmailHtml(string $value): string
  {
    $value = preg_replace('@<(script|iframe|object|embed)[^>]*?>.*?</\\1>@si', '', $value) ?? $value;
    $value = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\\1/si', '', $value) ?? $value;
    $value = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:[\s\S]*?\\2/si', '', $value) ?? $value;
    return trim($value);
  }

  private function plainText(string $value): string
  {
    return trim(html_entity_decode(wp_strip_all_tags($value, false), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  private function emailContentHtml(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if ($value !== strip_tags($value)) {
      return wp_kses_post($value);
    }
    return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
  }

  /** @return string[] */
  private function sanitizeChannels(array $channels): array
  {
    $out = [];
    foreach ($channels as $channel) {
      $channel = strtolower(trim((string) $channel));
      if (in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
        $out[$channel] = $channel;
      }
    }
    return array_values($out);
  }

  private function destination(array $recipient, string $channel): string
  {
    if ($channel === 'email') {
      $email = trim((string) ($recipient['correo'] ?? ''));
      return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
    $phone = $this->normalizePhone((string) ($recipient['celular'] ?? ''), (string) ($recipient['indicativo'] ?? ''), $channel === 'whatsapp');
    return $phone;
  }

  private function normalizePhone(string $phone, string $indicator = '', bool $withPlus = false): string
  {
    $phone = trim($phone);
    if ($phone === '') {
      return '';
    }
    if ($withPlus && (str_contains($phone, '@g.us') || str_contains($phone, '@s.whatsapp.net'))) {
      return $phone;
    }
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits === '') {
      return '';
    }
    $prefix = preg_replace('/\D+/', '', $indicator) ?: '';
    if ($prefix !== '' && !str_starts_with($digits, $prefix) && strlen($digits) <= 10) {
      $digits = $prefix . $digits;
    }
    if ($prefix === '' && strlen($digits) <= 10) {
      $digits = '57' . $digits;
    }
    return $withPlus ? ('+' . ltrim($digits, '+')) : $digits;
  }

  /** @param array<string,mixed> $config */
  private function resolveVariables(string $template, array $recipient, array $config): string
  {
    $sender = $this->senderProfile();
    $importMeta = is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [];
    $canonExcel = trim((string) ($importMeta['canon_excel'] ?? ''));
    $contractExcel = trim((string) ($importMeta['contrato_excel'] ?? ''));
    $propertyExcel = trim((string) ($importMeta['inmueble_simi_excel'] ?? ''));
    $monthExcel = trim((string) ($importMeta['mes_excel'] ?? ''));
    $addressExcel = trim((string) ($importMeta['direccion_excel'] ?? ''));
    $map = [
      '{{nombre}}' => trim((string) ($recipient['nombre'] ?? '')),
      '{{correo}}' => trim((string) ($recipient['correo'] ?? '')),
      '{{celular}}' => trim((string) ($recipient['celular'] ?? '')),
      '{{tipo_actor}}' => trim((string) ($recipient['tipo_label'] ?? $config['label'] ?? '')),
      '{{rol_persona}}' => trim((string) ($recipient['rol_persona'] ?? $config['role'] ?? '')),
      '{{funcionario}}' => $sender['name'],
      '{{cargo_funcionario}}' => $sender['cargo'],
      '{{celular_funcionario}}' => $sender['phone'],
      '{{firma_funcionario}}' => $sender['signature'],
      '{{firma_funcionario_linea}}' => $sender['signature_line'],
      '{{canon_excel}}' => $canonExcel !== '' ? $canonExcel : 'Sin canon en Excel',
      '{{valor_canon}}' => $canonExcel !== '' ? $canonExcel : 'Sin canon en Excel',
      '{{canon}}' => $canonExcel !== '' ? $canonExcel : 'Sin canon en Excel',
      '{{contrato_excel}}' => $contractExcel !== '' ? $contractExcel : 'Sin contrato en Excel',
      '{{inmueble_simi_excel}}' => $propertyExcel !== '' ? $propertyExcel : 'Sin inmueble SIMI en Excel',
      '{{mes_excel}}' => $monthExcel,
      '{{direccion_excel}}' => $addressExcel,
      '{{detalle_excel}}' => trim((string) ($importMeta['detalle_excel'] ?? '')),
      '{{mes_excel_linea}}' => $monthExcel !== '' ? '<br>Periodo: <strong>' . htmlspecialchars($monthExcel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>' : '',
      '{{direccion_excel_linea}}' => $addressExcel !== '' ? '<br>Direcci&oacute;n: <strong>' . htmlspecialchars($addressExcel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>' : '',
    ];
    return strtr($template, $map);
  }

  /** @return array{name:string,cargo:string,phone:string,signature:string,signature_line:string} */
  public function senderProfile(): array
  {
    if ($this->senderProfile !== null) {
      return $this->senderProfile;
    }

    $name = trim(Auth::user());
    $cargo = trim(Auth::userRol());
    $phone = '';
    $userId = Auth::userId();
    $funcTable = $this->db->table('jet_cct_funcionarios');
    if ($userId > 0 && $this->schema->tableExists($funcTable)) {
      $select = [];
      foreach (['nombre', 'rol', 'id_cargo', 'celular', 'celular_empleado', 'telefono', 'whatsapp'] as $column) {
        if ($this->schema->columnExists($funcTable, $column)) {
          $select[] = "`{$column}`";
        }
      }
      if ($select !== []) {
        $row = $this->db->getRow('SELECT ' . implode(', ', $select) . " FROM `{$funcTable}` WHERE `_ID` = ? LIMIT 1", [$userId]) ?: [];
        $name = trim((string) ($row['nombre'] ?? $name)) ?: $name;
        $phone = $this->firstNonEmpty([
          $row['celular'] ?? '',
          $row['celular_empleado'] ?? '',
          $row['telefono'] ?? '',
          $row['whatsapp'] ?? '',
        ]);
        $cargoId = trim((string) ($row['id_cargo'] ?? Auth::userCargo()));
        $cargo = trim((string) ($row['rol'] ?? $cargo)) ?: $cargo;
        $cargoName = $this->cargoName($cargoId);
        if ($cargoName !== '') {
          $cargo = $cargoName;
        }
      }
    }

    if ($name === '') {
      $name = 'Funcionario';
    }
    if ($cargo === '') {
      $cargo = 'Control Servicios Inmobiliarios';
    }

    $signatureLineParts = [$name, $cargo];
    if ($phone !== '') {
      $signatureLineParts[] = 'Cel. ' . $phone;
    }
    $signatureLine = implode(' - ', array_filter($signatureLineParts, static fn(string $value): bool => trim($value) !== ''));

    $this->senderProfile = [
      'name' => $name,
      'cargo' => $cargo,
      'phone' => $phone,
      'signature' => "Atentamente,\n" . $signatureLine,
      'signature_line' => $signatureLine,
    ];

    return $this->senderProfile;
  }

  private function cargoName(string $cargoId): string
  {
    $cargoId = trim($cargoId);
    if ($cargoId === '') {
      return '';
    }
    $table = $this->db->table('jet_cct_cargos');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'nombre_cargo')) {
      return '';
    }
    return trim((string) ($this->db->getVar("SELECT TRIM(COALESCE(`nombre_cargo`, '')) FROM `{$table}` WHERE CAST(`_ID` AS CHAR) = ? LIMIT 1", [$cargoId]) ?? ''));
  }

  /** @param array<int,mixed> $values */
  private function firstNonEmpty(array $values): string
  {
    foreach ($values as $value) {
      $value = trim((string) $value);
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  /** @return array<string,mixed> */
  private function typeConfig(string $type): array
  {
    $types = $this->types();
    $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'propietarios';
    if (!isset($types[$type])) {
      throw new \RuntimeException('Tipo de destinatario no valido.');
    }
    return $types[$type] + ['key' => $type];
  }

  /** @param array<string,mixed> $config */
  private function baseWhere(array $config): string
  {
    $table = (string) $config['table'];
    if (!empty($config['only_active']) && $this->schema->columnExists($table, 'activo')) {
      return "(TRIM(COALESCE(`activo`, '')) = '' OR LOWER(TRIM(COALESCE(`activo`, ''))) IN ('si', '1', 'true', 'activo'))";
    }
    return '1=1';
  }

  /** @param array<string,mixed> $config @return array{name:string,email:string,phone:string,indicator:string} */
  private function resolveColumns(array $config): array
  {
    $table = (string) $config['table'];
    return [
      'name' => $this->detect($table, (array) $config['name']),
      'email' => $this->detect($table, (array) $config['email']),
      'phone' => $this->detect($table, (array) $config['phone']),
      'indicator' => $this->detect($table, (array) $config['indicator']),
    ];
  }

  /** @param string[] $candidates */
  private function detect(string $table, array $candidates): string
  {
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '' && $this->schema->columnExists($table, $candidate)) {
        return $candidate;
      }
    }
    return '';
  }

  /** @param string[] $candidates @return string[] */
  private function detectMany(string $table, array $candidates): array
  {
    $columns = [];
    foreach ($candidates as $candidate) {
      $candidate = trim((string) $candidate);
      if ($candidate !== '' && $this->schema->columnExists($table, $candidate)) {
        $columns[$candidate] = $candidate;
      }
    }
    return array_values($columns);
  }

  /** @param array<string,mixed> $config @return string[] */
  private function searchColumns(string $table, array $config): array
  {
    $columns = [];
    foreach ((array) ($config['search'] ?? []) as $column) {
      $column = trim((string) $column);
      if ($column !== '' && $this->schema->columnExists($table, $column)) {
        $columns[$column] = $column;
      }
    }
    foreach (array_merge((array) $config['name'], (array) $config['email'], (array) $config['phone']) as $column) {
      $column = trim((string) $column);
      if ($column !== '' && $this->schema->columnExists($table, $column)) {
        $columns[$column] = $column;
      }
    }
    return array_values($columns);
  }
}
