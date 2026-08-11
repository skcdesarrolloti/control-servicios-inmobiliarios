<?php

namespace SCM\Modules\PublicTickets;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;
use SCM\Support\SmsQueue;

final class PublicTicketsService
{
  use \SCM\Modules\PublicTickets\Concerns\PublicPortalQueriesConcern;
  use \SCM\Modules\PublicTickets\Concerns\PublicTicketCreationConcern;
  use \SCM\Modules\PublicTickets\Concerns\RequesterLookupConcern;
  use \SCM\Modules\PublicTickets\Concerns\ResponsibleResolutionConcern;
  use \SCM\Modules\PublicTickets\Concerns\TicketPersistenceConcern;
  use \SCM\Modules\PublicTickets\Concerns\PublicTicketNotificationsConcern;
  use \SCM\Modules\PublicTickets\Concerns\AssignmentSettingsConcern;
  use \SCM\Modules\PublicTickets\Concerns\NormalizationAndAccessConcern;

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
  private SignedAccessTokenService $signedAccess;
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
    $this->signedAccess = new SignedAccessTokenService(
      $this->appSecret,
      defined('SCM_BASE_URL') ? (string) SCM_BASE_URL : (string) ($appConfig['base_url'] ?? '')
    );
    $this->groupComercial = trim((string) ($appConfig['group_comercial'] ?? ''));
    $override = $appConfig['cliente_notifications_override'] ?? [];
    $this->clienteNotificationsOverride = is_array($override) ? $override : [];
  }

  /** @return array<string,mixed> */
}
