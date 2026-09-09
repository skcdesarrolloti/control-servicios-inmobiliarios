<?php

declare(strict_types=1);

namespace SCM\Modules\AdministrativeNotifications;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\EmailTemplate;
use SCM\Support\LegacyXlsReader;
use SCM\Support\SchemaInspector;
use SCM\Support\StoredFileService;

final class AdministrativeNotificationsService
{
  public const PROJECT_CODE = 'control-servicios-inmobiliarios';
  public const SOURCE_MODULE = 'admin_notifications';
  public const QUEUE_TABLE = 'skc_notification_queue';
  private const QUEUE_ATTEMPTS_TABLE = 'skc_notification_attempts';
  public const SMS_MAX = 160;
  public const COLLECTION_SMS_MAX = 480;
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

  /** @return array<string,array<string,mixed>> */
  public function whatsappTemplates(): array
  {
    $propietarios = ['propietarios_activos', 'propietarios_no_activos'];
    $arrendatarios = ['arrendatarios_activos', 'arrendatarios_no_activos'];
    $copropiedades = ['copropiedades_activas', 'copropiedades_no_activas'];
    $funcionarios = ['funcionarios'];

    $templates = [
      self::DEFAULT_WHATSAPP_TEMPLATE => [
        'name' => self::DEFAULT_WHATSAPP_TEMPLATE,
        'label' => 'Notificación general',
        'language' => 'es_CO',
        'description' => 'Plantilla oficial para avisos generales. Incluye mensaje editable y firma del funcionario.',
        'body' => "Buen día, {{1}}.\n\nLe compartimos la siguiente información desde Su Casa Inmobiliaria:\n\n{{2}}\n\nAtentamente,\n{{3}}\n\nGracias por elegirnos y confiar en nuestro equipo.",
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
        'body' => "Hola, {{1}}.\n\nLe informamos que el pago del arriendo correspondiente a su inmueble en administración fue abonado a la cuenta registrada.\n\nDetalle del abono:\n\n{{2}}\n\nAtentamente,\n{{3}}",
        'variables' => ['Nombre del propietario', 'Detalle del pago, inmueble, mes o valor', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($propietarios, $funcionarios),
        'email_template' => 'scm_email_propietario_arriendo_consignado_v1',
        'parameter_mode' => 'name_message_signature',
      ],
      'scm_copropiedad_soportes_pago_v1' => [
        'name' => 'scm_copropiedad_soportes_pago_v1',
        'label' => 'Soportes de pago',
        'language' => 'es_CO',
        'description' => 'Envío de soportes de pago a copropiedades para verificación y recibo de caja.',
        'body' => "Hola, {{1}}.\n\nAdjuntamos los soportes de pago correspondientes para su respectiva verificación.\n\nDetalle de los soportes:\n\n{{2}}\n\nAgradecemos su colaboración con el envío del recibo de caja correspondiente a estos pagos.\n\nAtentamente,\n{{3}}",
        'variables' => ['Nombre de la copropiedad o contacto', 'Mes, inmueble y detalle de los soportes', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($copropiedades, $funcionarios),
        'email_template' => 'scm_email_copropiedad_soportes_pago_v1',
        'parameter_mode' => 'name_message_signature',
        'header_type' => 'document',
        'header_media_source' => 'import_receipt_pdf',
        'requires_message' => false,
      ],
      'scm_arrendatario_aviso_pago_canon_v1' => [
        'name' => 'scm_arrendatario_aviso_pago_canon_v1',
        'label' => 'Aviso pago canon',
        'language' => 'es_CO',
        'description' => 'Aviso formal para arrendatarios por incumplimiento en el pago del canon.',
        'body' => "Estimado(a) {{1}}, reciba un cordial saludo.\n\nLe recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.\n\nUna vez activado el proceso con la aseguradora, se puede generar un recargo adicional del 50% sobre el valor del canon adeudado, además de los costos y gestiones asociados al trámite.\n\nPara evitar mayores consecuencias económicas y administrativas, le solicitamos realizar el pago de manera inmediata.\n\nAtentamente,\n{{2}}\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro *Bot Guardián* desde el botón de abajo.\n🌐 Recuerda que puedes ingresar a tu *menú personal en nuestra página web* para consultar información y gestionar tus servicios.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_arrendatario_aviso_pago_canon_v1',
        'parameter_mode' => 'name_signature',
      ],
      'scm_aviso_siniestro_v2' => [
        'name' => 'scm_aviso_siniestro_v2',
        'label' => 'Aviso siniestro',
        'language' => 'es_CO',
        'description' => 'Aviso formal para arrendatarios por incumplimiento y posible reporte ante aseguradora.',
        'body' => "Estimado(a) *{{1}}*, reciba un cordial saludo.\n\nLe recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.\n\nUna vez activado el proceso con la aseguradora, se puede generar un recargo adicional del 50% sobre el valor del canon adeudado, además de los costos y gestiones asociados al trámite.\n\n*Para evitar mayores consecuencias económicas y administrativas, le solicitamos realizar el pago de manera inmediata.*\n\nAtentamente,\n*{{2}}*\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro *Bot Guardián* desde el botón de abajo.\n🌐 Recuerda que puedes ingresar a tu *menú personal en nuestra página web* para consultar información y gestionar tus servicios.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_aviso_siniestro_v1',
        'parameter_mode' => 'name_signature',
      ],
      'scm_arrendatario_gestion_cobro_v1' => [
        'name' => 'scm_arrendatario_gestion_cobro_v1',
        'label' => 'Gestión registrada',
        'language' => 'es_CO',
        'description' => 'Aviso para arrendatarios cuando se registra una gestión relacionada con su contrato.',
        'body' => "Buen dia, *{{1}}.*\n\nLe informamos que se registro una gestion de cobro relacionada con su contrato de arrendamiento.\n\n*Detalle de la gestion:*\n{{2}}\n\n*Si ya realizo el pago o tiene alguna novedad, por favor comuniquese con nosotros.*\n\nAtentamente,\n*{{3}}*\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro *Bot Guardián* desde el botón de abajo.\n🌐 Recuerda que puedes ingresar a tu *menú personal en nuestra página web* para consultar información y gestionar tus servicios.",
        'variables' => ['Nombre del arrendatario', 'Tipo de gestión y observación', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_arrendatario_gestion_cobro_v1',
        'parameter_mode' => 'name_message_signature',
      ],
      'scm_arrendatario_fecha_pago_v1' => [
        'name' => 'scm_arrendatario_fecha_pago_v1',
        'label' => 'Notificación fecha de pago',
        'language' => 'es_CO',
        'description' => 'Notificación informativa para arrendatarios en las fechas de pago 12, 22, 26 y 31.',
        'body' => "Buen día, *{{1}}.*\n\nLe recordamos que hoy, día *{{2}}*, corresponde su *{{3}}* fecha de pago del mes. 📅\n\n💰 Recuerde que el valor correspondiente a pagar se encuentra indicado en su cupón de pago\n\nAgradecemos realizar su pago oportunamente y por el valor establecido en el cupón. 🙏\n\nAtentamente,\n*{{4}}*\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro *Bot Guardián* desde el botón de abajo.\n🌐 Recuerda que puedes ingresar a tu *menú personal en nuestra página web* para consultar información y gestionar tus servicios.",
        'variables' => ['Nombre del arrendatario', 'Día de pago: 12, 22, 26 o 31', 'Fecha ordinal: primera, segunda, tercera o última', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_arrendatario_fecha_pago_v1',
        'parameter_mode' => 'collection_due_date',
      ],
      'scm_factura_disponible_v1' => [
        'name' => 'scm_factura_disponible_v1',
        'label' => 'Factura disponible',
        'language' => 'es_CO',
        'description' => 'Aviso para arrendatarios cuando tienen una nueva factura disponible.',
        'body' => "Estimado(a) {{1}},\n\n¡Tienes una nueva factura disponible! Ya puedes consultarla y en los próximos días estará habilitada para que puedas realizar tu pago.\n\nEstoy para servirte.\n\nAtentamente,\n{{2}}\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro Bot Guardián desde el botón de abajo.\n🌐 Recuerda que puedes ingresar a tu menú personal en nuestra página web para consultar información y gestionar tus servicios.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_factura_disponible_v1',
        'parameter_mode' => 'name_signature',
        'header_type' => 'image',
        'header_media_key' => 'whatsapp_factura_disponible_header_url',
        'header_media_env' => 'SCM_WHATSAPP_FACTURA_DISPONIBLE_IMAGE_URL',
        'header_required' => true,
      ],
      'scm_mes_generado_pago_v1' => [
        'name' => 'scm_mes_generado_pago_v1',
        'label' => 'Mes generado para pago',
        'language' => 'es_CO',
        'description' => 'Aviso para arrendatarios cuando el cupón de pago del mes fue generado.',
        'body' => "Estimado(a) {{1}}, tu mes ha sido generado exitosamente.\n\nHemos generado tu cupón de pago, el cual cuenta con 4 fechas establecidas cada mes:\n📅 12 de cada mes\n📅 22 de cada mes\n📅 26 de cada mes\n📅 31 de cada mes\n\nEn el cupón encontrarás el valor específico correspondiente a cada fecha. Te agradecemos revisar tu cupón mensual y realizar el pago en la fecha y por el valor allí establecidos.\n\nPuedes realizar el pago a través del botón de pago disponible o por ventanilla en Banco Caja Social, ingresando tu cédula o NIT sin dígito de verificación.\n\nCuando realices tu pago, por favor envíanos el soporte para validarlo en el sistema.\n\nEstoy para servirte.\n\nAtentamente,\n{{2}}\n\n🤖 ¿Dudas, quejas o inconvenientes? Escríbele a nuestro Bot Guardián desde el botón de abajo.",
        'variables' => ['Nombre del arrendatario', 'Firma del funcionario: Nombre - Cargo - Celular'],
        'actors' => array_merge($arrendatarios, $funcionarios),
        'email_template' => 'scm_email_cupon_disponible_v1',
        'parameter_mode' => 'name_signature',
      ],
    ];

    return $templates;
  }

  /** @return array<string,array{name:string,label:string,subject:string,body:string,description:string,source:string,message_only:bool,editable_message:string}> */
  public function emailTemplates(): array
  {
    $templates = [
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
      'scm_email_arrendatario_gestion_cobro_v1' => [
        'name' => 'scm_email_arrendatario_gestion_cobro_v1',
        'label' => 'Gestión registrada',
        'subject' => 'Gestión registrada en contrato de arrendamiento',
        'description' => 'Email para arrendatarios cuando se registra una gestión relacionada con su contrato.',
        'body' => '<p><strong>Buen d&iacute;a, {{nombre}}</strong>.</p><p>Le informamos que se registr&oacute; una gesti&oacute;n relacionada con su contrato de arrendamiento.</p><div>{{mensaje}}</div><p>Si ya realiz&oacute; el pago o tiene alguna novedad, por favor comun&iacute;quese con nosotros.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => true,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'requires_message' => true,
        'preview_excerpt' => 'Gestión registrada con detalle editable y firma del funcionario.',
      ],
      'scm_email_arrendatario_fecha_pago_v1' => [
        'name' => 'scm_email_arrendatario_fecha_pago_v1',
        'label' => 'Notificación fecha de pago',
        'subject' => 'Notificación de fecha de pago',
        'description' => 'Email informativo para recordar fechas de pago del canon.',
        'body' => '<p><strong>Buen d&iacute;a, {{nombre}}</strong>.</p><div>{{mensaje}}</div><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => true,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'requires_message' => true,
        'preview_excerpt' => 'Notificación de fecha de pago con firma y accesos automáticos a Guardian y menú personal.',
      ],
      'scm_email_arrendatario_aviso_pago_canon_v1' => [
        'name' => 'scm_email_arrendatario_aviso_pago_canon_v1',
        'label' => 'Aviso pago canon',
        'subject' => 'Aviso importante sobre canon de arrendamiento',
        'description' => 'Email formal para arrendatarios por incumplimiento en el pago del canon.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>, reciba un cordial saludo.</p><p>Le recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.</p><p>Una vez activado el proceso con la aseguradora, se puede generar un recargo adicional del <strong>50%</strong> sobre el valor del canon adeudado, adem&aacute;s de los costos y gestiones asociados al tr&aacute;mite.</p><p><strong>Para evitar mayores consecuencias econ&oacute;micas y administrativas, le solicitamos realizar el pago de manera inmediata.</strong></p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso formal de canon vencido con firma y accesos automáticos a Guardian y menú personal.',
      ],
      'scm_email_factura_disponible_v1' => [
        'name' => 'scm_email_factura_disponible_v1',
        'label' => 'Factura disponible',
        'subject' => 'Tienes una nueva factura disponible',
        'description' => 'Email para arrendatarios con factura nueva disponible.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>,</p><p>&iexcl;Tienes una nueva factura disponible! Ya puedes consultarla y en los pr&oacute;ximos d&iacute;as estar&aacute; habilitada para que puedas realizar tu pago.</p><p>Estoy para servirte.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso de nueva factura disponible con firma y accesos automaticos a Guardian y menu personal.',
      ],
      'scm_email_cupon_disponible_v1' => [
        'name' => 'scm_email_cupon_disponible_v1',
        'label' => 'Cupón disponible',
        'subject' => 'Tu mes ha sido generado exitosamente',
        'description' => 'Email para arrendatarios con cupón de pago generado y opciones de pago.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>, tu mes ha sido generado exitosamente.</p><p>Hemos generado tu cup&oacute;n de pago, el cual cuenta con 4 fechas establecidas cada mes:</p><ul><li>12 de cada mes</li><li>22 de cada mes</li><li>26 de cada mes</li><li>31 de cada mes</li></ul><p>En el cup&oacute;n encontrar&aacute;s el valor espec&iacute;fico correspondiente a cada fecha. Te agradecemos revisar tu cup&oacute;n mensual y realizar el pago en la fecha y por el valor all&iacute; establecidos.</p><p>Puedes realizar el pago a trav&eacute;s del bot&oacute;n de pago disponible o por ventanilla en Banco Caja Social, ingresando tu c&eacute;dula o NIT sin d&iacute;gito de verificaci&oacute;n.</p><p><a href="https://www.mipagoamigo.com/MPA_WebSite/ServicePayments/StartPayment?id=6329&amp;searchedCategoryId=&amp;searchedAgreementName=SOLUCIONES%20COMERCIALES%20Y%20CONSTRUCTIVAS%20SAS" style="display:inline-block;background:#f59120;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:8px;">Pagar ahora</a></p><p>Cuando realices tu pago, por favor env&iacute;anos el soporte para validarlo en el sistema.</p><p>Estoy para servirte.</p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso de cupon disponible con fechas de pago, boton de pago, firma y acceso Guardian.',
      ],
      'scm_email_aviso_siniestro_v1' => [
        'name' => 'scm_email_aviso_siniestro_v1',
        'label' => 'Aviso siniestro',
        'subject' => 'Aviso importante sobre canon de arrendamiento',
        'description' => 'Email formal para arrendatarios por incumplimiento y posible reporte ante aseguradora.',
        'body' => '<p><strong>Estimado(a) {{nombre}}</strong>, reciba un cordial saludo.</p><p>Le recordamos que el incumplimiento en el pago del canon de arrendamiento dentro del plazo establecido constituye una falta a las obligaciones contractuales y puede dar lugar al reporte ante la aseguradora, conforme al contrato de arrendamiento vigente.</p><p>Una vez activado el proceso con la aseguradora, se puede generar un recargo adicional del <strong>50%</strong> sobre el valor del canon adeudado, adem&aacute;s de los costos y gestiones asociados al tr&aacute;mite.</p><p><strong>Para evitar mayores consecuencias econ&oacute;micas y administrativas, le solicitamos realizar el pago de manera inmediata.</strong></p><p style="margin-top:24px;">Atentamente,<br><strong>{{firma_funcionario_linea}}</strong></p>',
        'source' => 'sistema',
        'message_only' => false,
        'editable_message' => '',
        'is_html' => true,
        'is_full_document' => false,
        'fixed_body' => true,
        'requires_message' => false,
        'preview_excerpt' => 'Aviso formal de siniestro/canon vencido con firma y accesos automaticos a Guardian y menu personal.',
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
        'requires_message' => false,
        'preview_excerpt' => 'Envio de soportes de pago con detalle editable.',
      ],
    ];

    try {
      $templates += $this->storedEmailTemplates();
    } catch (\Throwable) {
      // En entornos de prueba o sin JetEngine se mantienen las plantillas de sistema.
    }

    return $templates;
  }

  /**
   * Registra gestiones de cobro usando los mismos campos del formulario JetForm legacy.
   *
   * @param int[] $ids IDs de arrendatarios seleccionados.
   * @param array<string,mixed> $payload
   * @return array{selected:int,contracts:int,created:int,updated_contracts:int,history:int,properties:int,skipped:int,recipient_ids:int[],managements:array<int,array<string,mixed>>}
   */
  public function registerCollectionManagement(array $ids, array $payload): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
      throw new \RuntimeException('Selecciona al menos un arrendatario activo.');
    }

    $gestionesTable = $this->db->table('jet_cct_gestiones_cobro');
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($gestionesTable)) {
      throw new \RuntimeException('La tabla de gestiones de cobro no esta disponible.');
    }
    if (!$this->schema->tableExists($contractTable)) {
      throw new \RuntimeException('La tabla de contratos de arrendamiento no esta disponible.');
    }

    $data = $this->normalizeCollectionPayload($payload);
    $contracts = $this->activeArrendatarioContracts($ids, $data['contract_ids']);
    if ($contracts === []) {
      return ['selected' => count($ids), 'contracts' => 0, 'created' => 0, 'updated_contracts' => 0, 'history' => 0, 'properties' => 0, 'skipped' => count($ids), 'recipient_ids' => [], 'managements' => []];
    }

    $nowTs = time();
    $nowMysql = date('Y-m-d H:i:s', $nowTs);
    $sender = $this->senderProfile();
    $userId = Auth::userId();
    $nextTs = $this->collectionNextTimestamp($data['siguiente_fecha'], $data['siguiente_hora']);

    $created = 0;
    $updatedContracts = 0;
    $history = 0;
    $properties = 0;
    $skipped = 0;
    $seenContracts = [];
    $recipientIds = [];
    $managements = [];

    foreach ($contracts as $contract) {
      $contractId = (string) ($contract['_ID'] ?? '');
      if ($contractId === '' || isset($seenContracts[$contractId])) {
        $skipped++;
        continue;
      }
      $seenContracts[$contractId] = true;
      $nextCount = $this->nextCollectionCount($contract);

      $gestionPayload = [
        'cct_status' => 'publish',
        'cct_author_id' => $userId,
        'cct_created' => $nowMysql,
        'cct_modified' => $nowMysql,
        'id_inmueble' => $this->firstNonEmpty([$contract['id_inmueble'] ?? '', $contract['inmueble'] ?? '']),
        'id_contrato' => $contractId,
        'id_empleado' => $userId,
        'realizado_por' => $sender['name'],
        'cargo' => $sender['cargo'],
        'fecha' => $nowTs,
        'contrato' => $this->firstNonEmpty([$contract['contrato'] ?? '', $contract['contrato_arrendamiento'] ?? '', $contractId]),
        'inmueble' => $this->firstNonEmpty([$contract['inmueble'] ?? '', $contract['id_inmueble'] ?? '']),
        'direccion' => $this->firstNonEmpty([$contract['direccion'] ?? '', $contract['direccion_inmueble'] ?? '']),
        'arrendatario' => $this->firstNonEmpty([$contract['arrendatario'] ?? '', $contract['nombre_arrendatario'] ?? '']),
        'propietario' => $this->firstNonEmpty([$contract['propietario'] ?? '', $contract['nombre_propietario'] ?? '']),
        'sucursal' => $contract['sucursal'] ?? '',
        'id_arrendatario' => $contract['id_arrendatario'] ?? '',
        'id_propietario' => $contract['id_propietario'] ?? '',
        'gestiones_cobro' => $nextCount,
        'observacion' => $data['observacion'],
        'volver_llamar' => $data['volver_llamar'],
        'siguiente_gestion_cobro' => $nextTs,
        'otro_horario_cobro' => $data['otro_horario_cobro'],
        'dia_cobro' => $data['siguiente_fecha'],
        'hora_cobro' => $data['siguiente_hora'],
        'tipo_gestion' => $data['tipo_gestion_cobro'],
      ];
      $insert = $this->schema->filterTableData($gestionesTable, $gestionPayload);
      if ($insert === [] || !$this->db->insert($gestionesTable, $insert)) {
        $skipped++;
        continue;
      }
      $created++;
      $gestionId = (int) $this->db->lastInsertId();
      $arrendatarioId = (int) preg_replace('/\D+/', '', (string) ($contract['id_arrendatario'] ?? '0'));
      if ($arrendatarioId > 0) {
        $recipientIds[$arrendatarioId] = $arrendatarioId;
        $managements[] = [
          'id' => $gestionId,
          'recipient_id' => $arrendatarioId,
          'contract_id' => (int) $contractId,
          'contract_number' => (string) $gestionPayload['contrato'],
          'property' => (string) $gestionPayload['inmueble'],
          'type' => (string) $gestionPayload['tipo_gestion'],
        ];
      }

      $contractUpdate = [
        'cct_modified' => $nowMysql,
        'fecha_gestion_cobro' => $nowTs,
        'id_gestion_cobro' => $gestionId,
        'gestiones_cobro' => $nextCount,
        'siguiente_gestion_cobro' => $nextTs,
        'otro_horario_cobro' => $data['otro_horario_cobro'],
        'dia_cobro' => $data['siguiente_fecha'],
        'hora_cobro' => $data['siguiente_hora'],
        'tipo_gestion' => $data['tipo_gestion_cobro'],
        'id_empleado' => $userId,
        'realizado_por' => $sender['name'],
        'tuvo_revision' => 'Si',
      ];
      $contractUpdate = $this->schema->filterTableData($contractTable, $contractUpdate);
      if ($contractUpdate !== []) {
        $updatedContracts += $this->db->update($contractTable, $contractUpdate, ['_ID' => $contractId]) > 0 ? 1 : 0;
      }

      $history += $this->insertCollectionPropertyHistory($contract, $sender['name'], $userId, $nowTs, $nowMysql, $data) ? 1 : 0;
      $properties += $this->updateCollectionPropertyCounter($contract, $nextCount, $nowMysql) ? 1 : 0;
    }

    return [
      'selected' => count($ids),
      'contracts' => count($contracts),
      'created' => $created,
      'updated_contracts' => $updatedContracts,
      'history' => $history,
      'properties' => $properties,
      'skipped' => $skipped,
      'recipient_ids' => array_values($recipientIds),
      'managements' => $managements,
    ];
  }

  /** @param array<string,mixed> $payload */
  public function collectionNotificationMessage(array $payload): string
  {
    $data = $this->normalizeCollectionPayload($payload);
    $parts = [
      'Cobro por deuda de ' . $this->collectionDebtConceptLabel($data['tipo_gestion_cobro']) . '.',
    ];
    if ($data['observacion'] !== '') {
      $parts[] = 'Observación: ' . rtrim($this->plainText($data['observacion']), '.') . '.';
    }
    return implode(' ', $parts);
  }

  /** @param array<string,mixed> $payload */
  public function collectionSmsNotificationMessage(array $payload): string
  {
    return 'Buen día, {{nombre}}. ' . $this->collectionNotificationMessage($payload);
  }

  public function collectionDueDateOrdinal(int $day): string
  {
    return match ($day) {
      22 => 'segunda',
      26 => 'tercera',
      31 => 'última',
      default => 'primera',
    };
  }

  public function normalizeCollectionDueDateDay(int|string $day): int
  {
    $day = (int) preg_replace('/\D+/', '', (string) $day);
    if (!in_array($day, [12, 22, 26, 31], true)) {
      throw new \RuntimeException('Selecciona una fecha de pago válida: 12, 22, 26 o 31.');
    }
    return $day;
  }

  public function collectionDueDateReminderMessage(int|string $day): string
  {
    $day = $this->normalizeCollectionDueDateDay($day);
    return '<p>Le recordamos que <strong>hoy, día ' . $day . ', corresponde su ' . htmlspecialchars($this->collectionDueDateOrdinal($day), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' fecha de pago del mes</strong>. 📅</p>'
      . '<p>💰 Recuerde que el <strong>valor correspondiente a pagar se encuentra indicado en su cupón de pago</strong>.</p>'
      . '<p>Agradecemos realizar su pago oportunamente y por el valor establecido en el cupón. 🙏</p>';
  }

  public function collectionDueDateReminderSmsMessage(int|string $day): string
  {
    $day = $this->normalizeCollectionDueDateDay($day);
    return 'Buen día, {{nombre}}. Le recordamos que hoy, día ' . $day . ', corresponde su '
      . $this->collectionDueDateOrdinal($day)
      . ' fecha de pago del mes. El valor a pagar está indicado en su cupón. Agradecemos realizar su pago oportunamente.';
  }

  /** @param string[] $channels */
  public function collectionDueDateReminderObservation(int|string $day, array $channels = []): string
  {
    $day = $this->normalizeCollectionDueDateDay($day);
    $channels = array_values(array_unique(array_filter(array_map(static fn($value): string => strtolower(trim((string) $value)), $channels))));
    $channelText = $channels !== [] ? ' Canales: ' . implode(', ', array_map(static fn(string $channel): string => match ($channel) {
      'whatsapp' => 'WhatsApp',
      'email' => 'Email',
      'sms' => 'SMS',
      default => $channel,
    }, $channels)) . '.' : '';
    return 'Notificación informativa de fecha de pago día ' . $day . ' (' . $this->collectionDueDateOrdinal($day) . ' fecha de pago).' . $channelText;
  }

  /**
   * Consulta el historial de gestiones de cobro para el panel administrativo.
   *
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,stats:array{total:int,by_type:array<string,int>},types:array<int,string>,pagination:array{page:int,per_page:int,total:int,total_pages:int},filters:array{date_from:string,date_to:string,type:string}}
   */
  public function collectionManagementReport(array $filters = [], int $page = 1, int $perPage = 30): array
  {
    $table = $this->db->table('jet_cct_gestiones_cobro');
    if (!$this->schema->tableExists($table)) {
      return [
        'rows' => [],
        'stats' => ['total' => 0, 'by_type' => []],
        'types' => [],
        'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1],
        'filters' => ['date_from' => '', 'date_to' => '', 'type' => ''],
      ];
    }

    $dateFrom = $this->sanitizeDate((string) ($filters['date_from'] ?? ''));
    $dateTo = $this->sanitizeDate((string) ($filters['date_to'] ?? ''));
    if ($dateFrom !== '' && $dateTo !== '' && strcmp($dateFrom, $dateTo) > 0) {
      [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $type = trim((string) ($filters['type'] ?? ''));
    $page = max(1, $page);
    $perPage = max(10, min(100, $perPage));

    [$whereSql, $args] = $this->collectionManagementWhere($table, $dateFrom, $dateTo, $type, true);
    [$statsWhereSql, $statsArgs] = $this->collectionManagementWhere($table, $dateFrom, $dateTo, '', false);
    $total = (int) ($this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}", $args) ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $select = [
      $this->collectionSelect($table, ['_ID', 'id'], 'id'),
      $this->collectionSelect($table, ['fecha', 'fecha_gestion_cobro', 'cct_created'], 'fecha_raw'),
      $this->collectionSelect($table, ['contrato', 'id_contrato', 'contrato_arrendamiento'], 'contrato'),
      $this->collectionSelect($table, ['inmueble', 'id_inmueble', 'id_inmueble_data'], 'inmueble'),
      $this->collectionSelect($table, ['direccion', 'direccion_inmueble'], 'direccion'),
      $this->collectionSelect($table, ['propietario', 'nombre_propietario'], 'propietario'),
      $this->collectionSelect($table, ['arrendatario', 'nombre_arrendatario'], 'arrendatario'),
      $this->collectionSelect($table, ['tipo_gestion', 'tipo_gestion_cobro'], 'tipo_gestion'),
      $this->collectionSelect($table, ['gestiones_cobro', 'numero_gestion'], 'gestiones_cobro'),
      $this->collectionSelect($table, ['observacion', 'observaciones', 'detalle'], 'observacion'),
      $this->collectionSelect($table, ['realizado_por', 'funcionario', 'empleado'], 'realizado_por'),
      $this->collectionSelect($table, ['cargo'], 'cargo'),
    ];

    $orderColumn = $this->collectionDateColumn($table);
    $orderSql = $orderColumn !== ''
      ? "`{$orderColumn}` DESC, `{$this->collectionIdColumn($table)}` DESC"
      : "`{$this->collectionIdColumn($table)}` DESC";
    $rows = $this->db->getResults(
      'SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE {$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}",
      $args
    );

    $typeColumn = $this->detect($table, ['tipo_gestion', 'tipo_gestion_cobro']);
    $types = [];
    $byType = [];
    if ($typeColumn !== '') {
      $typeRows = $this->db->getResults(
        "SELECT TRIM(COALESCE(`{$typeColumn}`, '')) AS tipo, COUNT(*) AS total FROM `{$table}` WHERE {$statsWhereSql} GROUP BY TRIM(COALESCE(`{$typeColumn}`, '')) ORDER BY total DESC, tipo ASC",
        $statsArgs
      );
      foreach ($typeRows as $typeRow) {
        $label = trim((string) ($typeRow['tipo'] ?? ''));
        $label = $label !== '' ? $this->collectionTypeLabel($label) : 'Sin tipo';
        $count = (int) ($typeRow['total'] ?? 0);
        $byType[$label] = ($byType[$label] ?? 0) + $count;
        if ($label !== 'Sin tipo') {
          $types[$label] = $label;
        }
      }
    }

    $statsTotal = (int) ($this->db->getVar("SELECT COUNT(*) FROM `{$table}` WHERE {$statsWhereSql}", $statsArgs) ?? 0);

    return [
      'rows' => $rows,
      'stats' => ['total' => $statsTotal, 'by_type' => $byType],
      'types' => array_values($types),
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
      'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'type' => $type],
    ];
  }

  /** @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>,management_id:int} */
  public function collectionManagementQueue(int $managementId): array
  {
    $managementId = max(0, $managementId);
    if ($managementId <= 0) {
      throw new \RuntimeException('Gestion de cobro invalida.');
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $lookupNeedle = '%"collection_management_lookup":"%' . $this->db->escapeLike('|' . $managementId . '|') . '%';
    $rows = $this->db->getResults(
      'SELECT `id`, `channel`, `provider`, `destination`, `destination_name`, `subject`, `template_name`, `status`, `attempts`, `created_at`, `updated_at`, `sent_at`, `meta_json`, LEFT(COALESCE(`message_text`, \'\'), 1400) AS `message_text`, LEFT(COALESCE(`last_error`, \'\'), 500) AS `last_error`
        FROM `' . self::QUEUE_TABLE . '`
        WHERE `source_module` = ? AND `meta_json` LIKE ?
        ORDER BY `id` DESC
        LIMIT 100',
      [self::SOURCE_MODULE, $lookupNeedle]
    );

    $stats = ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'other' => 0];
    foreach ($rows as &$row) {
      $status = strtolower(trim((string) ($row['status'] ?? '')));
      $stats['total']++;
      if (isset($stats[$status])) {
        $stats[$status]++;
      } else {
        $stats['other']++;
      }
      $row['channel_label'] = $this->queueChannelLabel((string) ($row['channel'] ?? ''));
      $row['status_label'] = $this->queueStatusLabel($status);
      $row['recipient_role_label'] = $this->queueRecipientRoleLabel((string) ($row['meta_json'] ?? ''));
      unset($row['meta_json']);
    }
    unset($row);

    return [
      'rows' => $rows,
      'stats' => $stats,
      'management_id' => $managementId,
    ];
  }

  /**
   * @param array<string,mixed> $filters
   * @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>,pagination:array<string,int>,filters:array<string,string>}
   */
  public function notificationQueue(array $filters = []): array
  {
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $page = max(1, (int) ($filters['page'] ?? 1));
    $perPage = (int) ($filters['per_page'] ?? 25);
    if (!in_array($perPage, [10, 25, 50, 100], true)) {
      $perPage = 25;
    }
    $offset = ($page - 1) * $perPage;

    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
    $type = trim((string) ($filters['type'] ?? ''));
    $template = mb_substr(trim((string) ($filters['template'] ?? '')), 0, 120, 'UTF-8');
    $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 160, 'UTF-8');
    $dateFrom = $this->normalizeQueueDate((string) ($filters['date_from'] ?? ''));
    $dateTo = $this->normalizeQueueDate((string) ($filters['date_to'] ?? ''));

    $allowedStatuses = ['pending', 'processing', 'sent', 'failed', 'cancelled'];
    $allowedChannels = ['email', 'sms', 'whatsapp'];
    $types = $this->types();

    $where = ['`project_code` = ?', '`source_module` = ?'];
    $args = [self::PROJECT_CODE, self::SOURCE_MODULE];

    if ($dateFrom !== '') {
      $where[] = '`created_at` >= ?';
      $args[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
      $where[] = '`created_at` <= ?';
      $args[] = $dateTo . ' 23:59:59';
    }
    if (in_array($status, $allowedStatuses, true)) {
      $where[] = '`status` = ?';
      $args[] = $status;
    } else {
      $status = '';
    }
    if (in_array($channel, $allowedChannels, true)) {
      $where[] = '`channel` = ?';
      $args[] = $channel;
    } else {
      $channel = '';
    }
    if (array_key_exists($type, $types)) {
      $where[] = '`meta_json` LIKE ?';
      $args[] = '%"tipo_actor":"' . $this->db->escapeLike($type) . '"%';
    } else {
      $type = '';
    }
    if ($template !== '') {
      $where[] = '`template_name` LIKE ?';
      $args[] = '%' . $this->db->escapeLike($template) . '%';
    }
    if ($query !== '') {
      $needle = '%' . $this->db->escapeLike($query) . '%';
      $where[] = '(`destination_name` LIKE ? OR `destination` LIKE ? OR `subject` LIKE ? OR `template_name` LIKE ? OR `message_text` LIKE ? OR `last_error` LIKE ? OR `meta_json` LIKE ?)';
      array_push($args, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
    }

    $whereSql = implode(' AND ', $where);
    $total = (int) ($this->db->getVar('SELECT COUNT(1) FROM `' . self::QUEUE_TABLE . '` WHERE ' . $whereSql, $args) ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
      $page = $totalPages;
      $offset = ($page - 1) * $perPage;
    }

    $rows = $this->db->getResults(
      'SELECT `id`, `channel`, `provider`, `destination`, `destination_name`, `subject`, `template_name`, `status`, `attempts`, `max_attempts`, `created_at`, `updated_at`, `sent_at`, `meta_json`, LEFT(COALESCE(`message_text`, \'\'), 900) AS `message_text`, LEFT(COALESCE(`last_error`, \'\'), 700) AS `last_error`
        FROM `' . self::QUEUE_TABLE . '`
        WHERE ' . $whereSql . '
        ORDER BY `id` DESC
        LIMIT ' . $perPage . ' OFFSET ' . $offset,
      $args
    );

    $statsRows = $this->db->getResults(
      'SELECT LOWER(TRIM(COALESCE(`status`, \'\'))) AS status_key, COUNT(1) AS total
        FROM `' . self::QUEUE_TABLE . '`
        WHERE ' . $whereSql . '
        GROUP BY LOWER(TRIM(COALESCE(`status`, \'\')))',
      $args
    );
    $stats = ['total' => $total, 'pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'other' => 0];
    foreach ($statsRows as $statsRow) {
      $key = trim((string) ($statsRow['status_key'] ?? ''));
      $count = (int) ($statsRow['total'] ?? 0);
      if (isset($stats[$key])) {
        $stats[$key] = $count;
      } else {
        $stats['other'] += $count;
      }
    }

    foreach ($rows as &$row) {
      $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
      $admin = is_array($meta['admin_notifications'] ?? null) ? $meta['admin_notifications'] : [];
      $typeKey = trim((string) ($admin['tipo_actor'] ?? ''));
      $statusKey = strtolower(trim((string) ($row['status'] ?? '')));
      $channelKey = strtolower(trim((string) ($row['channel'] ?? '')));
      $row['channel_label'] = $this->queueChannelLabel($channelKey);
      $row['status_label'] = $this->queueStatusLabel($statusKey);
      $row['status_key'] = $statusKey;
      $row['type_key'] = $typeKey;
      $row['type_label'] = trim((string) ($admin['tipo_label'] ?? '')) ?: (string) ($types[$typeKey]['label'] ?? '');
      $row['recipient_role_label'] = $this->queueRecipientRoleLabel((string) ($row['meta_json'] ?? ''));
      $row['actor_id'] = (int) ($admin['id_actor'] ?? 0);
      $row['batch_id'] = trim((string) ($admin['batch_id'] ?? ''));
      $row['employee_name'] = trim((string) ($admin['nombre_funcionario'] ?? ''));
      $row['test_mode'] = is_array($admin['test_mode'] ?? null) && $admin['test_mode'] !== [];
      unset($row['meta_json']);
    }
    unset($row);

    return [
      'rows' => $rows,
      'stats' => $stats,
      'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
      'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $status, 'channel' => $channel, 'type' => $type, 'template' => $template, 'q' => $query],
    ];
  }

  private function normalizeQueueDate(string $value): string
  {
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
  }

  public function deleteFailedQueueNotification(int $id): int
  {
    $id = max(0, $id);
    if ($id <= 0) {
      throw new \RuntimeException('Registro de cola invalido.');
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $row = $this->db->getRow(
      'SELECT `id`, `status`, `project_code`, `source_module`
        FROM `' . self::QUEUE_TABLE . '`
        WHERE `id` = ?
        LIMIT 1',
      [$id]
    );
    if (!is_array($row) || $row === []) {
      throw new \RuntimeException('El registro de cola no existe.');
    }
    if ((string) ($row['project_code'] ?? '') !== self::PROJECT_CODE || (string) ($row['source_module'] ?? '') !== self::SOURCE_MODULE) {
      throw new \RuntimeException('Solo se pueden eliminar registros de Notificaciones administrativas.');
    }
    if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'failed') {
      throw new \RuntimeException('Solo se pueden eliminar registros fallidos.');
    }

    $pdo = $this->db->pdo();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
      $pdo->beginTransaction();
    }

    try {
      if ($this->schema->tableExists(self::QUEUE_ATTEMPTS_TABLE)) {
        $this->db->delete(self::QUEUE_ATTEMPTS_TABLE, ['notification_id' => $id]);
      }
      $deleted = $this->db->delete(self::QUEUE_TABLE, ['id' => $id]);
      if ($startedTransaction) {
        $pdo->commit();
      }
      return $deleted;
    } catch (\Throwable $e) {
      if ($startedTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  /** @param array<string,mixed> $payload @return array{tipo_gestion_cobro:string,observacion:string,volver_llamar:string,siguiente_fecha:string,siguiente_hora:string,otro_horario_cobro:string,tipo_reporte_inmueble:string,contract_ids:array<int,int>} */
  private function normalizeCollectionPayload(array $payload): array
  {
    $type = trim((string) ($payload['tipo_gestion_cobro'] ?? 'Canon'));
    if ($type === '') {
      $type = 'Canon';
    }
    $normalizedType = strtolower(strtr($type, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    if (str_contains($normalizedType, 'servicio')) {
      $type = 'Servicios publicos';
    } elseif (str_contains($normalizedType, 'admin')) {
      $type = 'Administracion';
    } else {
      $type = 'Canon';
    }

    $volver = strtolower(trim((string) ($payload['volver_llamar'] ?? 'No')));
    $volver = in_array($volver, ['si', 's', '1', 'true'], true) ? 'Si' : 'No';
    $date = trim((string) ($payload['siguiente_fecha'] ?? ''));
    $hour = trim((string) ($payload['siguiente_hora'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      $date = '';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $hour)) {
      $hour = '';
    }
    if ($volver !== 'Si') {
      $date = '';
      $hour = '';
    }
    $rawContractIds = $payload['contract_ids'] ?? [];
    if (!is_array($rawContractIds)) {
      $rawContractIds = [$rawContractIds];
    }
    $contractIds = array_values(array_unique(array_filter(array_map('intval', $rawContractIds), static fn(int $id): bool => $id > 0)));

    return [
      'tipo_gestion_cobro' => $type,
      'observacion' => trim(wp_strip_all_tags((string) ($payload['observacion'] ?? ''))),
      'volver_llamar' => $volver,
      'siguiente_fecha' => $date,
      'siguiente_hora' => $hour,
      'otro_horario_cobro' => mb_substr(trim(wp_strip_all_tags((string) ($payload['otro_horario_cobro'] ?? ''))), 0, 240, 'UTF-8'),
      'tipo_reporte_inmueble' => mb_substr(trim(wp_strip_all_tags((string) ($payload['tipo_reporte_inmueble'] ?? 'Cobro'))), 0, 190, 'UTF-8') ?: 'Cobro',
      'contract_ids' => $contractIds,
    ];
  }

  private function collectionNextTimestamp(string $date, string $hour): int
  {
    if ($date === '') {
      return 0;
    }
    $time = $hour !== '' ? $hour : '08:00';
    $ts = strtotime($date . ' ' . $time . ':00');
    return is_int($ts) && $ts > 0 ? $ts : 0;
  }

  private function sanitizeDate(string $value): string
  {
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
  }

  /** @return array{0:string,1:array<int,mixed>} */
  private function collectionManagementWhere(string $table, string $dateFrom, string $dateTo, string $type, bool $includeType): array
  {
    $where = ['1=1'];
    $args = [];
    $dateColumn = $this->collectionDateColumn($table);
    if ($dateColumn !== '' && ($dateFrom !== '' || $dateTo !== '')) {
      $from = $dateFrom !== '' ? $dateFrom : $dateTo;
      $to = $dateTo !== '' ? $dateTo : $dateFrom;
      $fromTs = strtotime($from . ' 00:00:00');
      $toTs = strtotime($to . ' 23:59:59');
      if ($fromTs !== false && $toTs !== false) {
        if (in_array($dateColumn, ['fecha', 'fecha_gestion_cobro'], true)) {
          $where[] = "CAST(COALESCE(`{$dateColumn}`, 0) AS UNSIGNED) BETWEEN ? AND ?";
          $args[] = (int) $fromTs;
          $args[] = (int) $toTs;
        } else {
          $where[] = "DATE(`{$dateColumn}`) BETWEEN ? AND ?";
          $args[] = $from;
          $args[] = $to;
        }
      }
    }

    $typeColumn = $this->detect($table, ['tipo_gestion', 'tipo_gestion_cobro']);
    if ($includeType && $typeColumn !== '' && trim($type) !== '') {
      $where[] = 'LOWER(' . $this->collatedTextSql("TRIM(COALESCE(`{$typeColumn}`, ''))") . ') = LOWER(CONVERT(TRIM(?) USING utf8mb4) COLLATE utf8mb4_unicode_ci)';
      $args[] = $this->collectionTypeLabel($type);
    }

    return [implode(' AND ', $where), $args];
  }

  private function collectionDateColumn(string $table): string
  {
    return $this->detect($table, ['fecha', 'fecha_gestion_cobro', 'cct_created', 'created_at']);
  }

  private function collectionIdColumn(string $table): string
  {
    return $this->detect($table, ['_ID', 'id']) ?: '_ID';
  }

  /** @param string[] $candidates */
  private function collectionSelect(string $table, array $candidates, string $alias): string
  {
    $column = $this->detect($table, $candidates);
    if ($column === '') {
      return "'' AS `{$alias}`";
    }
    return "COALESCE(`{$column}`, '') AS `{$alias}`";
  }

  private function collectionTypeLabel(string $type): string
  {
    $normalized = strtolower(strtr(trim($type), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    if (str_contains($normalized, 'servicio')) {
      return 'Servicios publicos';
    }
    if (str_contains($normalized, 'admin')) {
      return 'Administracion';
    }
    if (str_contains($normalized, 'canon')) {
      return 'Canon';
    }
    return trim($type) !== '' ? trim($type) : 'Sin tipo';
  }

  private function collectionTypeDisplayLabel(string $type): string
  {
    $normalized = $this->collectionTypeLabel($type);
    if ($normalized === 'Servicios publicos') {
      return 'Servicios públicos';
    }
    if ($normalized === 'Administracion') {
      return 'Administración';
    }
    return $normalized;
  }

  private function collectionDebtConceptLabel(string $type): string
  {
    $display = $this->collectionTypeDisplayLabel($type);
    if ($display === 'Canon') {
      return 'canon';
    }
    if ($display === 'Administración') {
      return 'administración';
    }
    if ($display === 'Servicios públicos') {
      return 'servicios públicos';
    }
    return mb_strtolower($display, 'UTF-8');
  }

  private function queueChannelLabel(string $channel): string
  {
    return match (strtolower(trim($channel))) {
      'email' => 'Email',
      'sms' => 'SMS',
      'whatsapp' => 'WhatsApp',
      default => ucfirst(trim($channel)) ?: 'Canal',
    };
  }

  private function queueStatusLabel(string $status): string
  {
    return match (strtolower(trim($status))) {
      'pending' => 'Pendiente',
      'processing' => 'Procesando',
      'sent' => 'Enviada',
      'failed' => 'Fallida',
      'cancelled' => 'Cancelada',
      default => ucfirst(trim($status)) ?: 'Sin estado',
    };
  }

  private function queueRecipientRoleLabel(string $metaJson): string
  {
    $meta = json_decode($metaJson, true);
    if (!is_array($meta)) {
      return '';
    }
    $admin = is_array($meta['admin_notifications'] ?? null) ? $meta['admin_notifications'] : [];
    $role = trim((string) ($admin['rol_persona'] ?? ''));
    $type = trim((string) ($admin['tipo_label'] ?? ''));
    return $role !== '' ? $role : $type;
  }

  /**
   * @param array<string,mixed> $recipient
   * @return array<int,array{id:int,label:string,contrato:string,inmueble:string,direccion:string,codeudores:array<int,array<string,string|bool>>}>
   */
  private function collectionContractOptionsForRecipient(array $recipient): array
  {
    $ids = array_values(array_unique(array_filter(array_map(
      static fn($value): int => (int) preg_replace('/\D+/', '', (string) $value),
      [$recipient['_ID'] ?? '', $recipient['contrato_actor_ref'] ?? '']
    ), static fn(int $id): bool => $id > 0)));
    return $this->collectionContractOptionsForRecipientIds($ids);
  }

  /**
   * Carga contratos y codeudores solo cuando se abre la gestión de cobro.
   *
   * @param int[] $ids
   * @return array<int,array{id:int,recipient_id:int,label:string,contrato:string,inmueble:string,direccion:string,codeudores:array<int,array<string,string|bool>>}>
   */
  public function collectionContractOptionsForRecipientIds(array $ids): array
  {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
      return [];
    }
    $lookupIds = array_fill_keys($ids, true);
    $arrendatariosTable = $this->db->table('jet_cct_arrendatarios');
    if (
      $this->schema->tableExists($arrendatariosTable)
      && $this->schema->columnExists($arrendatariosTable, 'id_arrendatario')
    ) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $rows = $this->db->getResults(
        "SELECT `_ID`, `id_arrendatario` FROM `{$arrendatariosTable}` WHERE CAST(`_ID` AS UNSIGNED) IN ({$placeholders})",
        $ids
      );
      foreach ($rows as $row) {
        $actorId = (int) preg_replace('/\D+/', '', (string) ($row['id_arrendatario'] ?? ''));
        if ($actorId > 0) {
          $lookupIds[$actorId] = true;
        }
      }
    }
    $contracts = $this->activeArrendatarioContracts(array_map('intval', array_keys($lookupIds)));
    $tableCodeudoresByContract = $this->collectionCodeudoresFromTableForContracts($contracts);
    $options = [];
    foreach ($contracts as $contract) {
      $id = (int) ($contract['_ID'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $contractNumber = $this->firstNonEmpty([$contract['contrato'] ?? '', $contract['contrato_arrendamiento'] ?? '', $id]);
      $property = $this->firstNonEmpty([$contract['inmueble'] ?? '', $contract['id_inmueble'] ?? '', $contract['id_inmueble_data'] ?? '']);
      $address = $this->firstNonEmpty([$contract['direccion'] ?? '', $contract['direccion_inmueble'] ?? '']);
      $parts = ['Contrato #' . $contractNumber];
      if ($property !== '') {
        $parts[] = 'Inmueble ' . $property;
      }
      if ($address !== '') {
        $parts[] = $address;
      }
      $options[] = [
        'id' => $id,
        'recipient_id' => (int) ($contract['id_arrendatario'] ?? 0),
        'label' => implode(' · ', $parts),
        'contrato' => (string) $contractNumber,
        'inmueble' => (string) $property,
        'direccion' => (string) $address,
        'codeudores' => $this->collectionCodeudorOptionsFromRecipients($contract, array_merge(
          $tableCodeudoresByContract[$id] ?? [],
          $this->collectionCodeudoresFromContractColumns($contract, [])
        )),
      ];
    }
    return $options;
  }

  /**
   * @param array<string,mixed> $contract
   * @return array<int,array<string,string|bool>>
   */
  private function collectionCodeudorOptionsForContract(array $contract): array
  {
    return $this->collectionCodeudorOptionsFromRecipients($contract, array_merge(
      $this->collectionCodeudoresFromTable($contract, []),
      $this->collectionCodeudoresFromContractColumns($contract, [])
    ));
  }

  /** @param array<string,mixed> $contract @param array<int,array<string,mixed>> $recipients */
  private function collectionCodeudorOptionsFromRecipients(array $contract, array $recipients): array
  {
    $contractId = (int) ($contract['_ID'] ?? 0);
    if ($contractId <= 0) {
      return [];
    }
    $out = [];
    $seen = [];
    foreach ($recipients as $recipient) {
      if (!is_array($recipient)) {
        continue;
      }
      $key = $this->codeudorRecipientKey($recipient, $contractId);
      if ($key === '' || isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $email = trim((string) ($recipient['correo'] ?? ''));
      $phone = trim((string) ($recipient['celular'] ?? ''));
      $meta = is_array($recipient['_scm_notification_meta'] ?? null) ? $recipient['_scm_notification_meta'] : [];
      $codeudorMeta = is_array($meta['codeudor'] ?? null) ? $meta['codeudor'] : [];
      $source = trim((string) ($codeudorMeta['source'] ?? ''));
      $out[] = [
        'key' => $key,
        'id' => (string) ((int) ($recipient['_ID'] ?? 0)),
        'nombre' => trim((string) ($recipient['nombre'] ?? '')),
        'documento' => trim((string) ($recipient['documento'] ?? '')),
        'correo' => $email,
        'celular' => $phone,
        'direccion' => trim((string) ($codeudorMeta['address'] ?? '')),
        'fuente' => $source === 'tabla' ? 'Tabla de codeudores' : 'Contrato',
        'has_email' => $email !== '',
        'has_phone' => $phone !== '',
      ];
    }

    return $out;
  }

  /**
   * @param int[] $contractIds
   * @return string[]
   */
  private function collectionContractLabels(array $contractIds): array
  {
    $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds), static fn(int $id): bool => $id > 0)));
    if ($contractIds === []) {
      return [];
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
    $select = ['_ID'];
    foreach (['contrato', 'contrato_arrendamiento', 'inmueble', 'id_inmueble', 'id_inmueble_data', 'direccion', 'direccion_inmueble'] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = $column;
      }
    }
    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($select))) . "` FROM `{$table}` WHERE CAST(`_ID` AS UNSIGNED) IN ({$placeholders}) ORDER BY `_ID` DESC",
      $contractIds
    );
    $labels = [];
    foreach ($rows as $row) {
      $contract = $this->firstNonEmpty([$row['contrato'] ?? '', $row['contrato_arrendamiento'] ?? '', $row['_ID'] ?? '']);
      $property = $this->firstNonEmpty([$row['inmueble'] ?? '', $row['id_inmueble'] ?? '', $row['id_inmueble_data'] ?? '']);
      $address = $this->firstNonEmpty([$row['direccion'] ?? '', $row['direccion_inmueble'] ?? '']);
      $parts = ['#' . $contract];
      if ($property !== '') {
        $parts[] = 'Inmueble ' . $property;
      }
      if ($address !== '') {
        $parts[] = $address;
      }
      $labels[] = implode(' · ', $parts);
    }
    return $labels;
  }

  /**
   * @param int[] $ids
   * @return array<int,array<string,mixed>>
   */
  private function activeArrendatarioContracts(array $ids, array $contractIds = []): array
  {
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'id_arrendatario')) {
      return [];
    }
    $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds), static fn(int $id): bool => $id > 0)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where = "CAST(`id_arrendatario` AS UNSIGNED) IN ({$placeholders})
          AND LOWER(TRIM(COALESCE(`estado`, ''))) = 'entregado'";
    $args = $ids;
    if ($contractIds !== []) {
      $contractPlaceholders = implode(',', array_fill(0, count($contractIds), '?'));
      $where .= " AND CAST(`_ID` AS UNSIGNED) IN ({$contractPlaceholders})";
      array_push($args, ...$contractIds);
    }
    $select = ['_ID'];
    foreach ([
      'estado',
      'contrato',
      'contrato_arrendamiento',
      'id_inmueble',
      'inmueble',
      'id_inmueble_data',
      'direccion',
      'direccion_inmueble',
      'arrendatario',
      'nombre_arrendatario',
      'propietario',
      'nombre_propietario',
      'sucursal',
      'id_arrendatario',
      'id_propietario',
      'gestiones_cobro',
      'codigo_inmueble_web',
      'num_codeudores',
      'id_codeudor',
      'id_codeudores',
      'codeudores_ids',
      'id_codeudor_1',
      'codeudor_1',
      'celular_codeudor_1',
      'correo_codeudor_1',
      'documento_codeudor_1',
      'dir_codeudor_1',
      'id_codeudor_2',
      'codeudor_2',
      'celular_codeudor_2',
      'correo_codeudor_2',
      'documento_codeudor_2',
      'dir_codeudor_2',
      'id_codeudor_3',
      'codeudor_3',
      'celular_codeudor_3',
      'correo_codeudor_3',
      'documento_codeudor_3',
      'dir_codeudor_3',
      'id_codeudor_4',
      'codeudor_4',
      'celular_codeudor_4',
      'correo_codeudor_4',
      'documento_codeudor_4',
      'dir_codeudor_4',
    ] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = $column;
      }
    }

    return $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($select))) . "` FROM `{$table}`
        WHERE {$where}
        ORDER BY `_ID` DESC",
      $args
    );
  }

  /** @param array<string,mixed> $contract */
  private function nextCollectionCount(array $contract): int
  {
    $raw = trim((string) ($contract['gestiones_cobro'] ?? '0'));
    $count = 0;
    if ($raw !== '') {
      if (is_numeric($raw)) {
        $count = (int) $raw;
      } elseif (preg_match_all('/\d+/', $raw, $matches)) {
        $numbers = array_map('intval', $matches[0] ?? []);
        $count = $numbers !== [] ? max($numbers) : 0;
      }
    }
    return max(0, $count) + 1;
  }

  /** @param array<string,mixed> $contract @param array<string,string> $data */
  private function insertCollectionPropertyHistory(array $contract, string $employeeName, int $employeeId, int $nowTs, string $nowMysql, array $data): bool
  {
    $table = $this->db->table('jet_cct_historial_del_inmueble');
    if (!$this->schema->tableExists($table)) {
      return false;
    }
    $propertyId = $this->firstNonEmpty([$contract['id_inmueble'] ?? '', $contract['inmueble'] ?? '', $contract['id_inmueble_data'] ?? '']);
    $detail = 'Se ha realizado una gestion de cobro en su inmueble.';
    if ($data['observacion'] !== '') {
      $detail .= ' Observacion: ' . $data['observacion'];
    }
    if ($data['volver_llamar'] === 'Si' && $data['siguiente_fecha'] !== '') {
      $detail .= ' Proxima gestion: ' . $data['siguiente_fecha'] . ($data['siguiente_hora'] !== '' ? ' ' . $data['siguiente_hora'] : '');
    }
    $propertyReportType = trim((string) ($data['tipo_reporte_inmueble'] ?? ''));
    if ($propertyReportType === '') {
      $propertyReportType = 'Cobro';
    }
    $payload = [
      'cct_status' => 'publish',
      'cct_author_id' => $employeeId,
      'cct_created' => $nowMysql,
      'cct_modified' => $nowMysql,
      'id_inmueble' => $propertyId,
      'id_inmueble_data' => $propertyId,
      'id_empleado' => $employeeId,
      'fecha' => $nowTs,
      'tipo_reporte' => $propertyReportType,
      'tipo_de_reporte_his' => $propertyReportType,
      'observacion' => $detail,
      'observacion_his' => $detail,
      'funcionario' => $employeeName,
      'reporte_realizado_por_his' => $employeeName,
    ];
    $payload = $this->schema->filterTableData($table, $payload);
    return $payload !== [] && $this->db->insert($table, $payload);
  }

  /** @param array<string,mixed> $contract */
  private function updateCollectionPropertyCounter(array $contract, int $nextCount, string $nowMysql): bool
  {
    $updated = $this->updateCollectionPropertyPostMetaCounter($contract, $nextCount);
    $table = $this->db->table('jet_cct_inmuebles');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'cobros')) {
      return $updated;
    }
    $payload = $this->schema->filterTableData($table, [
      'cobros' => $nextCount,
      'cct_modified' => $nowMysql,
    ]);
    if ($payload === []) {
      return $updated;
    }
    $candidateIds = array_values(array_unique(array_filter(array_map(
      static fn($value): string => trim((string) $value),
      [$contract['id_inmueble'] ?? '', $contract['id_inmueble_data'] ?? '', $contract['inmueble'] ?? '', $contract['codigo_inmueble_web'] ?? '']
    ), static fn(string $value): bool => $value !== '')));
    foreach ($candidateIds as $candidate) {
      if ($this->db->update($table, $payload, ['_ID' => $candidate]) > 0) {
        $updated = true;
      }
      if ($this->schema->columnExists($table, 'codigo') && $this->db->update($table, $payload, ['codigo' => $candidate]) > 0) {
        $updated = true;
      }
    }
    return $updated;
  }

  /** @param array<string,mixed> $contract */
  private function updateCollectionPropertyPostMetaCounter(array $contract, int $nextCount): bool
  {
    $postMetaTable = $this->db->table('postmeta');
    if (!$this->schema->tableExists($postMetaTable)) {
      return false;
    }
    $postId = (int) preg_replace('/\D+/', '', (string) ($contract['id_inmueble'] ?? '0'));
    if ($postId <= 0) {
      return false;
    }
    $exists = (int) ($this->db->getVar("SELECT `meta_id` FROM `{$postMetaTable}` WHERE `post_id` = ? AND `meta_key` = 'cobros' LIMIT 1", [$postId]) ?? 0);
    if ($exists > 0) {
      return $this->db->update($postMetaTable, ['meta_value' => (string) $nextCount], ['meta_id' => $exists]) >= 0;
    }
    return $this->db->insert($postMetaTable, [
      'post_id' => $postId,
      'meta_key' => 'cobros',
      'meta_value' => (string) $nextCount,
    ]);
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
      $columns['document'] !== '' ? "`{$columns['document']}` AS documento" : "'' AS documento",
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
      // Los contratos y codeudores se consultan al abrir Gestión de cobro.
      // Evita una consulta por cada fila durante la carga de destinatarios.
      $row['contratos_gestion_cobro'] = [];
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
   * @return array{rows:array<int,array<string,mixed>>,payload:array<string,array<string,string>>,report_rows:array<int,array<string,string>>,total_rows:int,usable_rows:int,matched:int,unmatched:int,ambiguous:int,duplicates:int,type:string,type_label:string}
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
      throw new \RuntimeException('No se encontraron encabezados validos. Usa columnas como contrato, inmueble_simi, NoInm y canon.');
    }

    $metas = [];

    foreach ($dataRows as $row) {
      $meta = $this->importMetaForRow($headers, $row);
      if (($meta['contrato_excel'] ?? '') === '' && ($meta['inmueble_simi_excel'] ?? '') === '' && ($meta['documento_excel'] ?? '') === '') {
        continue;
      }
      $metas[] = $meta;
    }

    if ($this->canUseBulkContractImport($config, $metas)) {
      return $this->importRecipientsFromContractMetas($type, $config, $metas, count($dataRows));
    }

    $outRows = [];
    $payload = [];
    $reportRows = [];
    $seen = [];
    $unmatched = 0;
    $ambiguous = 0;
    $duplicates = 0;

    foreach ($metas as $index => $meta) {
      $result = $this->search(
        $type,
        '',
        1,
        100,
        '',
        (string) ($meta['inmueble_simi_excel'] ?? ''),
        (string) ($meta['contrato_excel'] ?? ''),
        ['document' => (string) ($meta['documento_excel'] ?? '')]
      );
      $matches = (array) ($result['rows'] ?? []);
      if ($matches === []) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se encontro destinatario para contrato/inmueble.');
        continue;
      }
      $matchesById = [];
      foreach ($matches as $recipient) {
        $id = (int) ($recipient['_ID'] ?? 0);
        if ($id > 0) {
          $matchesById[$id] = $recipient;
        }
      }
      if (count($matchesById) > 1) {
        $ambiguous++;
        foreach ($matchesById as $recipient) {
          $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Coincidencia multiple', 'No', 'La fila coincide con varios destinatarios; no se marco automaticamente.');
        }
        continue;
      }
      $recipient = reset($matchesById);
      if (!is_array($recipient)) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se encontro destinatario valido para contrato/inmueble.');
        continue;
      }
      $id = (int) ($recipient['_ID'] ?? 0);
      if (isset($seen[$id])) {
        $duplicates++;
        $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Duplicado omitido', 'No', 'Ya habia sido marcado por otra fila del Excel.');
        continue;
      }
      $seen[$id] = true;
      $recipient['_scm_import_meta'] = $meta;
      $outRows[] = $recipient;
      $payload[(string) $id] = $meta;
      $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Encontrado', 'Si', 'Marcado automaticamente en la pagina.');
    }

    return [
      'rows' => $outRows,
      'payload' => $payload,
      'report_rows' => $reportRows,
      'total_rows' => count($dataRows),
      'usable_rows' => count($metas),
      'matched' => count($outRows),
      'unmatched' => $unmatched,
      'ambiguous' => $ambiguous,
      'duplicates' => $duplicates,
      'type' => $type,
      'type_label' => (string) ($config['label'] ?? $type),
    ];
  }

  /**
   * @param array<string,mixed> $files
   * @return array{rows:array<int,array<string,mixed>>,payload:array<string,array<string,mixed>>,report_rows:array<int,array<string,string>>,total_rows:int,usable_rows:int,matched:int,files_matched:int,unmatched:int,ambiguous:int,duplicates:int,type:string,type_label:string}
   */
  public function importCopropiedadPaymentReceipts(string $type, array $files): array
  {
    $config = $this->typeConfig($type);
    if (!str_starts_with($type, 'copropiedades_')) {
      throw new \RuntimeException('Los comprobantes PDF solo se cruzan en copropiedades.');
    }

    $uploads = $this->normalizeReceiptUploadFiles($files);
    if ($uploads === []) {
      throw new \RuntimeException('Selecciona al menos un comprobante PDF.');
    }

    $payload = [];
    $contractsByRecipient = [];
    $reportRows = [];
    $unmatched = 0;
    $ambiguous = 0;
    $filesMatched = 0;

    foreach ($uploads as $index => $file) {
      $meta = $this->paymentReceiptMetaFromUpload($file);
      $nit = (string) ($meta['documento_excel'] ?? '');
      if ($nit === '') {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se encontro NIT en el nombre del PDF.');
        continue;
      }

      $matches = $this->paymentReceiptCopropiedadMatches($nit, $this->effectiveContractStatus($config, ''), $type, (string) ($config['label'] ?? 'Copropiedades'));
      if ($matches === []) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se encontro copropiedad activa para el NIT del PDF.');
        continue;
      }
      if (count($matches) > 1) {
        $ambiguous++;
        foreach ($matches as $match) {
          $reportRows[] = $this->importReportRow($index, $meta, $match['recipient'], 'Coincidencia multiple', 'No', 'El NIT coincide con varias copropiedades; no se marco automaticamente.');
        }
        continue;
      }

      $match = reset($matches);
      if (!is_array($match) || !is_array($match['recipient'] ?? null)) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se pudo construir el destinatario de copropiedad.');
        continue;
      }

      $stored = $this->storePaymentReceiptUpload($file, $nit);
      $recipient = $match['recipient'];
      $recipientId = (string) ((int) ($recipient['_ID'] ?? 0));
      if ($recipientId === '0') {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'El contrato tiene copropiedad, pero no tiene ID de destinatario valido.');
        continue;
      }

      $contracts = $this->filterPaymentReceiptContracts(
        array_values((array) ($match['contracts'] ?? [])),
        (string) ($meta['inmueble_simi_excel'] ?? ''),
        (string) ($meta['pagos_pdf_detalle'] ?? '')
      );
      $receiptMeta = $meta;
      $receiptMeta['archivo_pdf'] = (string) ($stored['name'] ?? '');
      $receiptMeta['ruta_pdf'] = (string) ($stored['path'] ?? '');
      $receiptMeta['url_pdf'] = (string) ($stored['url'] ?? '');
      $receiptMeta['archivo_pdf_guardado'] = (string) ($stored['stored_name'] ?? '');
      $receiptMeta['comprobantes_pago'] = [[
        'path' => (string) ($stored['path'] ?? ''),
        'name' => (string) ($stored['name'] ?? ''),
        'stored_name' => (string) ($stored['stored_name'] ?? ''),
        'url' => (string) ($stored['url'] ?? ''),
        'nit' => $nit,
        'periodo' => (string) ($meta['mes_excel'] ?? ''),
        'inmuebles' => (string) ($meta['inmueble_simi_excel'] ?? ''),
        'pagos_pdf' => (string) ($meta['pagos_pdf'] ?? ''),
        'pagos_pdf_detalle' => (string) ($meta['pagos_pdf_detalle'] ?? ''),
      ]];
      $receiptMeta['contratos_sistema'] = implode(', ', $this->paymentReceiptContractLabels($contracts));
      $receiptMeta['detalle_excel'] = $this->paymentReceiptDetail($receiptMeta, $contracts);

      if (!isset($payload[$recipientId])) {
        $payload[$recipientId] = $receiptMeta;
      } else {
        $payload[$recipientId] = $this->mergePaymentReceiptMeta($payload[$recipientId], $receiptMeta, $contracts);
      }
      $contractsByRecipient[$recipientId] = array_merge($contractsByRecipient[$recipientId] ?? [], $contracts);
      $filesMatched++;

      $recipient['_scm_import_meta'] = $receiptMeta;
      $reportRows[] = $this->importReportRow($index, $receiptMeta, $recipient, 'Encontrado', 'Si', 'Comprobante asociado y PDF guardado para adjuntar por email.');
    }

    $ids = array_map('intval', array_keys($payload));
    $rows = $this->recipients($type, $ids, $payload);
    foreach ($rows as &$row) {
      $id = (string) ((int) ($row['_ID'] ?? 0));
      $contractInfo = $this->contractInfoFromRows(array_values($contractsByRecipient[$id] ?? []));
      $row['contrato_arrendamiento_estado'] = $contractInfo['label'];
      $row['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
    }
    unset($row);

    return [
      'rows' => $rows,
      'payload' => $payload,
      'report_rows' => $reportRows,
      'total_rows' => count($uploads),
      'usable_rows' => count($uploads),
      'matched' => count($rows),
      'files_matched' => $filesMatched,
      'unmatched' => $unmatched,
      'ambiguous' => $ambiguous,
      'duplicates' => 0,
      'type' => $type,
      'type_label' => (string) ($config['label'] ?? $type),
    ];
  }

  /** @param array<string,string> $meta @param array<string,mixed>|null $recipient @return array<string,string> */
  private function importReportRow(int $index, array $meta, ?array $recipient, string $status, string $marked, string $note): array
  {
    $id = $recipient !== null ? (int) ($recipient['_ID'] ?? 0) : 0;
    $phone = $recipient !== null
      ? trim((string) ($recipient['celular_normalizado'] ?? ($recipient['celular'] ?? '')))
      : '';

    return [
      'fila_util' => (string) ($index + 1),
      'estado_cruce' => $status,
      'marcado_en_pagina' => $marked,
      'contrato_excel' => trim((string) ($meta['contrato_excel'] ?? '')),
      'inmueble_simi_excel' => trim((string) ($meta['inmueble_simi_excel'] ?? '')),
      'documento_excel' => trim((string) ($meta['documento_excel'] ?? '')),
      'canon_excel' => trim((string) ($meta['canon_excel'] ?? '')),
      'periodo_excel' => trim((string) ($meta['mes_excel'] ?? '')),
      'direccion_excel' => trim((string) ($meta['direccion_excel'] ?? '')),
      'archivo_pdf' => trim((string) ($meta['archivo_pdf'] ?? '')),
      'nit_pdf' => trim((string) ($meta['documento_excel'] ?? '')),
      'inmuebles_pdf' => trim((string) ($meta['inmueble_simi_excel'] ?? '')),
      'pdf_texto_leido' => trim((string) ($meta['pdf_texto_leido'] ?? '')),
      'pagos_pdf' => trim((string) ($meta['pagos_pdf'] ?? '')),
      'destinatario_id' => $id > 0 ? (string) $id : '',
      'destinatario_nombre' => $recipient !== null ? trim((string) ($recipient['nombre'] ?? '')) : '',
      'destinatario_tipo' => $recipient !== null ? trim((string) ($recipient['tipo_label'] ?? '')) : '',
      'destinatario_documento' => $recipient !== null ? trim((string) ($recipient['documento'] ?? '')) : '',
      'correo' => $recipient !== null ? trim((string) ($recipient['correo'] ?? '')) : '',
      'celular' => $phone,
      'contrato_sistema' => $recipient !== null ? trim((string) (($recipient['contratos_arrendamiento_resumen'] ?? '') ?: ($meta['contratos_sistema'] ?? ''))) : trim((string) ($meta['contratos_sistema'] ?? '')),
      'observacion' => $note,
    ];
  }

  /** @param array<int,array<string,mixed>> $contracts @param array<string,string> $meta @return array<int,array<string,mixed>> */
  private function filterImportContractsForMeta(array $contracts, array $meta): array
  {
    $contract = trim((string) ($meta['contrato_excel'] ?? ''));
    $property = trim((string) ($meta['inmueble_simi_excel'] ?? ''));
    $filtered = [];

    foreach ($contracts as $row) {
      $matchesContract = $contract !== '' && $this->importContractRowMatchesValue($row, ['contrato', 'contrato_arrendamiento', '_ID'], $contract);
      $matchesProperty = $property !== '' && $this->importContractRowMatchesValue($row, ['inmueble', 'id_inmueble', 'id_inmueble_data'], $property);
      if ($contract !== '' && $property !== '') {
        $matches = $matchesContract && $matchesProperty;
      } elseif ($contract !== '') {
        $matches = $matchesContract;
      } else {
        $matches = $matchesProperty;
      }
      if (!$matches) {
        continue;
      }
      $id = (int) ($row['_ID'] ?? 0);
      $filtered[$id > 0 ? $id : count($filtered) + 1] = $row;
    }

    return array_values($filtered);
  }

  /** @param array<string,mixed> $row @param string[] $columns */
  private function importContractRowMatchesValue(array $row, array $columns, string $expected): bool
  {
    $expected = $this->normalizeImportIdentifier($expected);
    if ($expected === '') {
      return false;
    }
    $numericExpected = preg_match('/^\d+$/', $expected) === 1 ? $expected : '';
    foreach ($columns as $column) {
      $rawValue = (string) ($row[$column] ?? '');
      $value = $this->normalizeImportIdentifier($rawValue);
      if ($value !== '' && $value === $expected) {
        return true;
      }
      if ($numericExpected !== '' && $this->stringContainsImportNumericToken($rawValue, $numericExpected)) {
        return true;
      }
    }
    return false;
  }

  private function stringContainsImportNumericToken(string $value, string $expected): bool
  {
    if ($expected === '') {
      return false;
    }
    return preg_match('/(?<!\d)' . preg_quote($expected, '/') . '(?!\d)/', $value) === 1;
  }

  /**
   * @param array<string,mixed> $row
   * @param array<string,array<int,int>> $indexes
   * @return int[]
   */
  private function importMetaIndexesForContractValue(array $row, string $column, array $indexes): array
  {
    $matchedIndexes = [];
    foreach ($indexes as $expected => $metaIndexes) {
      if (!$this->importContractRowMatchesValue($row, [$column], (string) $expected)) {
        continue;
      }
      foreach ($metaIndexes as $metaIndex) {
        $matchedIndexes[$metaIndex] = $metaIndex;
      }
    }
    return array_values($matchedIndexes);
  }

  /** @param array<string,mixed> $recipient */
  private function recipientMatchesImportDocument(array $recipient, string $expected): bool
  {
    $expected = $this->normalizeImportDocument($expected);
    if ($expected === '') {
      return true;
    }
    $document = (string) ($recipient['documento_normalizado'] ?? '');
    if ($document === '') {
      $document = $this->normalizeImportDocument((string) ($recipient['documento'] ?? ''));
    }
    return $document !== '' && $document === $expected;
  }

  /** @param array<string,mixed> $config @param array<int,array<string,string>> $metas */
  private function canUseBulkContractImport(array $config, array $metas = []): bool
  {
    if (!empty($config['contract_match_columns'])) {
      return false;
    }
    foreach ($metas as $meta) {
      if (
        trim((string) ($meta['documento_excel'] ?? '')) !== ''
        && trim((string) ($meta['contrato_excel'] ?? '')) === ''
        && trim((string) ($meta['inmueble_simi_excel'] ?? '')) === ''
      ) {
        return false;
      }
    }
    $table = (string) ($config['table'] ?? '');
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    return $table !== ''
      && $contractActorColumn !== ''
      && $this->schema->tableExists($table)
      && $this->schema->tableExists($contractTable)
      && $this->schema->columnExists($contractTable, $contractActorColumn)
      && $this->schema->columnExists($contractTable, 'estado');
  }

  /**
   * Cruce masivo para importaciones por Excel. Evita llamar search() por cada
   * fila, que dispara COUNT + SELECT + resumen de contratos cientos de veces.
   *
   * @param array<string,mixed> $config
   * @param array<int,array<string,string>> $metas
   * @return array{rows:array<int,array<string,mixed>>,payload:array<string,array<string,string>>,report_rows:array<int,array<string,string>>,total_rows:int,usable_rows:int,matched:int,unmatched:int,ambiguous:int,duplicates:int,type:string,type_label:string}
   */
  private function importRecipientsFromContractMetas(string $type, array $config, array $metas, int $totalRows): array
  {
    $contractsByMeta = $this->importContractsByMeta($config, $metas, $this->effectiveContractStatus($config, ''));
    $actorIds = [];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    foreach ($contractsByMeta as $contracts) {
      foreach ($contracts as $contract) {
        $actorId = (int) preg_replace('/\D+/', '', (string) ($contract[$contractActorColumn] ?? ''));
        if ($actorId > 0) {
          $actorIds[$actorId] = $actorId;
        }
      }
    }
    $recipientsByActor = $this->importRecipientsByActorIds($type, $config, array_values($actorIds));

    $outRows = [];
    $payload = [];
    $reportRows = [];
    $seen = [];
    $unmatched = 0;
    $ambiguous = 0;
    $duplicates = 0;

    foreach ($metas as $index => $meta) {
      $contracts = $this->filterImportContractsForMeta($contractsByMeta[$index] ?? [], $meta);
      $document = trim((string) ($meta['documento_excel'] ?? ''));
      if ($contracts === []) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Sin coincidencia', 'No', 'No se encontro contrato para contrato/inmueble.');
        continue;
      }

      $candidates = [];
      foreach ($contracts as $contract) {
        $contractId = (int) ($contract['_ID'] ?? 0);
        $actorId = (int) preg_replace('/\D+/', '', (string) ($contract[$contractActorColumn] ?? ''));
        if ($actorId <= 0 || empty($recipientsByActor[$actorId])) {
          continue;
        }
        foreach ($recipientsByActor[$actorId] as $recipient) {
          $id = (int) ($recipient['_ID'] ?? 0);
          if ($id <= 0) {
            continue;
          }
          if ($document !== '' && !$this->recipientMatchesImportDocument($recipient, $document)) {
            continue;
          }
          if (!isset($candidates[$id])) {
            $candidates[$id] = [
              'recipient' => $recipient,
              'contracts' => [],
            ];
          }
          $candidateKey = $contractId > 0 ? $contractId : count($candidates[$id]['contracts']) + 1;
          $candidates[$id]['contracts'][$candidateKey] = $contract;
        }
      }
      if ($candidates === []) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Contrato encontrado sin destinatario', 'No', 'El contrato existe, pero no se encontro el actor en esta pestana.');
        continue;
      }
      if ($document === '' && count($contracts) > 1) {
        $ambiguous++;
        foreach ($candidates as $candidate) {
          $recipient = is_array($candidate['recipient'] ?? null) ? $candidate['recipient'] : null;
          if ($recipient !== null) {
            $contractInfo = $this->contractInfoFromRows(array_values((array) ($candidate['contracts'] ?? [])));
            $recipient['contrato_arrendamiento_estado'] = $contractInfo['label'];
            $recipient['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
          }
          $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Coincidencia multiple', 'No', 'La fila coincide con varios contratos; no se marco automaticamente.');
        }
        continue;
      }
      if (count($candidates) > 1) {
        $ambiguous++;
        foreach ($candidates as $candidate) {
          $recipient = is_array($candidate['recipient'] ?? null) ? $candidate['recipient'] : null;
          if ($recipient !== null) {
            $contractInfo = $this->contractInfoFromRows(array_values((array) ($candidate['contracts'] ?? [])));
            $recipient['contrato_arrendamiento_estado'] = $contractInfo['label'];
            $recipient['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
          }
          $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Coincidencia multiple', 'No', 'La fila coincide con varios destinatarios; no se marco automaticamente.');
        }
        continue;
      }

      $candidate = reset($candidates);
      $recipient = is_array($candidate['recipient'] ?? null) ? $candidate['recipient'] : null;
      if ($recipient === null) {
        $unmatched++;
        $reportRows[] = $this->importReportRow($index, $meta, null, 'Contrato encontrado sin destinatario', 'No', 'El contrato existe, pero no se encontro el actor en esta pestana.');
        continue;
      }
      $id = (int) ($recipient['_ID'] ?? 0);
      $contractInfo = $this->contractInfoFromRows(array_values((array) ($candidate['contracts'] ?? [])));
      $recipient['contrato_arrendamiento_estado'] = $contractInfo['label'];
      $recipient['contratos_arrendamiento_resumen'] = $contractInfo['summary'];
      if (isset($seen[$id])) {
        $duplicates++;
        $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Duplicado omitido', 'No', 'Ya habia sido marcado por otra fila del Excel.');
        continue;
      }
      $seen[$id] = true;
      $recipient['_scm_import_meta'] = $meta;
      $outRows[] = $recipient;
      $payload[(string) $id] = $meta;
      $reportRows[] = $this->importReportRow($index, $meta, $recipient, 'Encontrado', 'Si', 'Marcado automaticamente en la pagina.');
    }

    return [
      'rows' => $outRows,
      'payload' => $payload,
      'report_rows' => $reportRows,
      'total_rows' => $totalRows,
      'usable_rows' => count($metas),
      'matched' => count($outRows),
      'unmatched' => $unmatched,
      'ambiguous' => $ambiguous,
      'duplicates' => $duplicates,
      'type' => $type,
      'type_label' => (string) ($config['label'] ?? $type),
    ];
  }

  /**
   * @param array<string,mixed> $config
   * @param array<int,array<string,string>> $metas
   * @return array<int,array<int,array<string,mixed>>>
   */
  private function importContractsByMeta(array $config, array $metas, string $contractStatus): array
  {
    if ($metas === []) {
      return [];
    }

    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    $contractColumns = array_values(array_filter(['contrato', 'contrato_arrendamiento', '_ID'], fn(string $column): bool => $this->schema->columnExists($contractTable, $column)));
    $propertyColumns = array_values(array_filter(['inmueble', 'id_inmueble', 'id_inmueble_data'], fn(string $column): bool => $this->schema->columnExists($contractTable, $column)));
    if ($contractColumns === [] && $propertyColumns === []) {
      return [];
    }

    $contractIndexes = [];
    $propertyIndexes = [];
    foreach ($metas as $index => $meta) {
      $contract = trim((string) ($meta['contrato_excel'] ?? ''));
      if ($contract !== '') {
        $contractIndexes[$contract][] = $index;
      }
      $property = trim((string) ($meta['inmueble_simi_excel'] ?? ''));
      if ($property !== '') {
        $propertyIndexes[$property][] = $index;
      }
    }

    $selectColumns = array_values(array_unique(array_merge(['_ID', $contractActorColumn, 'estado'], $contractColumns, $propertyColumns)));
    $select = array_map(static fn(string $column): string => "`{$column}`", $selectColumns);
    $matched = [];
    $queries = [
      ['indexes' => $contractIndexes, 'columns' => $contractColumns],
      ['indexes' => $propertyIndexes, 'columns' => $propertyColumns],
    ];

    foreach ($queries as $query) {
      /** @var array<string,array<int,int>> $indexes */
      $indexes = $query['indexes'];
      /** @var string[] $columns */
      $columns = $query['columns'];
      $values = array_keys($indexes);
      if ($values === [] || $columns === []) {
        continue;
      }
      foreach (array_chunk($values, 250) as $chunk) {
        $parts = [];
        $args = [];
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $numericChunk = array_values(array_unique(array_filter(array_map(
          static fn($value): int => preg_match('/^\d+$/', (string) $value) === 1 ? (int) $value : 0,
          $chunk
        ), static fn(int $value): bool => $value > 0)));
        $numericPlaceholders = $numericChunk !== [] ? implode(',', array_fill(0, count($numericChunk), '?')) : '';
        foreach ($columns as $column) {
          $expr = $column === '_ID' ? "CAST(`_ID` AS CHAR)" : "TRIM(COALESCE(`{$column}`, ''))";
          $parts[] = "{$expr} IN ({$placeholders})";
          array_push($args, ...$chunk);
          if ($numericChunk !== []) {
            $parts[] = "CAST(`{$column}` AS UNSIGNED) IN ({$numericPlaceholders})";
            array_push($args, ...$numericChunk);
            foreach ($numericChunk as $numericValue) {
              $parts[] = "COALESCE(`{$column}`, '') LIKE ?";
              $args[] = '%' . $this->db->escapeLike((string) $numericValue) . '%';
            }
          }
        }
        $where = '(' . implode(' OR ', $parts) . ')';
        if ($contractStatus !== '') {
          $where .= " AND LOWER(TRIM(COALESCE(`estado`, ''))) = ?";
          $args[] = $contractStatus === 'activos' ? 'entregado' : 'recibido';
        }
        $rows = $this->db->getResults('SELECT ' . implode(', ', $select) . " FROM `{$contractTable}` WHERE {$where}", $args);
        foreach ($rows as $row) {
          $contractId = (int) ($row['_ID'] ?? 0);
          if ($contractId <= 0) {
            continue;
          }
          foreach ($columns as $column) {
            foreach ($this->importMetaIndexesForContractValue($row, $column, $indexes) as $metaIndex) {
              $matched[$metaIndex][$contractId] = $row;
            }
          }
        }
      }
    }

    foreach ($matched as $index => $contracts) {
      $matched[$index] = array_values($contracts);
    }
    ksort($matched);
    return $matched;
  }

  /**
   * @param array<string,mixed> $config
   * @param int[] $actorIds
   * @return array<int,array<int,array<string,mixed>>>
   */
  private function importRecipientsByActorIds(string $type, array $config, array $actorIds): array
  {
    $actorIds = array_values(array_unique(array_filter(array_map('intval', $actorIds), static fn(int $id): bool => $id > 0)));
    if ($actorIds === []) {
      return [];
    }

    $table = (string) $config['table'];
    $columns = $this->resolveColumns($config);
    $select = [
      '`_ID`',
      $columns['name'] !== '' ? "`{$columns['name']}` AS nombre" : "CAST(`_ID` AS CHAR) AS nombre",
      $columns['email'] !== '' ? "`{$columns['email']}` AS correo" : "'' AS correo",
      $columns['phone'] !== '' ? "`{$columns['phone']}` AS celular" : "'' AS celular",
      $columns['document'] !== '' ? "`{$columns['document']}` AS documento" : "'' AS documento",
      $columns['indicator'] !== '' ? "`{$columns['indicator']}` AS indicativo" : "'' AS indicativo",
    ];
    $contractActorColumn = (string) ($config['contract_actor_column'] ?? '');
    $hasActorColumn = $contractActorColumn !== '' && $this->schema->columnExists($table, $contractActorColumn);
    $select[] = $hasActorColumn ? "`{$contractActorColumn}` AS contrato_actor_ref" : "'' AS contrato_actor_ref";

    $recipientsByActor = [];
    foreach (array_chunk($actorIds, 500) as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '?'));
      $parts = ["CAST(`_ID` AS UNSIGNED) IN ({$placeholders})"];
      $args = $chunk;
      if ($hasActorColumn) {
        $parts[] = "CAST(`{$contractActorColumn}` AS UNSIGNED) IN ({$placeholders})";
        array_push($args, ...$chunk);
      }
      $where = $this->baseWhere($config) . ' AND (' . implode(' OR ', $parts) . ')';
      $rows = $this->db->getResults('SELECT ' . implode(', ', $select) . " FROM `{$table}` WHERE {$where}", $args);
      foreach ($rows as $row) {
        $row['tipo_actor'] = $type;
        $row['tipo_label'] = (string) $config['label'];
        $row['rol_persona'] = (string) $config['role'];
        $row['celular_normalizado'] = $this->normalizePhone((string) ($row['celular'] ?? ''), (string) ($row['indicativo'] ?? ''), true);
        $row['documento_normalizado'] = $this->normalizeImportDocument((string) ($row['documento'] ?? ''));
        $row['contrato_arrendamiento_estado'] = '';
        $row['contratos_arrendamiento_resumen'] = '';
        $row['contratos_gestion_cobro'] = [];

        $keys = [(int) ($row['_ID'] ?? 0), (int) preg_replace('/\D+/', '', (string) ($row['contrato_actor_ref'] ?? ''))];
        foreach (array_unique($keys) as $key) {
          if ($key > 0) {
            $recipientsByActor[$key][] = $row;
          }
        }
      }
    }

    return $recipientsByActor;
  }

  /** @param array<int,array<string,mixed>> $contracts @return array{label:string,summary:string} */
  private function contractInfoFromRows(array $contracts): array
  {
    $hasDelivered = false;
    $hasReceived = false;
    $contractNumbers = [];
    $properties = [];

    foreach ($contracts as $contract) {
      $state = strtolower(trim((string) ($contract['estado'] ?? '')));
      $hasDelivered = $hasDelivered || $state === 'entregado';
      $hasReceived = $hasReceived || $state === 'recibido';
      $contractNumber = $this->firstNonEmpty([
        $contract['contrato'] ?? '',
        $contract['contrato_arrendamiento'] ?? '',
        $contract['_ID'] ?? '',
      ]);
      if ($contractNumber !== '') {
        $contractNumbers[$contractNumber] = $contractNumber;
      }
      $property = $this->firstNonEmpty([
        $contract['inmueble'] ?? '',
        $contract['id_inmueble'] ?? '',
        $contract['id_inmueble_data'] ?? '',
      ]);
      if ($property !== '') {
        $properties[$property] = $property;
      }
    }

    $summary = $this->contractSummary(array_values($contractNumbers), array_values($properties));
    if ($hasDelivered) {
      return ['label' => 'Activo', 'summary' => $summary];
    }
    if ($hasReceived) {
      return ['label' => 'No activo', 'summary' => $summary];
    }
    return ['label' => 'Sin contrato', 'summary' => $summary];
  }

  /**
   * @param int[] $ids
   * @param string[] $channels
   * @return array{queued:int,failed:int,invalid:int,filtered:int,selected:int,channels:array<int,string>,type:string,test_mode?:bool,test_email?:string,test_phone?:string}
   */
  public function enqueue(string $type, array $ids, array $channels, string $subject, string $message, string $whatsappTemplate = '', string $emailTemplate = '', array $recipientMetaMap = [], int $smsMax = self::SMS_MAX, array $testOptions = []): array
  {
    $config = $this->typeConfig($type);
    $channels = $this->sanitizeChannels($channels);
    $whatsappTemplateConfig = $this->whatsappTemplateConfig($whatsappTemplate);
    $emailTemplateConfig = $this->emailTemplateConfig($emailTemplate);
    $isPaymentSupportTemplate = $this->isCopropiedadPaymentSupportTemplate($whatsappTemplateConfig, $emailTemplateConfig);
    if ($isPaymentSupportTemplate) {
      $channels = array_values(array_filter($channels, static fn(string $channel): bool => $channel !== 'sms'));
    }
    $testOptions = $this->sanitizeTestModeOptions($testOptions, $channels);
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
    $notificationMetaMap = $this->sanitizeNotificationMetaMap($recipientMetaMap);
    $recipientMetaMap = $this->sanitizeImportMetaMap($recipientMetaMap);
    if ($isPaymentSupportTemplate && $recipientMetaMap === []) {
      throw new \RuntimeException('Importa primero los comprobantes PDF para que el sistema pueda adjuntar soportes y armar el detalle.');
    }
    $canUseImportDetail = $this->templateCanUseImportDetail($whatsappTemplateConfig, $emailTemplateConfig);
    $hasImportedDetail = $canUseImportDetail && $recipientMetaMap !== [];
    $messageRequired = !$isPaymentSupportTemplate && (in_array('sms', $channels, true)
      || (in_array('whatsapp', $channels, true) && $this->whatsappTemplateNeedsMessage($whatsappTemplateConfig) && !$hasImportedDetail)
      || (in_array('email', $channels, true) && $this->emailTemplateNeedsMessage($emailTemplateConfig) && !$hasImportedDetail));
    if ($messageRequired && $messageText === '') {
      throw new \RuntimeException('El mensaje no puede estar vacio.');
    }
    if (in_array('email', $channels, true) && $subject === '') {
      $subject = trim((string) ($emailTemplateConfig['subject'] ?? ''));
    }
    if (in_array('email', $channels, true) && $subject === '') {
      throw new \RuntimeException('El asunto es obligatorio para Email.');
    }
    $smsMax = max(self::SMS_MAX, $smsMax);
    if (in_array('sms', $channels, true) && mb_strlen(self::SMS_PREFIX . $messageText) > $smsMax) {
      throw new \RuntimeException('El SMS supera ' . $smsMax . ' caracteres.');
    }
    if (in_array('whatsapp', $channels, true) && $whatsappTemplateConfig === []) {
      throw new \RuntimeException('Selecciona una plantilla valida para WhatsApp.');
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $recipients = $this->recipients($type, $ids, $recipientMetaMap, $notificationMetaMap);
    $batchId = bin2hex(random_bytes(8));
    $queued = 0;
    $failed = 0;
    $invalid = 0;
    $filtered = 0;

    foreach ($recipients as $recipient) {
      foreach ($channels as $channel) {
        if (empty($testOptions['enabled']) && $this->isBlockedByPreference($recipient, $channel, $type)) {
          $filtered++;
          continue;
        }
        $originalDestination = $this->destination($recipient, $channel);
        $destination = !empty($testOptions['enabled'])
          ? $this->testModeDestination($testOptions, $channel)
          : $originalDestination;
        if ($destination === '') {
          $invalid++;
          continue;
        }
        $queueRecipient = $recipient;
        if (!empty($testOptions['enabled'])) {
          $queueRecipient['_scm_test_mode'] = [
            'enabled' => true,
            'channel' => $channel,
            'test_destination' => $destination,
            'test_email' => (string) ($testOptions['email'] ?? ''),
            'test_phone' => (string) ($testOptions['phone'] ?? ''),
            'original_destination' => $originalDestination,
          ];
        }
        $baseMessage = $this->messageForRecipient($message, $recipient, $whatsappTemplateConfig, $emailTemplateConfig, $channel);
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
          $resolvedMessage = $this->plainText($resolvedMessage);
          if (mb_strlen(self::SMS_PREFIX . $resolvedMessage) > $smsMax) {
            $invalid++;
            continue;
          }
        } else {
          $resolvedMessage = $this->plainText($resolvedMessage);
        }
        if ($this->insertQueueRow($channel, $destination, $queueRecipient, $resolvedSubject, $resolvedMessage, $batchId, $whatsappTemplateConfig, $emailTemplateConfig)) {
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
    ] + (!empty($testOptions['enabled']) ? [
      'test_mode' => true,
      'test_email' => (string) ($testOptions['email'] ?? ''),
      'test_phone' => (string) ($testOptions['phone'] ?? ''),
    ] : []);
  }

  /**
   * Encola avisos de gesti&oacute;n de cobro para codeudores relacionados con las gestiones creadas.
   *
   * @param array<int,array<string,mixed>> $managements
   * @param string[] $channels
   * @return array{queued:int,failed:int,invalid:int,filtered:int,selected:int,channels:array<int,string>,type:string}
   */
  public function enqueueCollectionCodeudores(array $managements, array $channels, string $subject, string $message, string $whatsappTemplate = '', string $emailTemplate = '', int $smsMax = self::SMS_MAX, array $selectedCodeudorKeys = []): array
  {
    $channels = $this->sanitizeChannels($channels);
    $recipients = $this->collectionCodeudorRecipients($managements);
    $selectedMap = [];
    foreach ($selectedCodeudorKeys as $selectedKey) {
      $selectedKey = trim((string) $selectedKey);
      if ($selectedKey !== '') {
        $selectedMap[$selectedKey] = true;
      }
    }
    if ($selectedMap !== []) {
      $recipients = array_values(array_filter($recipients, function (array $recipient) use ($selectedMap): bool {
        $meta = is_array($recipient['_scm_notification_meta'] ?? null) ? $recipient['_scm_notification_meta'] : [];
        $codeudorMeta = is_array($meta['codeudor'] ?? null) ? $meta['codeudor'] : [];
        $contractId = (int) ($codeudorMeta['contract_id'] ?? 0);
        if ($contractId <= 0) {
          return false;
        }
        $key = $this->codeudorRecipientKey($recipient, $contractId);
        return isset($selectedMap[$key]);
      }));
    }
    $result = [
      'queued' => 0,
      'failed' => 0,
      'invalid' => 0,
      'filtered' => 0,
      'selected' => count($recipients),
      'channels' => $channels,
      'type' => 'codeudores',
    ];
    if ($channels === [] || $recipients === []) {
      return $result;
    }
    if (!$this->schema->tableExists(self::QUEUE_TABLE)) {
      throw new \RuntimeException('La tabla skc_notification_queue no esta disponible.');
    }

    $whatsappTemplateConfig = $this->whatsappTemplateConfig($whatsappTemplate);
    $emailTemplateConfig = $this->emailTemplateConfig($emailTemplate);
    if (in_array('whatsapp', $channels, true) && $whatsappTemplateConfig === []) {
      throw new \RuntimeException('Selecciona una plantilla valida para WhatsApp.');
    }
    if (in_array('email', $channels, true) && trim($subject) === '') {
      $subject = trim((string) ($emailTemplateConfig['subject'] ?? 'Gestion de cobro de contrato de arrendamiento'));
    }
    $config = [
      'label' => 'Codeudores',
      'role' => 'Codeudor',
    ];
    $messageText = $this->plainText($message);
    $messageRequired = in_array('sms', $channels, true)
      || (in_array('whatsapp', $channels, true) && $this->whatsappTemplateNeedsMessage($whatsappTemplateConfig))
      || (in_array('email', $channels, true) && $this->emailTemplateNeedsMessage($emailTemplateConfig));
    if ($messageRequired && $messageText === '') {
      throw new \RuntimeException('El mensaje no puede estar vacio.');
    }
    $smsMax = max(self::SMS_MAX, $smsMax);
    $batchId = bin2hex(random_bytes(8));

    foreach ($recipients as $recipient) {
      foreach ($channels as $channel) {
        if ($this->isBlockedByPreference($recipient, $channel, 'codeudores')) {
          $result['filtered']++;
          continue;
        }
        $destination = $this->destination($recipient, $channel);
        if ($destination === '') {
          $result['invalid']++;
          continue;
        }
        $channelNeedsMessage = $channel === 'sms'
          || ($channel === 'whatsapp' && $this->whatsappTemplateNeedsMessage($whatsappTemplateConfig))
          || ($channel === 'email' && $this->emailTemplateNeedsMessage($emailTemplateConfig));
        if ($channelNeedsMessage && $this->plainText($message) === '') {
          $result['invalid']++;
          continue;
        }
        $resolvedMessage = $this->resolveVariables($message, $recipient, $config);
        $resolvedSubject = $this->resolveVariables($subject, $recipient, $config);
        if ($channel === 'email') {
          $resolvedMessage = $this->renderEmailTemplate($emailTemplateConfig, $resolvedMessage, $recipient, $config);
        } elseif ($channel === 'sms') {
          $resolvedMessage = $this->plainText($resolvedMessage);
          if (mb_strlen(self::SMS_PREFIX . $resolvedMessage) > $smsMax) {
            $result['invalid']++;
            continue;
          }
        } else {
          $resolvedMessage = $this->plainText($resolvedMessage);
        }
        if ($this->insertQueueRow($channel, $destination, $recipient, $resolvedSubject, $resolvedMessage, $batchId, $whatsappTemplateConfig, $emailTemplateConfig)) {
          $result['queued']++;
        } else {
          $result['failed']++;
        }
      }
    }

    return $result;
  }

  /** @param array<string,mixed> $files @return array<int,array{name:string,tmp_name:string,error:int,size:int,type:string}> */
  private function normalizeReceiptUploadFiles(array $files): array
  {
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
      return [[
        'name' => (string) ($files['name'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'] ?? 0),
        'type' => (string) ($files['type'] ?? ''),
      ]];
    }

    $out = [];
    foreach (array_keys($names) as $index) {
      $out[] = [
        'name' => (string) ($files['name'][$index] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
        'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'][$index] ?? 0),
        'type' => (string) ($files['type'][$index] ?? ''),
      ];
      if (count($out) >= 200) {
        break;
      }
    }
    return $out;
  }

  /** @param array{name:string,tmp_name:string,error:int,size:int,type:string} $file @return array<string,mixed> */
  private function paymentReceiptMetaFromUpload(array $file): array
  {
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new \RuntimeException('Uno de los comprobantes no se pudo subir.');
    }
    $name = basename((string) ($file['name'] ?? ''));
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($name === '' || $tmp === '' || !is_readable($tmp)) {
      throw new \RuntimeException('Uno de los comprobantes no se puede leer.');
    }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
      throw new \RuntimeException('Solo se permiten comprobantes en PDF.');
    }

    $base = pathinfo($name, PATHINFO_FILENAME);
    $nit = '';
    if (preg_match('/\d[\d.\-\s]{5,}\d/', $base, $match) === 1) {
      $nit = $this->normalizeImportDocument((string) $match[0]);
    }
    $period = $this->paymentReceiptPeriodFromName($base);
    $properties = $this->paymentReceiptPropertiesFromName($base, $nit, $period);
    $pdfStats = $this->paymentReceiptPdfStatsFromUpload($file, $nit);

    return [
      'contrato_excel' => '',
      'inmueble_simi_excel' => $properties,
      'documento_excel' => $nit,
      'canon_excel' => '',
      'canon_excel_raw' => '',
      'mes_excel' => $period,
      'direccion_excel' => '',
      'archivo_pdf' => $name,
      'pdf_texto_leido' => $pdfStats['read'] ? 'Si' : 'No',
      'pagos_pdf' => $pdfStats['payments'] > 0 ? (string) $pdfStats['payments'] : '',
      'pagos_pdf_detalle' => $pdfStats['detail'],
      'detalle_excel' => '',
    ];
  }

  /** @param array{name:string,tmp_name:string,error:int,size:int,type:string} $file @return array{read:bool,payments:int,detail:string,records:array<int,array<string,string>>} */
  private function paymentReceiptPdfStatsFromUpload(array $file, string $nit): array
  {
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) {
      return ['read' => false, 'payments' => 0, 'detail' => '', 'records' => []];
    }

    $text = '';
    if (class_exists(\Smalot\PdfParser\Parser::class)) {
      try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($tmp);
        $text = trim($pdf->getText());
      } catch (\Throwable) {
        $text = '';
      }
    }

    if ($text === '') {
      $text = trim($this->paymentReceiptPdfTextViaCommand($tmp));
    }

    return [
      'read' => $text !== '',
      'payments' => $text !== '' ? $this->paymentReceiptPaymentCountFromText($text, $nit) : 0,
      'detail' => $text !== '' ? $this->paymentReceiptPaymentDetailFromText($text, $nit) : '',
      'records' => $text !== '' ? $this->paymentReceiptPaymentRecordsFromText($text, $nit) : [],
    ];
  }

  private function paymentReceiptPdfTextViaCommand(string $path): string
  {
    if (!function_exists('shell_exec')) {
      return '';
    }

    $binary = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
    $command = $binary . ' -layout ' . escapeshellarg($path) . ' - 2>&1';
    try {
      $output = shell_exec($command);
    } catch (\Throwable) {
      return '';
    }

    if (!is_string($output)) {
      return '';
    }
    $lower = mb_strtolower($output, 'UTF-8');
    foreach (['not recognized', 'not found', 'no se reconoce', 'cannot open', 'no such file'] as $errorNeedle) {
      if (str_contains($lower, $errorNeedle)) {
        return '';
      }
    }

    return $output;
  }

  private function paymentReceiptPaymentCountFromText(string $text, string $nit): int
  {
    $records = $this->paymentReceiptPaymentRecordsFromText($text, $nit);
    return $records !== [] ? count($records) : 0;
  }

  /** @return array<int,array{tercero:string,valor:string,transaccion:string}> */
  private function paymentReceiptPaymentRecordsFromText(string $text, string $nit): array
  {
    $candidates = $this->paymentReceiptNitCandidates($nit);
    if ($candidates === []) {
      return [];
    }
    $flat = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $flat = preg_replace('/[ \t\x{00A0}]+/u', ' ', $flat) ?? $flat;
    $flat = preg_replace('/\R+/u', ' ', $flat) ?? $flat;
    $flat = trim($flat);
    if ($flat === '') {
      return [];
    }

    $records = [];
    foreach ($candidates as $candidate) {
      if ($candidate === '') {
        continue;
      }
      $pattern = '/(?:\b(?:NI|NIT|CC)\b\s*)?(?<!\d)' . preg_quote($candidate, '/') . '(?!\d)\s+(?<third>[\p{L}\p{N}\s\.\-ÁÉÍÓÚÜÑáéíóúüñ&]{2,160}?)\s+\$(?<value>[0-9\.\,]+)(?<tail>.{0,220}?)(?=\b(?:Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Setiembre|Octubre|Noviembre|Diciembre)\b|Página|Imprimir|$)/iu';
      if (preg_match_all($pattern, $flat, $matches, PREG_SET_ORDER) !== false && $matches !== []) {
        foreach ($matches as $match) {
          $third = $this->cleanPaymentReceiptPdfFragment((string) ($match['third'] ?? ''));
          $value = trim((string) ($match['value'] ?? ''));
          $tail = (string) ($match['tail'] ?? '');
          $transaction = '';
          if (preg_match('/([0-9]{7,})\s*Proveedores/iu', $tail, $txMatch) === 1) {
            $transaction = (string) $txMatch[1];
          }
          $key = strtolower($candidate . '|' . $third . '|' . $value . '|' . $transaction);
          $records[$key] = [
            'tercero' => $third,
            'valor' => $value !== '' ? '$' . $value : '',
            'transaccion' => $transaction,
          ];
        }
        if ($records !== []) {
          break;
        }
      }
    }

    return array_values($records);
  }

  private function paymentReceiptPaymentDetailFromText(string $text, string $nit): string
  {
    $records = $this->paymentReceiptPaymentRecordsFromText($text, $nit);
    if ($records === []) {
      return '';
    }
    $lines = [];
    foreach ($records as $index => $record) {
      $parts = [];
      $third = trim((string) ($record['tercero'] ?? ''));
      $value = trim((string) ($record['valor'] ?? ''));
      $transaction = trim((string) ($record['transaccion'] ?? ''));
      $parts[] = 'Pago ' . ($index + 1);
      if ($third !== '') {
        $parts[] = 'tercero ' . $third;
      }
      if ($value !== '') {
        $parts[] = 'valor ' . $value;
      }
      if ($transaction !== '') {
        $parts[] = 'transaccion ' . $transaction;
      }
      $lines[] = implode(' - ', $parts);
    }
    return implode("\n", $lines);
  }

  private function cleanPaymentReceiptPdfFragment(string $value): string
  {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
    $value = preg_replace('/\b(Cuenta|Ahorros|Corriente|Davivienda|Bancolombia|Banco|Destino|Numero|Número).*$/iu', '', $value) ?? $value;
    return trim($value);
  }

  private function paymentReceiptPeriodFromName(string $name): string
  {
    $months = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre';
    if (preg_match('/\b(' . $months . ')(?:\s+(\d{4}))?\b/iu', $name, $match) !== 1) {
      return '';
    }
    $month = mb_strtoupper((string) $match[1], 'UTF-8');
    $year = trim((string) ($match[2] ?? ''));
    return trim($month . ($year !== '' ? ' ' . $year : ''));
  }

  private function paymentReceiptPropertiesFromName(string $name, string $nit, string $period): string
  {
    $value = $name;
    if ($nit !== '') {
      $value = preg_replace('/\d[\d.\-\s]{5,}\d/u', ' ', $value, 1) ?? $value;
    }
    if ($period !== '') {
      $parts = preg_split('/\s+/', $period);
      $month = preg_quote((string) ($parts[0] ?? ''), '/');
      if ($month !== '') {
        $value = preg_replace('/\b' . $month . '(?:\s+\d{4})?\b/iu', ' ', $value) ?? $value;
      }
    }
    $value = preg_replace('/[_\-]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
  }

  /** @return array<int,array{recipient:array<string,mixed>,contracts:array<int,array<string,mixed>>}> */
  private function paymentReceiptCopropiedadMatches(string $nit, string $contractStatus, string $type, string $typeLabel): array
  {
    $nit = $this->normalizeImportDocument($nit);
    if ($nit === '') {
      return [];
    }
    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    $copTable = $this->db->table('jet_cct_copropiedades');
    if (!$this->schema->tableExists($contractTable) || !$this->schema->columnExists($contractTable, 'nit_copropiedad')) {
      return [];
    }

    $nitCandidates = $this->paymentReceiptNitCandidates($nit);
    $placeholders = implode(',', array_fill(0, count($nitCandidates), '?'));
    $statusSql = '';
    $args = array_values($nitCandidates);
    if ($contractStatus !== '') {
      $statusSql = " AND LOWER(TRIM(COALESCE(c.`estado`, ''))) = ?";
      $args[] = $contractStatus === 'activos' ? 'entregado' : 'recibido';
    }

    $hasCopTable = $this->schema->tableExists($copTable);
    $join = $hasCopTable ? "LEFT JOIN `{$copTable}` cp ON CAST(c.`id_copropiedad` AS UNSIGNED) = cp.`_ID`" : '';
    $copSelect = $hasCopTable
      ? "cp.`_ID` AS cp_id, cp.`copropiedad` AS cp_copropiedad, cp.`nit` AS cp_nit, cp.`correo` AS cp_correo, cp.`contacto` AS cp_contacto, cp.`administrador` AS cp_administrador"
      : "'' AS cp_id, '' AS cp_copropiedad, '' AS cp_nit, '' AS cp_correo, '' AS cp_contacto, '' AS cp_administrador";
    $rows = $this->db->getResults(
      "SELECT c.`_ID`, c.`estado`, c.`contrato`, c.`contrato_arrendamiento`, c.`inmueble`, c.`id_inmueble`, c.`id_inmueble_data`, c.`direccion`, c.`copropiedad`, c.`nit_copropiedad`, c.`administrador`, c.`correo_copropiedad`, c.`celular_copropiedad`, c.`id_copropiedad`, {$copSelect}
         FROM `{$contractTable}` c
         {$join}
        WHERE {$this->normalizedDocumentSql("COALESCE(c.`nit_copropiedad`, '')")} IN ({$placeholders})
          {$statusSql}
        ORDER BY c.`_ID`",
      $args
    );

    $matches = [];
    foreach ($rows as $row) {
      $recipientId = (int) preg_replace('/\D+/', '', (string) ($row['cp_id'] ?? $row['id_copropiedad'] ?? ''));
      if ($recipientId <= 0) {
        $recipientId = (int) preg_replace('/\D+/', '', (string) ($row['id_copropiedad'] ?? ''));
      }
      if ($recipientId <= 0) {
        continue;
      }
      if (!isset($matches[$recipientId])) {
        $name = $this->firstNonEmpty([$row['cp_copropiedad'] ?? '', $row['copropiedad'] ?? '', $row['administrador'] ?? '', 'Copropiedad ' . $recipientId]);
        $matches[$recipientId] = [
          'recipient' => [
            '_ID' => $recipientId,
            'nombre' => $name,
            'correo' => $this->firstNonEmpty([$row['cp_correo'] ?? '', $row['correo_copropiedad'] ?? '']),
            'celular' => $this->firstNonEmpty([$row['cp_contacto'] ?? '', $row['celular_copropiedad'] ?? '']),
            'documento' => $this->firstNonEmpty([$row['cp_nit'] ?? '', $row['nit_copropiedad'] ?? $nit]),
            'tipo_actor' => $type,
            'tipo_label' => $typeLabel,
            'rol_persona' => 'Copropiedad',
          ],
          'contracts' => [],
        ];
      }
      $matches[$recipientId]['contracts'][] = $row;
    }
    return $matches;
  }

  /** @return array<string,string> */
  private function paymentReceiptNitCandidates(string $nit): array
  {
    $nit = $this->normalizeImportDocument($nit);
    if ($nit === '') {
      return [];
    }

    $candidates = [$nit => $nit];
    if (strlen($nit) > 8) {
      $withoutCheckDigit = substr($nit, 0, -1);
      if ($withoutCheckDigit !== '') {
        $candidates[$withoutCheckDigit] = $withoutCheckDigit;
      }
    }

    return $candidates;
  }

  /** @param array{name:string,tmp_name:string,error:int,size:int,type:string} $file @return array{path:string,name:string,stored_name:string,url:string} */
  private function storePaymentReceiptUpload(array $file, string $nit): array
  {
    $dir = defined('SCM_UPLOAD_PATH')
      ? (string) SCM_UPLOAD_PATH
      : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new \RuntimeException('No se pudo crear la carpeta para comprobantes.');
    }
    $originalName = basename((string) ($file['name'] ?? 'comprobante.pdf'));
    $safeName = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $originalName) ?: 'comprobante.pdf';
    $safeName = trim($safeName, ' ._-');
    if ($safeName === '') {
      $safeName = 'comprobante.pdf';
    }
    $storedName = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $target = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
    $tmp = (string) ($file['tmp_name'] ?? '');
    $stored = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
    if (!$stored || !is_readable($target)) {
      throw new \RuntimeException('No se pudo guardar el comprobante PDF.');
    }
    $url = '';
    try {
      $url = StoredFileService::fromRuntime()->urlFor($storedName);
    } catch (\Throwable) {
      $url = '';
    }
    return ['path' => $target, 'name' => $safeName, 'stored_name' => $storedName, 'url' => $url];
  }

  /** @param array<int,array<string,mixed>> $contracts @return string[] */
  private function paymentReceiptContractLabels(array $contracts): array
  {
    $labels = [];
    foreach ($contracts as $contract) {
      $number = $this->firstNonEmpty([$contract['contrato'] ?? '', $contract['contrato_arrendamiento'] ?? '', $contract['_ID'] ?? '']);
      $property = $this->firstNonEmpty([$contract['inmueble'] ?? '', $contract['id_inmueble'] ?? '', $contract['id_inmueble_data'] ?? '']);
      $address = $this->firstNonEmpty([$contract['direccion'] ?? '']);
      $parts = [];
      if ($number !== '') {
        $parts[] = '#' . $number;
      }
      if ($property !== '') {
        $parts[] = 'Inmueble ' . $property;
      }
      if ($address !== '') {
        $parts[] = $address;
      }
      if ($parts !== []) {
        $labels[] = implode(' - ', $parts);
      }
    }
    return array_values(array_unique($labels));
  }

  /** @param array<int,array<string,mixed>> $contracts @return array<int,array<string,mixed>> */
  private function filterPaymentReceiptContracts(array $contracts, string $properties, string $pdfDetail): array
  {
    $tokens = $this->paymentReceiptPropertyTokens($properties . ' ' . $pdfDetail);
    if ($tokens === [] || $contracts === []) {
      return $contracts;
    }

    $filtered = [];
    foreach ($contracts as $contract) {
      $haystack = $this->normalizePaymentReceiptMatchText(implode(' ', array_map('strval', [
        $contract['contrato'] ?? '',
        $contract['contrato_arrendamiento'] ?? '',
        $contract['inmueble'] ?? '',
        $contract['id_inmueble'] ?? '',
        $contract['id_inmueble_data'] ?? '',
        $contract['direccion'] ?? '',
      ])));
      foreach ($tokens as $token) {
        if ($token !== '' && str_contains($haystack, $token)) {
          $filtered[] = $contract;
          break;
        }
      }
    }

    return $filtered !== [] ? array_values($filtered) : $contracts;
  }

  /** @return string[] */
  private function paymentReceiptPropertyTokens(string $value): array
  {
    $value = $this->normalizePaymentReceiptMatchText($value);
    $tokens = [];
    if (preg_match_all('/\b(?:APTO|APT|APARTAMENTO|AP)\s*([A-Z0-9-]{1,12})\b/u', $value, $matches) > 0) {
      foreach ($matches[1] as $match) {
        $token = trim((string) $match);
        if ($token !== '') {
          $tokens[$token] = $token;
        }
      }
    }
    if (preg_match_all('/\bTORRE\s*([A-Z0-9-]{1,12})\b/u', $value, $matches) > 0) {
      foreach ($matches[1] as $match) {
        $token = 'TORRE ' . trim((string) $match);
        $tokens[$token] = $token;
      }
    }
    return array_values($tokens);
  }

  private function normalizePaymentReceiptMatchText(string $value): string
  {
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = strtr($value, [
      'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U', 'ñ' => 'N',
      'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
  }

  /** @param array<string,mixed> $meta @param array<int,array<string,mixed>> $contracts */
  private function paymentReceiptDetail(array $meta, array $contracts): string
  {
    $lines = [];
    $receipts = is_array($meta['comprobantes_pago'] ?? null) ? $meta['comprobantes_pago'] : [];
    $lines[] = 'Soportes adjuntos: ' . max(1, count($receipts));
    $nit = trim((string) ($meta['documento_excel'] ?? ''));
    if ($nit !== '') {
      $lines[] = 'NIT: ' . $nit;
    }
    $period = trim((string) ($meta['mes_excel'] ?? ''));
    if ($period !== '') {
      $lines[] = 'Periodo: ' . $period;
    }
    $properties = trim((string) ($meta['inmueble_simi_excel'] ?? ''));
    if ($properties !== '') {
      $lines[] = 'Inmueble(s) indicados en el nombre del PDF: ' . $properties;
    }
    $pdfTextRead = trim((string) ($meta['pdf_texto_leido'] ?? ''));
    $pdfPayments = (int) ($meta['pagos_pdf'] ?? 0);
    if ($pdfTextRead !== '') {
      $lines[] = 'PDF leido: ' . $pdfTextRead;
    }
    if ($pdfPayments > 0) {
      $lines[] = 'Pagos detectados en PDF: ' . $pdfPayments;
    }
    $pdfDetail = trim((string) ($meta['pagos_pdf_detalle'] ?? ''));
    if ($pdfDetail !== '') {
      $lines[] = 'Detalle leído del PDF:';
      foreach (preg_split('/\R+/', $pdfDetail) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
          $lines[] = '- ' . $line;
        }
      }
    }
    $contractLabels = $this->paymentReceiptContractLabels($contracts);
    foreach (array_filter(array_map('trim', explode(',', (string) ($meta['contratos_sistema'] ?? '')))) as $label) {
      $contractLabels[] = $label;
    }
    $contractLabels = array_values(array_unique($contractLabels));
    if ($contractLabels !== []) {
      $lines[] = 'Contrato(s) relacionados:';
      foreach ($contractLabels as $label) {
        $lines[] = '- ' . $label;
      }
    }
    $pdfUrls = [];
    $url = trim((string) ($meta['url_pdf'] ?? ''));
    if ($url !== '') {
      $pdfUrls[$url] = $url;
    }
    foreach ($receipts as $receipt) {
      if (!is_array($receipt)) {
        continue;
      }
      $receiptUrl = trim((string) ($receipt['url'] ?? ''));
      if ($receiptUrl !== '') {
        $pdfUrls[$receiptUrl] = $receiptUrl;
      }
    }
    if ($pdfUrls !== []) {
      $lines[] = count($pdfUrls) === 1 ? 'Link del comprobante PDF:' : 'Links de comprobantes PDF:';
      foreach ($pdfUrls as $pdfUrl) {
        $lines[] = '- ' . $pdfUrl;
      }
    }
    return implode("\n", $lines);
  }

  /** @param array<string,mixed> $base @param array<string,mixed> $extra @param array<int,array<string,mixed>> $contracts @return array<string,mixed> */
  private function mergePaymentReceiptMeta(array $base, array $extra, array $contracts): array
  {
    $baseReceipts = is_array($base['comprobantes_pago'] ?? null) ? $base['comprobantes_pago'] : [];
    $extraReceipts = is_array($extra['comprobantes_pago'] ?? null) ? $extra['comprobantes_pago'] : [];
    $base['comprobantes_pago'] = array_merge($baseReceipts, $extraReceipts);
    foreach (['inmueble_simi_excel', 'mes_excel', 'contratos_sistema', 'url_pdf'] as $key) {
      $values = array_filter(array_map('trim', explode(',', (string) ($base[$key] ?? ''))));
      $extraValues = array_filter(array_map('trim', explode(',', (string) ($extra[$key] ?? ''))));
      $merged = array_values(array_unique(array_merge($values, $extraValues)));
      $base[$key] = implode(', ', $merged);
    }
    foreach (['pagos_pdf_detalle'] as $key) {
      $values = array_filter(array_map('trim', preg_split('/\R+/', (string) ($base[$key] ?? '')) ?: []));
      $extraValues = array_filter(array_map('trim', preg_split('/\R+/', (string) ($extra[$key] ?? '')) ?: []));
      $merged = array_values(array_unique(array_merge($values, $extraValues)));
      $base[$key] = implode("\n", $merged);
    }
    $base['pagos_pdf'] = (string) ((int) ($base['pagos_pdf'] ?? 0) + (int) ($extra['pagos_pdf'] ?? 0));
    $base['pdf_texto_leido'] = (($base['pdf_texto_leido'] ?? '') === 'Si' || ($extra['pdf_texto_leido'] ?? '') === 'Si') ? 'Si' : 'No';
    $base['detalle_excel'] = $this->paymentReceiptDetail($base, $contracts);
    return $base;
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
    if ($ext === 'xls') {
      return $this->parseXlsImportFile($tmp);
    }
    if ($ext === 'xlsx') {
      return $this->parseXlsxImportFile($tmp);
    }
    throw new \RuntimeException('Formato no soportado. Sube un archivo .xls, .xlsx o .csv.');
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
  private function parseXlsImportFile(string $path): array
  {
    $book = new LegacyXlsReader($path);
    if (!$book->success()) {
      throw new \RuntimeException('No se pudo leer el archivo XLS: ' . (string) $book->error());
    }

    $rows = $book->rows(0, 3001);
    return array_map(static function (array $row): array {
      return array_values(array_map(static function ($value): string {
        return trim((string) $value);
      }, $row));
    }, $rows);
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
    $sheetData = $sheet->sheetData ?? null;
    if (!$sheetData && $mainNs !== '') {
      $sheetRoot = $sheet->children($mainNs);
      $sheetData = $sheetRoot->sheetData ?? null;
    }

    $rows = [];
    $xmlRows = $sheetData ? $sheetData->row : [];
    if ($sheetData && count($xmlRows) === 0 && $mainNs !== '') {
      $xmlRows = $sheetData->children($mainNs)->row;
    }
    foreach ($xmlRows as $xmlRow) {
      $cells = [];
      $max = -1;
      $xmlCells = $xmlRow->c;
      if (count($xmlCells) === 0 && $mainNs !== '') {
        $xmlCells = $xmlRow->children($mainNs)->c;
      }
      foreach ($xmlCells ?? [] as $cell) {
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
      $inline = $cell->is ?? ($children->is ?? null);
      if ($inline instanceof \SimpleXMLElement) {
        $text = (string) ($inline->t ?? '');
        if ($text === '' && $mainNs !== '') {
          $inlineChildren = $inline->children($mainNs);
          $text = (string) ($inlineChildren->t ?? '');
        }
        return trim($text);
      }
      return '';
    }
    $value = (string) ($cell->v ?? '');
    if ($value === '') {
      $value = (string) ($children->v ?? '');
    }
    return trim($value);
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
        if ($this->isImportAlias($header, $this->importContractAliases())) {
          $score += 2;
        }
        if ($this->isImportAlias($header, $this->importPropertyAliases())) {
          $score += 2;
        }
        if ($this->isImportAlias($header, $this->importDocumentAliases())) {
          $score += 2;
        }
        if ($this->isImportAlias($header, $this->importCanonAliases())) {
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

  /** @return string[] */
  private function importContractAliases(): array
  {
    return ['contrato', 'contrato_arrendamiento', 'numero_contrato', 'nro_contrato', 'no_contrato', 'num_contrato'];
  }

  /** @return string[] */
  private function importPropertyAliases(): array
  {
    return [
      'inmueble',
      'inmueble_simi',
      'codigo_inmueble',
      'id_inmueble',
      'numero_inmueble',
      'nro_inmueble',
      'no_inmueble',
      'num_inmueble',
      'simi',
      'noinm',
      'no_inm',
      'nro_inm',
      'num_inm',
      'cod_inm',
    ];
  }

  /** @return string[] */
  private function importCanonAliases(): array
  {
    return ['canon', 'valor', 'total', 'valor_total', 'valor_canon', 'canon_arrendamiento', 'valor_arriendo', 'renta'];
  }

  /** @return string[] */
  private function importDocumentAliases(): array
  {
    return ['documento', 'cedula', 'cedula_arrendatario', 'identificacion', 'identificacion_arrendatario', 'nit', 'cc'];
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
    $contract = $this->normalizeImportIdentifier($this->importValue($headers, $row, $this->importContractAliases()));
    $property = $this->normalizeImportIdentifier($this->importValue($headers, $row, $this->importPropertyAliases()));
    $document = $this->normalizeImportDocument($this->importValue($headers, $row, $this->importDocumentAliases()));
    $canonRaw = $this->importValue($headers, $row, $this->importCanonAliases());
    $meta = [
      'contrato_excel' => $contract,
      'inmueble_simi_excel' => $property,
      'documento_excel' => $document,
      'canon_excel' => $this->formatImportMoney($canonRaw),
      'canon_excel_raw' => trim($canonRaw),
      'mes_excel' => trim($this->importValue($headers, $row, ['mes', 'periodo', 'mes_canon', 'periodo_canon'])),
      'direccion_excel' => trim($this->importValue($headers, $row, ['direccion', 'direccion_inmueble'])),
    ];
    $meta['detalle_excel'] = $this->importCanonSummary($meta);
    return $meta;
  }

  private function normalizeImportDocument(string $value): string
  {
    $value = trim(mb_strtoupper($value, 'UTF-8'));
    if ($value === '') {
      return '';
    }
    if (preg_match('/^\d+(?:[.,]0+)?$/', $value) === 1) {
      return (string) ((int) ((float) str_replace(',', '.', $value)));
    }
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits !== '') {
      return $digits;
    }
    $value = preg_replace('/[^A-Z0-9]/u', '', $value) ?? $value;
    return trim($value);
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
    $document = trim((string) ($meta['documento_excel'] ?? ''));
    if ($document !== '') {
      $parts[] = 'Documento: ' . $document;
    }
    $month = trim((string) ($meta['mes_excel'] ?? ''));
    if ($month !== '') {
      $parts[] = 'Periodo: ' . $month;
    }
    return implode("\n", $parts);
  }

  /** @param array<mixed> $metaMap @return array<string,array<string,mixed>> */
  private function sanitizeImportMetaMap(array $metaMap): array
  {
    $out = [];
    foreach ($metaMap as $id => $meta) {
      $id = (string) ((int) $id);
      if ($id === '0' || !is_array($meta)) {
        continue;
      }
      $clean = [];
      $limits = [
        'detalle_excel' => 3000,
        'contratos_sistema' => 2000,
        'pagos_pdf_detalle' => 2000,
        'url_pdf' => 900,
        'ruta_pdf' => 900,
      ];
      foreach (['contrato_excel', 'inmueble_simi_excel', 'documento_excel', 'canon_excel', 'canon_excel_raw', 'mes_excel', 'direccion_excel', 'detalle_excel', 'archivo_pdf', 'archivo_pdf_guardado', 'ruta_pdf', 'url_pdf', 'contratos_sistema', 'pdf_texto_leido', 'pagos_pdf', 'pagos_pdf_detalle'] as $key) {
        $limit = (int) ($limits[$key] ?? 500);
        $clean[$key] = trim(mb_substr((string) ($meta[$key] ?? ''), 0, $limit, 'UTF-8'));
      }
      $receipts = is_array($meta['comprobantes_pago'] ?? null) ? $meta['comprobantes_pago'] : [];
      $cleanReceipts = [];
      foreach ($receipts as $receipt) {
        if (!is_array($receipt)) {
          continue;
        }
        $path = trim((string) ($receipt['path'] ?? ''));
        $name = trim((string) ($receipt['name'] ?? ''));
        if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf' || !is_readable($path)) {
          continue;
        }
        $cleanReceipts[] = [
          'path' => $path,
          'name' => $name !== '' ? $name : basename($path),
          'stored_name' => trim((string) ($receipt['stored_name'] ?? '')),
          'url' => trim((string) ($receipt['url'] ?? '')),
          'nit' => trim((string) ($receipt['nit'] ?? '')),
          'periodo' => trim((string) ($receipt['periodo'] ?? '')),
          'inmuebles' => trim((string) ($receipt['inmuebles'] ?? '')),
          'pagos_pdf' => trim((string) ($receipt['pagos_pdf'] ?? '')),
          'pagos_pdf_detalle' => trim((string) ($receipt['pagos_pdf_detalle'] ?? '')),
        ];
        if (count($cleanReceipts) >= 20) {
          break;
        }
      }
      if ($cleanReceipts !== []) {
        $clean['comprobantes_pago'] = $cleanReceipts;
      }
      $hasText = implode('', array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $clean)) !== '';
      if (!$hasText && $cleanReceipts === []) {
        continue;
      }
      if (($clean['detalle_excel'] ?? '') === '') {
        $clean['detalle_excel'] = $this->importCanonSummary($clean);
      }
      $out[$id] = $clean;
    }
    return $out;
  }

  /** @param array<mixed> $metaMap @return array<string,array<string,mixed>> */
  private function sanitizeNotificationMetaMap(array $metaMap): array
  {
    $out = [];
    foreach ($metaMap as $id => $meta) {
      $id = (string) ((int) $id);
      if ($id === '0' || !is_array($meta)) {
        continue;
      }
      $notificationMeta = is_array($meta['__notification_meta'] ?? null) ? $meta['__notification_meta'] : [];
      if ($notificationMeta === []) {
        continue;
      }
      $cleanMeta = [];
      $collection = is_array($notificationMeta['collection_management'] ?? null) ? $notificationMeta['collection_management'] : [];
      $managements = is_array($collection['managements'] ?? null) ? $collection['managements'] : [];
      $cleanManagements = [];
      $ids = [];
      foreach ($managements as $management) {
        if (!is_array($management)) {
          continue;
        }
        $managementId = (int) ($management['id'] ?? 0);
        if ($managementId <= 0) {
          continue;
        }
        $ids[] = $managementId;
        $cleanManagements[] = [
          'id' => $managementId,
          'contract_id' => (int) ($management['contract_id'] ?? 0),
          'contract_number' => mb_substr(trim((string) ($management['contract_number'] ?? '')), 0, 80, 'UTF-8'),
          'property' => mb_substr(trim((string) ($management['property'] ?? '')), 0, 80, 'UTF-8'),
          'type' => mb_substr(trim((string) ($management['type'] ?? '')), 0, 80, 'UTF-8'),
        ];
      }
      if ($cleanManagements !== []) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $value): bool => $value > 0)));
        $cleanMeta['collection_management'] = [
          'ids' => $ids,
          'lookup' => '|' . implode('|', $ids) . '|',
          'managements' => $cleanManagements,
        ];
      }
      $dueDate = is_array($notificationMeta['collection_due_date'] ?? null) ? $notificationMeta['collection_due_date'] : [];
      $dueDay = (int) ($dueDate['day'] ?? 0);
      if (in_array($dueDay, [12, 22, 26, 31], true)) {
        $cleanMeta['collection_due_date'] = [
          'day' => $dueDay,
          'ordinal' => $this->collectionDueDateOrdinal($dueDay),
        ];
      }
      if ($cleanMeta === []) {
        continue;
      }
      $out[$id] = $cleanMeta;
    }
    return $out;
  }

  /** @param array<string,array<string,string>> $metaMap @param array<string,array<string,mixed>> $notificationMetaMap @return array<int,array<string,mixed>> */
  private function recipients(string $type, array $ids, array $metaMap = [], array $notificationMetaMap = []): array
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
      if ($id !== '0' && isset($notificationMetaMap[$id])) {
        $row['_scm_notification_meta'] = $notificationMetaMap[$id];
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
      $documentValue = $filterKey === 'document' ? $this->normalizeImportDocument($value) : '';
      foreach ($columns as $column) {
        if ($documentValue !== '') {
          $parts[] = $this->normalizedDocumentSql("COALESCE(`{$column}`, '')") . ' = ?';
          $args[] = $documentValue;
        } else {
          $parts[] = $this->collatedTextSql("COALESCE(`{$column}`, '')") . ' LIKE ?';
          $args[] = $like;
        }
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

  private function normalizedDocumentSql(string $expression): string
  {
    $sql = "UPPER({$this->collatedTextSql($expression)})";
    foreach (['.', ',', '-', ' ', '/'] as $needle) {
      $escaped = str_replace("'", "''", $needle);
      $sql = "REPLACE({$sql}, '{$escaped}', '')";
    }
    return $sql;
  }

  /**
   * @param array<int,array<string,mixed>> $managements
   * @return array<int,array<string,mixed>>
   */
  private function collectionCodeudorRecipients(array $managements): array
  {
    $managementsByContract = [];
    $contractIds = [];
    foreach ($managements as $management) {
      if (!is_array($management)) {
        continue;
      }
      $contractId = (int) ($management['contract_id'] ?? 0);
      $managementId = (int) ($management['id'] ?? 0);
      if ($contractId <= 0 || $managementId <= 0) {
        continue;
      }
      $contractIds[$contractId] = $contractId;
      $managementsByContract[$contractId][] = $management;
    }
    if ($contractIds === []) {
      return [];
    }

    $contractTable = $this->db->table('jet_cct_contratos_arrendamiento');
    if (!$this->schema->tableExists($contractTable)) {
      return [];
    }
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
    $select = ['_ID'];
    foreach ([
      'contrato',
      'contrato_arrendamiento',
      'id_inmueble',
      'inmueble',
      'id_inmueble_data',
      'direccion',
      'arrendatario',
      'num_codeudores',
      'id_codeudor',
      'id_codeudores',
      'codeudores_ids',
      'id_codeudor_1',
      'codeudor_1',
      'celular_codeudor_1',
      'correo_codeudor_1',
      'documento_codeudor_1',
      'dir_codeudor_1',
      'id_codeudor_2',
      'codeudor_2',
      'celular_codeudor_2',
      'correo_codeudor_2',
      'documento_codeudor_2',
      'dir_codeudor_2',
      'id_codeudor_3',
      'codeudor_3',
      'celular_codeudor_3',
      'correo_codeudor_3',
      'documento_codeudor_3',
      'dir_codeudor_3',
      'id_codeudor_4',
      'codeudor_4',
      'celular_codeudor_4',
      'correo_codeudor_4',
      'documento_codeudor_4',
      'dir_codeudor_4',
    ] as $column) {
      if ($this->schema->columnExists($contractTable, $column)) {
        $select[] = $column;
      }
    }
    $contracts = $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($select))) . "` FROM `{$contractTable}` WHERE CAST(`_ID` AS UNSIGNED) IN ({$placeholders})",
      array_values($contractIds)
    );

    $out = [];
    $seen = [];
    foreach ($contracts as $contract) {
      $contractId = (int) ($contract['_ID'] ?? 0);
      if ($contractId <= 0) {
        continue;
      }
      $contractNumber = $this->firstNonEmpty([$contract['contrato'] ?? '', $contract['contrato_arrendamiento'] ?? '', $contractId]);
      $contractManagements = $managementsByContract[$contractId] ?? [];
      foreach ($this->collectionCodeudoresFromTable($contract, $contractManagements) as $recipient) {
        $key = $this->codeudorRecipientKey($recipient, $contractId);
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = true;
        $out[] = $recipient;
      }
      foreach ($this->collectionCodeudoresFromContractColumns($contract, $contractManagements) as $recipient) {
        $key = $this->codeudorRecipientKey($recipient, $contractId);
        if (isset($seen[$key])) {
          continue;
        }
        $seen[$key] = true;
        $out[] = $recipient;
      }
    }
    return $out;
  }

  /**
   * @param array<int,array<string,mixed>> $contracts
   * @return array<int,array<int,array<string,mixed>>>
   */
  private function collectionCodeudoresFromTableForContracts(array $contracts): array
  {
    $table = $this->db->table('jet_cct_codeudores');
    if ($contracts === [] || !$this->schema->tableExists($table)) {
      return [];
    }

    $select = ['_ID'];
    foreach ([
      'id_contrato',
      'id_codeudor',
      'nombre',
      'correo',
      'celular',
      'indicativo',
      'documento',
      'direccion',
      'bloqueo_email',
      'bloqueo_sms',
      'bloqueo_whatsapp',
    ] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = $column;
      }
    }

    $contractsById = [];
    $contractLookup = [];
    $codeudorLookup = [];
    foreach ($contracts as $contract) {
      $contractId = (int) ($contract['_ID'] ?? 0);
      if ($contractId <= 0) {
        continue;
      }
      $contractsById[$contractId] = $contract;
      // jet_cct_codeudores.id_contrato almacena el _ID interno del contrato.
      // El numero visible de contrato puede coincidir con el _ID de otro registro.
      $contractLookup[(string) $contractId][$contractId] = $contractId;
      foreach ($this->collectionCodeudorIdsFromContract($contract) as $codeudorId) {
        $codeudorLookup[$codeudorId][$contractId] = $contractId;
      }
    }
    if ($contractsById === []) {
      return [];
    }

    $where = [];
    $args = [];
    if ($this->schema->columnExists($table, 'id_contrato') && $contractLookup !== []) {
      $values = array_keys($contractLookup);
      $where[] = 'TRIM(COALESCE(`id_contrato`, \'\')) IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
      array_push($args, ...$values);
    }
    if ($codeudorLookup !== []) {
      $ids = array_map('intval', array_keys($codeudorLookup));
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $where[] = "CAST(`_ID` AS UNSIGNED) IN ({$placeholders})";
      array_push($args, ...$ids);
      if ($this->schema->columnExists($table, 'id_codeudor')) {
        $where[] = "CAST(`id_codeudor` AS UNSIGNED) IN ({$placeholders})";
        array_push($args, ...$ids);
      }
    }
    if ($where === []) {
      return [];
    }

    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($select))) . "` FROM `{$table}` WHERE " . implode(' OR ', array_map(static fn(string $part): string => '(' . $part . ')', $where)) . ' ORDER BY `_ID` ASC',
      $args
    );
    $out = [];
    foreach ($rows as $row) {
      $contractIds = [];
      $contractRef = trim((string) ($row['id_contrato'] ?? ''));
      foreach (($contractLookup[$contractRef] ?? []) as $contractId) {
        $contractIds[$contractId] = $contractId;
      }
      foreach ([(int) ($row['_ID'] ?? 0), (int) ($row['id_codeudor'] ?? 0)] as $codeudorId) {
        foreach (($codeudorLookup[$codeudorId] ?? []) as $contractId) {
          $contractIds[$contractId] = $contractId;
        }
      }
      foreach ($contractIds as $contractId) {
        if (!isset($contractsById[$contractId])) {
          continue;
        }
        $out[$contractId][] = $this->collectionCodeudorRecipient(
          $row,
          $contractsById[$contractId],
          [],
          'tabla'
        );
      }
    }
    return $out;
  }

  /**
   * @param array<string,mixed> $contract
   * @param array<int,array<string,mixed>> $managements
   * @return array<int,array<string,mixed>>
   */
  private function collectionCodeudoresFromTable(array $contract, array $managements): array
  {
    $table = $this->db->table('jet_cct_codeudores');
    if (!$this->schema->tableExists($table)) {
      return [];
    }
    $contractId = (int) ($contract['_ID'] ?? 0);

    $select = ['_ID'];
    if ($this->schema->columnExists($table, 'id_contrato')) {
      $select[] = 'id_contrato';
    }
    foreach ([
      'id_codeudor',
      'nombre',
      'correo',
      'celular',
      'indicativo',
      'documento',
      'direccion',
      'bloqueo_email',
      'bloqueo_sms',
      'bloqueo_whatsapp',
    ] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $select[] = $column;
      }
    }
    $whereParts = [];
    $args = [];
    if ($contractId > 0 && $this->schema->columnExists($table, 'id_contrato')) {
      $whereParts[] = "TRIM(COALESCE(`id_contrato`, '')) = ?";
      $args[] = (string) $contractId;
    }
    $codeudorIds = $this->collectionCodeudorIdsFromContract($contract);
    if ($codeudorIds !== []) {
      $idPlaceholders = implode(',', array_fill(0, count($codeudorIds), '?'));
      $whereParts[] = "CAST(`_ID` AS UNSIGNED) IN ({$idPlaceholders})";
      array_push($args, ...$codeudorIds);
      if ($this->schema->columnExists($table, 'id_codeudor')) {
        $whereParts[] = "CAST(`id_codeudor` AS UNSIGNED) IN ({$idPlaceholders})";
        array_push($args, ...$codeudorIds);
      }
    }
    if ($whereParts === []) {
      return [];
    }
    $rows = $this->db->getResults(
      'SELECT `' . implode('`, `', array_values(array_unique($select))) . "` FROM `{$table}` WHERE " . implode(' OR ', array_map(static fn(string $part): string => '(' . $part . ')', $whereParts)) . " ORDER BY `_ID` ASC",
      $args
    );
    $out = [];
    foreach ($rows as $row) {
      $out[] = $this->collectionCodeudorRecipient($row, $contract, $managements, 'tabla');
    }
    return $out;
  }

  /** @param array<string,mixed> $contract @return int[] */
  private function collectionCodeudorIdsFromContract(array $contract): array
  {
    $ids = [];
    foreach ([
      'id_codeudor',
      'id_codeudores',
      'codeudores_ids',
      'id_codeudor_1',
      'id_codeudor_2',
      'id_codeudor_3',
      'id_codeudor_4',
    ] as $column) {
      $raw = trim((string) ($contract[$column] ?? ''));
      if ($raw === '') {
        continue;
      }
      if (preg_match_all('/\d+/', $raw, $matches)) {
        foreach (($matches[0] ?? []) as $match) {
          $id = (int) $match;
          if ($id > 0) {
            $ids[$id] = $id;
          }
        }
      }
    }
    return array_values($ids);
  }

  /**
   * @param array<string,mixed> $contract
   * @param array<int,array<string,mixed>> $managements
   * @return array<int,array<string,mixed>>
   */
  private function collectionCodeudoresFromContractColumns(array $contract, array $managements): array
  {
    $out = [];
    $contractId = (int) ($contract['_ID'] ?? 0);
    for ($i = 1; $i <= 4; $i++) {
      $name = trim((string) ($contract["codeudor_{$i}"] ?? ''));
      $email = trim((string) ($contract["correo_codeudor_{$i}"] ?? ''));
      $phone = trim((string) ($contract["celular_codeudor_{$i}"] ?? ''));
      $document = trim((string) ($contract["documento_codeudor_{$i}"] ?? ''));
      if ($name === '' && $email === '' && $phone === '' && $document === '') {
        continue;
      }
      $out[] = $this->collectionCodeudorRecipient([
        '_ID' => 1000000000 + ($contractId * 10) + $i,
        'nombre' => $name !== '' ? $name : 'Codeudor ' . $i,
        'correo' => $email,
        'celular' => $phone,
        'indicativo' => '',
        'documento' => $document,
        'direccion' => trim((string) ($contract["dir_codeudor_{$i}"] ?? '')),
      ], $contract, $managements, 'contrato_' . $i);
    }
    return $out;
  }

  /**
   * @param array<string,mixed> $row
   * @param array<string,mixed> $contract
   * @param array<int,array<string,mixed>> $managements
   * @return array<string,mixed>
   */
  private function collectionCodeudorRecipient(array $row, array $contract, array $managements, string $source): array
  {
    $contractId = (int) ($contract['_ID'] ?? 0);
    $contractNumber = $this->firstNonEmpty([$contract['contrato'] ?? '', $contract['contrato_arrendamiento'] ?? '', $contractId]);
    $property = $this->firstNonEmpty([$contract['inmueble'] ?? '', $contract['id_inmueble'] ?? '', $contract['id_inmueble_data'] ?? '']);
    $managementIds = [];
    $cleanManagements = [];
    foreach ($managements as $management) {
      if (!is_array($management)) {
        continue;
      }
      $managementId = (int) ($management['id'] ?? 0);
      if ($managementId <= 0) {
        continue;
      }
      $managementIds[$managementId] = $managementId;
      $cleanManagements[] = [
        'id' => $managementId,
        'contract_id' => $contractId,
        'contract_number' => (string) ($management['contract_number'] ?? $contractNumber),
        'property' => (string) ($management['property'] ?? $property),
        'type' => (string) ($management['type'] ?? ''),
      ];
    }

    $recipient = [
      '_ID' => (int) ($row['_ID'] ?? 0),
      'nombre' => trim((string) ($row['nombre'] ?? '')),
      'correo' => trim((string) ($row['correo'] ?? '')),
      'celular' => trim((string) ($row['celular'] ?? '')),
      'indicativo' => trim((string) ($row['indicativo'] ?? '')),
      'documento' => trim((string) ($row['documento'] ?? '')),
      'tipo_actor' => 'codeudores',
      'tipo_label' => 'Codeudores',
      'rol_persona' => 'Codeudor',
      '_scm_notification_meta' => [
        'collection_management' => [
          'ids' => array_values($managementIds),
          'lookup' => $managementIds !== [] ? '|' . implode('|', array_values($managementIds)) . '|' : '',
          'managements' => $cleanManagements,
        ],
        'codeudor' => [
          'source' => $source,
          'contract_id' => $contractId,
          'contract_number' => (string) $contractNumber,
          'property' => (string) $property,
          'address' => $this->firstNonEmpty([$row['direccion'] ?? '', $contract['direccion'] ?? '']),
          'arrendatario' => trim((string) ($contract['arrendatario'] ?? '')),
        ],
      ],
    ];
    foreach (['bloqueo_email', 'bloqueo_sms', 'bloqueo_whatsapp'] as $column) {
      if (array_key_exists($column, $row)) {
        $recipient[$column] = $row[$column];
      }
    }
    return $recipient;
  }

  /** @param array<string,mixed> $recipient */
  private function codeudorRecipientKey(array $recipient, int $contractId): string
  {
    $document = preg_replace('/\D+/', '', (string) ($recipient['documento'] ?? '')) ?: '';
    $email = strtolower(trim((string) ($recipient['correo'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string) ($recipient['celular'] ?? '')) ?: '';
    $name = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) ($recipient['nombre'] ?? ''))));
    if ($document !== '') {
      return implode('|', [$contractId, 'documento', $document]);
    }
    if ($email !== '') {
      return implode('|', [$contractId, 'correo', $email]);
    }
    if ($phone !== '') {
      return implode('|', [$contractId, 'celular', $phone, $name]);
    }
    return $name !== '' ? implode('|', [$contractId, 'nombre', $name]) : '';
  }

  private function collectionManagementLookupFromRecipient(array $recipient): string
  {
    $meta = is_array($recipient['_scm_notification_meta'] ?? null) ? $recipient['_scm_notification_meta'] : [];
    $collection = is_array($meta['collection_management'] ?? null) ? $meta['collection_management'] : [];
    $lookup = trim((string) ($collection['lookup'] ?? ''));
    return preg_match('/^\|[0-9|]+\|$/', $lookup) ? $lookup : '';
  }

  /** @param array<string,mixed> $whatsappTemplateConfig */
  private function insertQueueRow(string $channel, string $destination, array $recipient, string $subject, string $message, string $batchId, array $whatsappTemplateConfig = [], array $emailTemplateConfig = []): bool
  {
    $now = gmdate('Y-m-d H:i:s');
    $message = $this->normalizeNotificationLineBreaks($message);
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
      $components = [];
      $headerComponent = $this->whatsappTemplateHeaderComponent($whatsappTemplateConfig, $recipient);
      if ($headerComponent !== []) {
        $components[] = $headerComponent;
      }
      $parameters = $this->whatsappTemplateParameters($whatsappTemplateConfig, $name, $message, $recipient);
      if ($parameters !== []) {
        $components[] = [
          'type' => 'body',
          'parameters' => $parameters,
        ];
      }
      $payload = [
        'type' => 'template',
        'template_name' => $templateName,
        'template_language' => $templateLanguage,
      ];
      if ($components !== []) {
        $payload['components'] = $components;
      }
    } elseif ($channel === 'email') {
      $templateName = trim((string) ($emailTemplateConfig['name'] ?? ''));
      $attachments = $this->emailAttachmentsFromImportMeta($recipient);
      if ($attachments !== []) {
        $payload['attachments'] = $attachments;
      }
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
        'context_meta' => is_array($recipient['_scm_notification_meta'] ?? null) ? $recipient['_scm_notification_meta'] : [],
        'test_mode' => is_array($recipient['_scm_test_mode'] ?? null) ? $recipient['_scm_test_mode'] : [],
        'collection_management_lookup' => $this->collectionManagementLookupFromRecipient($recipient),
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
      'message_text' => $this->plainText($message),
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

  /** @param array<string,mixed> $recipient @return array<int,array{path:string,name:string}> */
  private function emailAttachmentsFromImportMeta(array $recipient): array
  {
    $meta = is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [];
    $receipts = is_array($meta['comprobantes_pago'] ?? null) ? $meta['comprobantes_pago'] : [];
    $attachments = [];
    foreach ($receipts as $receipt) {
      if (!is_array($receipt)) {
        continue;
      }
      $path = trim((string) ($receipt['path'] ?? ''));
      if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf' || !is_readable($path)) {
        continue;
      }
      $attachments[] = [
        'path' => $path,
        'name' => trim((string) ($receipt['name'] ?? '')) ?: basename($path),
      ];
    }
    return $attachments;
  }

  /** @return array<string,mixed> */
  private function whatsappTemplateConfig(string $templateName): array
  {
    $templateName = trim($templateName);
    $templateAliases = [
      'nueva_factura' => 'scm_factura_disponible_v1',
      'scm_factura_disponible_v2' => 'scm_factura_disponible_v1',
      'cupones' => 'scm_arrendatario_fecha_pago_v1',
      'scm_cupon_disponible_v1' => 'scm_arrendatario_fecha_pago_v1',
      'scm_cupon_disponible_v2' => 'scm_arrendatario_fecha_pago_v1',
      'scm_arrendatario_fecha_cobro_v1' => 'scm_arrendatario_fecha_pago_v1',
      'scm_aviso_siniestro_v1' => 'scm_aviso_siniestro_v2',
    ];
    if (isset($templateAliases[$templateName])) {
      $templateName = $templateAliases[$templateName];
    }
    if ($templateName === '') {
      $templateName = self::DEFAULT_WHATSAPP_TEMPLATE;
    }
    $templates = $this->whatsappTemplates();
    if (is_array($templates[$templateName] ?? null)) {
      return $templates[$templateName] + ['template_id' => $templateName];
    }
    foreach ($templates as $templateId => $template) {
      if (trim((string) ($template['name'] ?? '')) === $templateName) {
        return $template + ['template_id' => $templateId];
      }
    }
    return [];
  }

  /** @return array<string,mixed> */
  private function emailTemplateConfig(string $templateName): array
  {
    $templateName = trim($templateName);
    $templateAliases = [
      'scm_email_mes_generado_pago_v1' => 'scm_email_cupon_disponible_v1',
    ];
    if (isset($templateAliases[$templateName])) {
      $templateName = $templateAliases[$templateName];
    }
    if ($templateName === '') {
      $templateName = self::DEFAULT_EMAIL_TEMPLATE;
    }
    $templates = $this->emailTemplates();
    return is_array($templates[$templateName] ?? null) ? $templates[$templateName] : $templates[self::DEFAULT_EMAIL_TEMPLATE];
  }

  /** @param array<string,mixed> $templateConfig */
  private function whatsappTemplateNeedsMessage(array $templateConfig): bool
  {
    $mode = (string) ($templateConfig['parameter_mode'] ?? 'name_message_signature');
    return $mode === 'name_message_signature' || $mode === 'name_message';
  }

  /** @param array<string,mixed> $templateConfig @param array<string,mixed> $recipient @return array<string,mixed> */
  private function whatsappTemplateHeaderComponent(array $templateConfig, array $recipient = []): array
  {
    $headerType = strtolower(trim((string) ($templateConfig['header_type'] ?? '')));
    if ($headerType === '') {
      return [];
    }

    $media = $headerType === 'document' ? $this->whatsappTemplateDocumentHeaderFromRecipient($templateConfig, $recipient) : [];
    $mediaUrl = trim((string) ($media['url'] ?? ''));
    if ($mediaUrl === '') {
      $mediaUrl = $this->whatsappTemplateHeaderUrl($templateConfig);
    }
    if ($mediaUrl === '') {
      if (!empty($templateConfig['header_required'])) {
        $templateName = trim((string) ($templateConfig['name'] ?? ''));
        throw new \RuntimeException("La plantilla WhatsApp {$templateName} requiere un archivo de encabezado.");
      }
      return [];
    }

    if ($headerType === 'image') {
      return [
        'type' => 'header',
        'parameters' => [
          [
            'type' => 'image',
            'image' => ['link' => $mediaUrl],
          ],
        ],
      ];
    }

    if ($headerType === 'document') {
      $document = ['link' => $mediaUrl];
      $filename = trim((string) ($media['filename'] ?? ''));
      if ($filename !== '') {
        $document['filename'] = mb_substr($filename, 0, 240, 'UTF-8');
      }
      return [
        'type' => 'header',
        'parameters' => [
          [
            'type' => 'document',
            'document' => $document,
          ],
        ],
      ];
    }

    return [];
  }

  /** @param array<string,mixed> $templateConfig @param array<string,mixed> $recipient @return array{url:string,filename:string}|array{} */
  private function whatsappTemplateDocumentHeaderFromRecipient(array $templateConfig, array $recipient): array
  {
    if (strtolower(trim((string) ($templateConfig['header_media_source'] ?? ''))) !== 'import_receipt_pdf') {
      return [];
    }

    $meta = is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [];
    $candidates = [];
    $url = trim((string) ($meta['url_pdf'] ?? ''));
    if ($url !== '') {
      $candidates[] = [
        'url' => $url,
        'filename' => trim((string) ($meta['archivo_pdf'] ?? '')) ?: 'comprobante.pdf',
      ];
    }
    $receipts = is_array($meta['comprobantes_pago'] ?? null) ? $meta['comprobantes_pago'] : [];
    foreach ($receipts as $receipt) {
      if (!is_array($receipt)) {
        continue;
      }
      $receiptUrl = trim((string) ($receipt['url'] ?? ''));
      if ($receiptUrl === '') {
        continue;
      }
      $candidates[] = [
        'url' => $receiptUrl,
        'filename' => trim((string) ($receipt['name'] ?? '')) ?: 'comprobante.pdf',
      ];
    }

    foreach ($candidates as $candidate) {
      $candidateUrl = trim((string) ($candidate['url'] ?? ''));
      if ($candidateUrl !== '' && filter_var($candidateUrl, FILTER_VALIDATE_URL)) {
        return [
          'url' => $candidateUrl,
          'filename' => trim((string) ($candidate['filename'] ?? '')) ?: 'comprobante.pdf',
        ];
      }
    }

    return [];
  }

  /** @param array<string,mixed> $templateConfig */
  private function whatsappTemplateHeaderUrl(array $templateConfig): string
  {
    $envName = trim((string) ($templateConfig['header_media_env'] ?? ''));
    if ($envName !== '') {
      $envValue = trim((string) getenv($envName));
      if ($envValue !== '' && filter_var($envValue, FILTER_VALIDATE_URL)) {
        return $envValue;
      }
    }

    $systemKey = trim((string) ($templateConfig['header_media_key'] ?? ''));
    if ($systemKey !== '' && function_exists('system_image')) {
      $systemValue = trim((string) \system_image($systemKey, ''));
      if ($systemValue !== '' && filter_var($systemValue, FILTER_VALIDATE_URL)) {
        return $systemValue;
      }
    }

    $directValue = trim((string) ($templateConfig['header_media_url'] ?? ''));
    return $directValue !== '' && filter_var($directValue, FILTER_VALIDATE_URL) ? $directValue : '';
  }

  private function normalizeNotificationLineBreaks(string $message): string
  {
    $message = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $message);
    $message = str_replace(["\r\n", "\r"], "\n", $message);
    return trim($message);
  }

  /** @param array<string,mixed> $whatsappTemplateConfig @param array<string,mixed> $emailTemplateConfig */
  private function templateCanUseImportDetail(array $whatsappTemplateConfig, array $emailTemplateConfig): bool
  {
    return (string) ($whatsappTemplateConfig['template_id'] ?? '') === 'scm_propietario_arriendo_consignado_v1'
      || (string) ($whatsappTemplateConfig['name'] ?? '') === 'scm_propietario_arriendo_consignado_v1'
      || $this->isCopropiedadPaymentSupportTemplate($whatsappTemplateConfig, $emailTemplateConfig)
      || (string) ($emailTemplateConfig['name'] ?? '') === 'scm_email_propietario_arriendo_consignado_v1'
      || (string) ($emailTemplateConfig['name'] ?? '') === 'scm_email_copropiedad_soportes_pago_v1';
  }

  /** @param array<string,mixed> $whatsappTemplateConfig @param array<string,mixed> $emailTemplateConfig */
  private function isCopropiedadPaymentSupportTemplate(array $whatsappTemplateConfig, array $emailTemplateConfig): bool
  {
    return (string) ($whatsappTemplateConfig['template_id'] ?? '') === 'scm_copropiedad_soportes_pago_v1'
      || (string) ($whatsappTemplateConfig['name'] ?? '') === 'scm_copropiedad_soportes_pago_v1'
      || (string) ($emailTemplateConfig['name'] ?? '') === 'scm_email_copropiedad_soportes_pago_v1';
  }

  /** @param array<string,mixed> $recipient @param array<string,mixed> $whatsappTemplateConfig @param array<string,mixed> $emailTemplateConfig */
  private function messageForRecipient(string $message, array $recipient, array $whatsappTemplateConfig, array $emailTemplateConfig, string $channel = ''): string
  {
    if ((string) ($whatsappTemplateConfig['parameter_mode'] ?? '') === 'static' && $this->plainText($message) === '') {
      return trim((string) ($whatsappTemplateConfig['body'] ?? ''));
    }
    if (!$this->templateCanUseImportDetail($whatsappTemplateConfig, $emailTemplateConfig)) {
      return $message;
    }
    $meta = is_array($recipient['_scm_import_meta'] ?? null) ? $recipient['_scm_import_meta'] : [];
    if ($meta === []) {
      return $message;
    }
    $cleanMeta = $this->sanitizeImportMetaMap(['1' => $meta])['1'] ?? [];
    $detail = $this->normalizeNotificationLineBreaks((string) ($cleanMeta['detalle_excel'] ?? ''));
    if ($detail === '') {
      $detail = $this->importCanonSummary($cleanMeta);
    }
    if ($channel === 'whatsapp'
      && strtolower(trim((string) ($whatsappTemplateConfig['header_type'] ?? ''))) === 'document'
      && $this->whatsappTemplateDocumentHeaderFromRecipient($whatsappTemplateConfig, $recipient) !== []
    ) {
      $detail = $this->removePaymentReceiptPdfLinksFromDetail($detail);
    }
    return $detail !== '' ? $detail : $message;
  }

  private function removePaymentReceiptPdfLinksFromDetail(string $detail): string
  {
    $lines = preg_split('/\R+/', $detail) ?: [];
    $out = [];
    $skipPdfLinks = false;
    foreach ($lines as $line) {
      $line = trim((string) $line);
      if ($line === '') {
        if (!$skipPdfLinks) {
          $out[] = $line;
        }
        continue;
      }
      if (preg_match('/^Links? del comprobante PDF:/iu', $line) === 1) {
        $skipPdfLinks = true;
        continue;
      }
      if ($skipPdfLinks && str_starts_with($line, '- ') && str_contains($line, '/file.php?n=')) {
        continue;
      }
      $skipPdfLinks = false;
      $out[] = $line;
    }
    return trim(implode("\n", $out));
  }

  /** @param array<string,mixed> $templateConfig @return array<int,array{type:string,text:string}> */
  private function whatsappTemplateParameters(array $templateConfig, string $recipientName, string $message, array $recipient = []): array
  {
    $name = trim($recipientName) !== '' ? trim($recipientName) : 'Cliente';
    $signature = (string) ($this->senderProfile()['signature_line'] ?? 'Control Servicios Inmobiliarios');
    $mode = (string) ($templateConfig['parameter_mode'] ?? 'name_message_signature');
    if ($mode === 'static') {
      return [];
    }
    if ($mode === 'name_signature') {
      return [
        $this->whatsappTextParameter($name),
        $this->whatsappTextParameter($signature),
      ];
    }
    if ($mode === 'name_message') {
      $messageWithSignature = trim($message);
      if ($messageWithSignature !== '' && $signature !== '') {
        $messageWithSignature .= "\n\nAtentamente,\n" . $signature;
      }
      return [
        $this->whatsappTextParameter($name),
        $this->whatsappTextParameter($messageWithSignature),
      ];
    }
    if ($mode === 'collection_due_date') {
      $meta = is_array($recipient['_scm_notification_meta'] ?? null) ? $recipient['_scm_notification_meta'] : [];
      $due = is_array($meta['collection_due_date'] ?? null) ? $meta['collection_due_date'] : [];
      $day = (int) ($due['day'] ?? 12);
      if (!in_array($day, [12, 22, 26, 31], true)) {
        $day = 12;
      }
      $ordinal = trim((string) ($due['ordinal'] ?? ''));
      if ($ordinal === '') {
        $ordinal = $this->collectionDueDateOrdinal($day);
      }
      return [
        $this->whatsappTextParameter($name),
        $this->whatsappTextParameter((string) $day),
        $this->whatsappTextParameter($ordinal),
        $this->whatsappTextParameter($signature),
      ];
    }
    return [
      $this->whatsappTextParameter($name),
      $this->whatsappTextParameter($message),
      $this->whatsappTextParameter($signature),
    ];
  }

  /** @return array{type:string,text:string} */
  private function whatsappTextParameter(string $text): array
  {
    return ['type' => 'text', 'text' => $this->sanitizeWhatsAppTemplateParameterText($text)];
  }

  private function sanitizeWhatsAppTemplateParameterText(string $text): string
  {
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = $this->normalizeNotificationLineBreaks($text);
    $text = preg_replace('/[\r\n\t]+/u', ' | ', $text) ?? $text;
    $text = preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    $text = trim($text, " \t\n\r\0\x0B|");
    return mb_substr($text, 0, 1024, 'UTF-8');
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
    $rendered = '';
    if (!empty($templateConfig['fixed_body'])) {
      $rendered = $this->resolveVariables($body, $recipient, $config);
    } elseif (!str_contains($body, '{{mensaje}}') && !str_contains($body, '{{custom_message}}')) {
      if (!empty($templateConfig['is_full_document']) || $this->isFullEmailHtml($body)) {
        $rendered = $this->resolveVariables($body, $recipient, $config);
      } else {
        $rendered = $this->resolveVariables($message !== '' ? $message : $body, $recipient, $config);
      }
    } else {
      $messageForEmail = $body !== strip_tags($body) ? $this->emailContentHtml($message) : $message;
      $body = str_replace(['{{mensaje}}', '{{custom_message}}'], $messageForEmail, $body);
      $rendered = $this->resolveVariables($body, $recipient, $config);
    }

    return $this->appendGuardianMenuBlock($rendered, $recipient);
  }

  /** @param array<string,mixed> $recipient */
  private function appendGuardianMenuBlock(string $html, array $recipient): string
  {
    $menuUrl = $this->personalMenuUrl($recipient);
    if ($menuUrl === '') {
      return $html;
    }

    $block = $this->guardianMenuBlockHtml($menuUrl);
    if ($this->isFullEmailHtml($html)) {
      $count = 0;
      $htmlWithBlock = preg_replace('/<\/body\s*>/i', $block . '</body>', $html, 1, $count);
      if ($htmlWithBlock !== null && $count > 0) {
        return $htmlWithBlock;
      }
    }

    return $html . $block;
  }

  /** @param array<string,mixed> $recipient */
  private function personalMenuUrl(array $recipient): string
  {
    $type = strtolower(trim((string) ($recipient['tipo_actor'] ?? '')));
    $role = strtolower(trim((string) ($recipient['rol_persona'] ?? '')));
    $value = $type . ' ' . $role;

    if (str_contains($value, 'propietario')) {
      return 'https://sucasainmobiliaria.com.co/propietario/';
    }
    if (str_contains($value, 'arrendatario')) {
      return 'https://sucasainmobiliaria.com.co/arrendatario';
    }
    if (str_contains($value, 'copropiedad')) {
      return 'https://sucasainmobiliaria.com.co/copropiedad';
    }
    if (str_contains($value, 'cliente')) {
      return 'https://sucasainmobiliaria.com.co/cliente/';
    }

    return '';
  }

  private function guardianMenuBlockHtml(string $menuUrl): string
  {
    $guardianUrl = 'https://sucasainmobiliaria.com.co/guardian/';
    $buttonStyle = 'display:inline-block;background:#404041;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:5px;margin:6px;';

    return '<div style="margin-top:24px;padding:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
      . '<p style="margin:0 0 10px;">&#129302; &iquest;Dudas, quejas o inconvenientes? Escr&iacute;bele a nuestro <strong>Bot Guardi&aacute;n</strong> desde el bot&oacute;n de abajo.</p>'
      . '<p style="margin:0 0 14px;">&#127760; Recuerda que puedes ingresar a tu <strong>men&uacute; personal en nuestra p&aacute;gina web</strong> para consultar informaci&oacute;n y gestionar tus servicios.</p>'
      . '<div style="text-align:center;">'
      . '<a href="' . htmlspecialchars($guardianUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="' . $buttonStyle . '">Hablar con Guardi&aacute;n</a>'
      . '<a href="' . htmlspecialchars($menuUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="' . $buttonStyle . '">Ir a mi men&uacute;</a>'
      . '</div></div>';
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

  /** @param string[] $channels @return array{enabled:bool,email:string,phone:string} */
  private function sanitizeTestModeOptions(array $options, array $channels): array
  {
    $enabled = filter_var($options['enabled'] ?? false, FILTER_VALIDATE_BOOL);
    if (!$enabled) {
      return ['enabled' => false, 'email' => '', 'phone' => ''];
    }

    $email = trim((string) ($options['email'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    $phone = $this->normalizePhone((string) ($options['phone'] ?? ''));
    if (in_array('email', $channels, true) && $email === '') {
      throw new \RuntimeException('En modo prueba, escribe un correo de prueba valido.');
    }
    if ((in_array('sms', $channels, true) || in_array('whatsapp', $channels, true)) && $phone === '') {
      throw new \RuntimeException('En modo prueba, escribe un celular de prueba valido.');
    }

    return ['enabled' => true, 'email' => $email, 'phone' => $phone];
  }

  /** @param array{enabled:bool,email:string,phone:string} $testOptions */
  private function testModeDestination(array $testOptions, string $channel): string
  {
    if ($channel === 'email') {
      return (string) ($testOptions['email'] ?? '');
    }
    return $this->normalizePhone((string) ($testOptions['phone'] ?? ''), '', $channel === 'whatsapp');
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
    $cargoId = trim(Auth::userCargo());
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
        $rowCargoId = trim((string) ($row['id_cargo'] ?? ''));
        if ($rowCargoId !== '') {
          $cargoId = $rowCargoId;
        }
        $cargo = trim((string) ($row['rol'] ?? $cargo)) ?: $cargo;
      }
    }

    // El cargo visible debe provenir del catálogo de cargos. El campo `rol`
    // queda únicamente como respaldo para instalaciones con datos incompletos.
    $cargoName = $this->cargoName($cargoId);
    if ($cargoName !== '') {
      $cargo = $cargoName;
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

  /** @param array<string,mixed> $config @return array{name:string,email:string,phone:string,document:string,indicator:string} */
  private function resolveColumns(array $config): array
  {
    $table = (string) $config['table'];
    return [
      'name' => $this->detect($table, (array) $config['name']),
      'email' => $this->detect($table, (array) $config['email']),
      'phone' => $this->detect($table, (array) $config['phone']),
      'document' => $this->detect($table, (array) ($config['document'] ?? [])),
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
