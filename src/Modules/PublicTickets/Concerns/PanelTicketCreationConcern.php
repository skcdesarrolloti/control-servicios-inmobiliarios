<?php

declare(strict_types=1);

namespace SCM\Modules\PublicTickets\Concerns;

use SCM\Core\Auth;
use SCM\Modules\Pending\PublicServicesCriticalTicket;
use SCM\Support\EmailTemplate;
use SCM\Support\FuncionarioOptions;
use SCM\Support\InternalNotificationRecipients;
use SCM\Support\PanelCaseAttachments;
use SCM\Support\PanelCaseNotifications;
use SCM\Support\SharedNotificationsBridge;

trait PanelTicketCreationConcern
{
  public function panelCaseOptions(): array
  {
    $themes = [
      'Reparaciones necesarias',
      'Reparaciones locativas',
      'Mejoras utiles',
      'Reparaciones voluntarias',
      'Contable y tributaria',
      'Certificaciones tributarias',
      'Procesos juridicos',
      'Solicitud contractual',
      'Solicitud de servicios publicos',
      'Otros servicios',
      'Reparaciones antes de la entrega',
      'Reparaciones antes del recibo',
      PublicServicesCriticalTicket::TOPIC,
    ];
    $departments = ['Servicio al propietario', 'Servicio al arrendatario', 'Servicio a la copropiedad'];
    $themeDepartments = [PublicServicesCriticalTicket::TOPIC => 'Servicio al arrendatario'];
    return [
      'themes' => $themes,
      'departments' => $departments,
      'theme_departments' => $themeDepartments,
      'employees' => FuncionarioOptions::panelFuncionarios($this->db, $this->schema, 'employee', null, true),
      'max_file_bytes' => min((int) SCM_UPLOAD_MAX_BYTES, 10 * 1024 * 1024),
      'whatsapp_enabled' => PanelCaseNotifications::config()['enabled'],
      'ai' => \SCM\Support\PanelCaseAi::availability(),
      'draft_scope' => hash_hmac('sha256', 'panel-case-draft:' . Auth::employeeId(), (string) SCM_APP_SECRET),
    ];
  }

  public function searchPanelContracts(string $query, string $by): array
  {
    $columnsByType = [
      'contrato' => ['contrato'],
      'inmueble' => ['inmueble', 'id_inmueble'],
      'propietario' => ['propietario', 'id_propietario', 'documento_propietario'],
      'arrendatario' => ['arrendatario', 'id_arrendatario', 'documento_arrendatario'],
    ];
    if (!isset($columnsByType[$by]) || mb_strlen(trim($query)) < 2 || mb_strlen($query) > 100) {
      throw new \InvalidArgumentException('Selecciona el tipo de búsqueda y escribe entre 2 y 100 caracteres.');
    }
    $table = $this->db->table('jet_cct_contratos_arrendamiento');
    $where = [];
    $args = [];
    foreach ($columnsByType[$by] as $column) {
      if ($this->schema->columnExists($table, $column)) {
        $where[] = "`{$column}` LIKE ?";
        $args[] = '%' . $this->db->escapeLike(trim($query)) . '%';
      }
    }
    if (in_array($by, ['propietario', 'arrendatario'], true)) {
      $personTable = $this->db->table('jet_cct_' . ($by === 'propietario' ? 'propietarios' : 'arrendatarios'));
      $personId = 'id_' . $by;
      if ($this->schema->columnExists($table, $personId) && $this->schema->tableExists($personTable)) {
        $identities = [];
        foreach (['_ID', $personId] as $column) {
          if ($this->schema->columnExists($personTable, $column)) $identities[] = "CAST(p.`{$column}` AS CHAR) = `{$table}`.`{$personId}`";
        }
        $documents = [];
        foreach (['documento', 'documento_juridico'] as $column) {
          if ($this->schema->columnExists($personTable, $column)) {
            $documents[] = "p.`{$column}` LIKE ?";
          }
        }
        if ($identities !== [] && $documents !== []) {
          $where[] = "EXISTS (SELECT 1 FROM `{$personTable}` p WHERE (" . implode(' OR ', $identities) . ') AND (' . implode(' OR ', $documents) . '))';
          foreach ($documents as $_document) $args[] = '%' . $this->db->escapeLike(trim($query)) . '%';
        }
      }
    }
    if ($where === []) return [];
    $select = ['_ID'];
    foreach (['contrato', 'inmueble', 'direccion', 'propietario', 'arrendatario', 'estado'] as $column) {
      if ($this->schema->columnExists($table, $column)) $select[] = $column;
    }
    return $this->db->getResults('SELECT `' . implode('`, `', $select) . "` FROM `{$table}` WHERE (" . implode(' OR ', $where) . ') ORDER BY `_ID` DESC LIMIT 30', $args);
  }

  /** Authenticated panel entrypoint. Never called by the public portal. */
  public function createPanelTicket(array $input): array
  {
    if (!Auth::isLoggedIn()) throw new \InvalidArgumentException('Inicia sesión para crear casos.');
    $options = $this->panelCaseOptions();
    $title = trim(strip_tags((string) ($input['asunto'] ?? '')));
    $description = trim(strip_tags((string) ($input['descripcion'] ?? '')));
    $theme = trim((string) ($input['tema_ayuda'] ?? ''));
    $department = trim((string) ($input['departamento'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200 || $description === '' || mb_strlen($description) > 10000) {
      throw new \InvalidArgumentException('Completa el título (máximo 200 caracteres) y la descripción (máximo 10.000).');
    }
    if (!in_array($theme, $options['themes'], true) || !in_array($department, $options['departments'], true)) {
      throw new \InvalidArgumentException('Selecciona un tema y un departamento válidos.');
    }
    $employees = array_column($options['employees'], null, 'employee_id');
    $activeEmployees = array_column(FuncionarioOptions::activeFuncionarios($this->db, $this->schema, 'employee', true), null, 'employee_id');
    $creator = $activeEmployees[(string) Auth::employeeId()] ?? null;
    $assignee = $employees[trim((string) ($input['id_empleado'] ?? ''))] ?? null;
    if (!$creator) throw new \InvalidArgumentException('El creador debe ser un funcionario activo.');
    if (!$assignee) throw new \InvalidArgumentException('Selecciona un responsable activo de los cargos habilitados en Configuración.');
    if (!filter_var($assignee['email'], FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('El responsable no tiene un correo válido. Actualiza su ficha antes de asignarlo.');
    }
    $contract = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_contratos_arrendamiento') . '` WHERE `_ID` = ? LIMIT 1', [(int) ($input['contract_id'] ?? 0)]);
    if (!$contract) throw new \InvalidArgumentException('Selecciona un contrato existente.');
    $notifications = new PanelCaseNotifications($this->db, $this->schema);
    $notificationPlan = $notifications->prepare($contract, $assignee, $input);
    $attachments = new PanelCaseAttachments();
    $attachments->validate($input);
    $tickets = $this->db->table('jet_cct_tickets');
    foreach (['imagenes', 'archivos'] as $field) {
      if (!empty($_FILES[$field]['name'][0]) && !$this->schema->columnExists($tickets, $field)) {
        throw new \RuntimeException('El esquema de casos no admite este tipo de adjunto.');
      }
    }
    // Queue initialization may run DDL: always prewarm before the business transaction.
    if (!(new SharedNotificationsBridge($this->db))->isAvailable()) {
      throw new \RuntimeException('La cola de notificaciones no está disponible. Intenta nuevamente.');
    }
    $stored = $attachments->store();
    $pdo = $this->db->pdo();
    try {
      $pdo->beginTransaction();
      $contact = ['name' => $creator['name'], 'email' => $creator['email'], 'phone' => $creator['phone'], 'indicativo' => '+57', 'actor_id' => $creator['employee_id']];
      $responsible = ['nombre' => $assignee['name'], 'correo' => $assignee['email'], 'celular' => $assignee['phone'], 'id_empleado' => $assignee['employee_id']];
      $result = $this->insertTicket('funcionario', ['label' => 'Funcionario', 'medium' => 'Panel administrativo'], $theme, $department, $title, $description, [], $contact, $responsible, $contract, null, $creator['employee_id']);
      if (empty($result['ok'])) throw new \RuntimeException('No se pudo guardar el caso.');
      $id = (int) $result['ticket_id'];
      $data = $this->schema->filterTableData($tickets, [
        'imagenes' => implode(',', array_column($stored['images'], 'url')),
        'archivos' => serialize($stored['documents']),
      ]);
      if ($data !== []) $this->db->update($tickets, $data, ['_ID' => $id]);
      $this->insertPanelCaseHistory($id, $title, $creator, $assignee, $contract);
      $recipients = InternalNotificationRecipients::emailsForAction($this->db, 'nuevo_caso_panel');
      $recipients[] = $assignee['email'];
      $recipients = array_values(array_unique(array_map('strtolower', $recipients)));
      $content = '<p>Se creó el caso <b>#' . $id . '</b> en SKC SuCasa Inmobiliaria.</p>';
      foreach (['Título' => $title, 'Tema' => $theme, 'Departamento' => $department, 'Responsable' => $assignee['name'], 'Contrato' => $contract['contrato'] ?? '', 'Inmueble SIMI' => $contract['inmueble'] ?? '', 'Dirección' => $contract['direccion'] ?? '', 'Creado por' => $creator['name']] as $label => $value) {
        $content .= '<p><b>' . EmailTemplate::e($label) . ':</b> ' . EmailTemplate::e((string) $value) . '</p>';
      }
      $content .= '<p>' . nl2br(EmailTemplate::e($description)) . '</p><p>Adjuntos: ' . (count($stored['images']) + count($stored['documents'])) . '.</p>';
      $url = \SCM\Support\PublicCaseAccess::url($id, 'funcionario');
      $queued = $this->emailQueue->enqueue($recipients, 'Nuevo caso #' . $id . ': ' . $title, EmailTemplate::render('Nuevo caso asignado', $content, ['ticket_url' => $url]), [
        'source_module' => 'nuevo_caso_panel', 'dedupe_key' => 'nuevo_caso_panel:' . $id,
        'meta' => ['ticket_id' => $id, 'contract_id' => (int) $contract['_ID'], 'created_by_employee_id' => $creator['employee_id'], 'assigned_employee_id' => $assignee['employee_id']],
      ]);
      if ($queued !== count($recipients)) throw new \RuntimeException('No se pudieron encolar todos los correos. El caso no se guardó; intenta nuevamente.');
      $extra = $notifications->enqueue($notificationPlan, $id, $contract, $title, $description, $theme, $creator, $recipients);
      $pdo->commit();
      $queued += $extra['email'];
      return ['ticket_id' => $id, 'queued' => $queued, 'whatsapp_queued' => $extra['whatsapp'], 'notification_details' => $extra['details'], 'message' => 'Caso #' . $id . ' creado. Correos encolados: ' . $queued . '. WhatsApp encolados: ' . $extra['whatsapp'] . '. ' . $extra['warning']];
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $attachments->cleanup($stored);
      throw $e;
    }
  }

  private function insertPanelCaseHistory(int $id, string $title, array $creator, array $assignee, array $contract): void
  {
    $now = time();
    $base = ['cct_status' => 'publish', 'cct_author_id' => $creator['employee_id'], 'id_empleado' => $creator['employee_id'], 'id_ticket' => $id, 'fecha' => $now, 'cct_created' => date('Y-m-d H:i:s', $now), 'cct_modified' => date('Y-m-d H:i:s', $now)];
    $histories = [
      // Creation is an audit event for the property, not a consultant response.
      'jet_cct_historial_del_inmueble' => ['funcionario' => $creator['name'], 'observacion' => 'Caso creado desde el panel. Asignado a ' . $assignee['name'] . '. ' . $title, 'tipo_reporte' => 'Ticket', 'id_inmueble' => $contract['id_inmueble'] ?? '', 'id_inmueble_data' => $contract['id_inmueble_data'] ?? ''],
    ];
    foreach ($histories as $name => $fields) {
      $table = $this->db->table($name);
      if (!$this->schema->tableExists($table)) continue;
      $data = $this->schema->filterTableData($table, array_merge($base, $fields));
      if ($data !== []) $this->db->insert($table, $data);
    }
  }
}
