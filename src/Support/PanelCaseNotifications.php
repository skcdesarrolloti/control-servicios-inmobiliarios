<?php

declare(strict_types=1);

namespace SCM\Support;

use SCM\Core\App;
use SCM\Core\Database;

/** Notification plan is validated before storing the case; enqueue runs in its transaction. */
final class PanelCaseNotifications
{
  public const SETTINGS_KEY = 'panel_case_notifications';
  public const ROLES = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'copropiedad' => 'Copropiedad'];

  public function __construct(private Database $db, private SchemaInspector $schema) {}

  public static function config(): array
  {
    $raw = App::settings()->get(self::SETTINGS_KEY, []);
    return self::validateConfig(is_array($raw) ? $raw : []);
  }

  public static function validateConfig(array $raw): array
  {
    $config = [
      'enabled' => filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
      'assigned_template' => trim((string) ($raw['assigned_template'] ?? 'scm_caso_asignado_v1')),
      'external_template' => trim((string) ($raw['external_template'] ?? 'scm_caso_registrado_v2')),
      'language' => trim((string) ($raw['language'] ?? 'es_CO')),
    ];
    foreach (['assigned_template', 'external_template'] as $key) {
      if (!preg_match('/^[a-z0-9_]{1,512}$/D', $config[$key])) throw new \InvalidArgumentException('Usa nombres de plantillas de casos con minúsculas, números y guiones bajos.');
    }
    if (!preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/D', $config['language'])) throw new \InvalidArgumentException('Indica el código exacto del idioma de las plantillas, por ejemplo es_CO.');
    return $config;
  }

  public function prepare(array $contract, array $assignee, array $input): array
  {
    $roles = $input['notify_roles'] ?? [];
    if (!is_array($roles) || count($roles) > 3 || count(array_filter($roles, 'is_string')) !== count($roles) || array_diff($roles, array_keys(self::ROLES))) throw new \InvalidArgumentException('Selecciona destinatarios válidos para los avisos del caso.');
    $config = self::config();
    $external = [];
    foreach (array_unique($roles) as $role) {
      $contact = $this->contact($contract, $role);
      if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL) || $contact['phone'] === '') {
        throw new \InvalidArgumentException('Actualiza el correo y celular de ' . self::ROLES[$role] . ' en el contrato o su ficha antes de notificarle.');
      }
      $external[] = $contact;
    }
    $assignee['phone'] = self::phone((string) $assignee['phone']);
    if ($config['enabled'] && $assignee['phone'] === '') throw new \InvalidArgumentException('El responsable necesita un celular válido para recibir WhatsApp. Actualiza su ficha.');
    return ['config' => $config, 'external' => $external, 'assignee' => $assignee];
  }

  public static function phone(string $raw, string $country = '57'): string
  {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '' || str_contains($raw, '@')) return '';
    if (strlen($digits) === 10 && !str_starts_with(trim($raw), '+')) $digits = (preg_replace('/\D+/', '', $country) ?: '57') . $digits;
    return preg_match('/^[1-9][0-9]{10,14}$/D', $digits) ? '+' . $digits : '';
  }

  private function contact(array $contract, string $role): array
  {
    $record = [];
    $table = $this->db->table('jet_cct_' . ($role === 'propietario' ? 'propietarios' : ($role === 'arrendatario' ? 'arrendatarios' : 'copropiedades')));
    $id = trim((string) ($contract['id_' . $role] ?? ''));
    if ($id !== '' && $this->schema->tableExists($table)) {
      // Prefer the business identity, then the CCT primary key, without ambiguous OR matching.
      foreach (['id_' . $role, '_ID'] as $column) {
        if (!$this->schema->columnExists($table, $column)) continue;
        $record = $this->db->getRow("SELECT * FROM `{$table}` WHERE `{$column}` = ? LIMIT 1", [$id]) ?: [];
        if ($record !== []) break;
      }
    }
    $first = static function (array $values): string {
      foreach ($values as $value) if (trim((string) $value) !== '') return trim((string) $value);
      return '';
    };
    $name = $first([$contract[$role] ?? '', $record['nombre'] ?? '', $record['copropiedad'] ?? '', self::ROLES[$role]]);
    $email = $first([$contract['correo_' . $role] ?? '', $record['correo'] ?? '']);
    $rawPhone = $first([$contract['celular_' . $role] ?? '', $record['celular'] ?? '', $record['telefono'] ?? '', $record['contacto'] ?? '']);
    $country = $first([$contract['indicativo_' . $role] ?? '', $record['indicativo'] ?? '', '57']);
    return ['role' => $role, 'name' => $name, 'email' => $email, 'phone' => self::phone($rawPhone, $country)];
  }

  public function enqueue(array $plan, int $id, array $contract, string $title, string $description, string $theme, array $creator, array $internalEmails): array
  {
    $config = $plan['config'];
    $assignee = $plan['assignee'];
    $emailCount = 0;
    $whatsappCount = 0;
    $meta = ['ticket_id' => $id, 'contract_id' => (int) $contract['_ID'], 'created_by_employee_id' => $creator['employee_id'], 'assigned_employee_id' => $assignee['employee_id'], 'notify_roles' => array_column($plan['external'], 'role')];
    $queue = (new SharedNotificationsBridge($this->db))->queue();
    if (!$queue) throw new \RuntimeException('La cola de notificaciones no está disponible.');
    $phones = [];
    $emails = array_fill_keys(array_map('strtolower', $internalEmails), true);
    foreach ($plan['external'] as $person) {
      $email = strtolower($person['email']);
      if (!isset($emails[$email])) {
        $content = '<p>Hola ' . EmailTemplate::e($person['name']) . ', se registró el caso <b>#' . $id . '</b> en SKC SuCasa Inmobiliaria.</p>';
        foreach (['Título' => $title, 'Tema' => $theme, 'Contrato' => $contract['contrato'] ?? '', 'Responsable' => $assignee['name']] as $label => $value) $content .= '<p><b>' . $label . ':</b> ' . EmailTemplate::e((string) $value) . '</p>';
        $content .= '<p>' . nl2br(EmailTemplate::e($description)) . '</p>';
        $count = (new EmailQueue($this->db))->enqueue($email, 'Caso #' . $id . ' registrado: ' . $title, EmailTemplate::render('Caso registrado', $content, ['ticket_url' => PublicCaseAccess::url($id)]), [
          'source_module' => 'nuevo_caso_panel', 'dedupe_key' => 'nuevo_caso_panel:' . $id . ':externo', 'meta' => $meta + ['recipient_role' => $person['role']],
        ]);
        if ($count !== 1) throw new \RuntimeException('No se pudo encolar el correo del destinatario. El caso no se guardó.');
        $emails[$email] = true;
        $emailCount += $count;
      }
    }
    if ($config['enabled']) {
      // Assignee first; configured internal copies come from the existing event only.
      $staff = array_merge([$assignee], InternalNotificationRecipients::contactsForAction($this->db, 'nuevo_caso_panel'));
      foreach (array_merge($staff, $plan['external']) as $person) {
        $external = isset($person['role']);
        $phone = self::phone((string) $person['phone']);
        if ($phone === '' || isset($phones[$phone])) continue;
        $values = [$person['name'], (string) $id, $title, $theme, (string) ($contract['contrato'] ?? 'Sin número'), $assignee['name']];
        $values = array_map(static fn($v, $limit) => mb_substr(preg_replace('/\s+/u', ' ', trim((string) $v)) ?: 'Sin dato', 0, $limit), $values, [80, 20, 200, 100, 50, 80]);
        $components = [['type' => 'body', 'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => $v], $values)]];
        $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => PublicCaseAccess::reference($id)]]];
        $queuedId = $queue->enqueueWhatsAppOfficialTemplate($phone, $config[$external ? 'external_template' : 'assigned_template'], $components, [
          'project_code' => 'control-servicios-inmobiliarios', 'source_module' => 'nuevo_caso_panel',
          'destination_name' => $person['name'], 'template_language' => $config['language'], 'max_attempts' => 5,
          'message_text' => 'Caso #' . $id . ' · ' . $title . ' · ' . $theme,
          'dedupe_key' => 'nuevo_caso_panel:' . $id . ':whatsapp:' . $phone,
          'meta' => $meta + ['recipient_role' => $person['role'] ?? 'funcionario'], 'created_by' => $creator['employee_id'],
        ]);
        if ($queuedId <= 0) throw new \RuntimeException('No se pudo encolar WhatsApp. El caso no se guardó.');
        $phones[$phone] = true;
        $whatsappCount++;
      }
    }
    return ['email' => $emailCount, 'whatsapp' => $whatsappCount, 'warning' => $config['enabled'] ? '' : 'WhatsApp pendiente: activa las plantillas aprobadas en Configuración → Notificaciones internas → Crear casos.'];
  }
}
