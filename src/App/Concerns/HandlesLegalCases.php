<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\LegalCases\LegalCaseService;
use SCM\Modules\LegalCases\LegalCommunicationService;
use SCM\Modules\LegalCases\LegalDeliveryGateway;
use SCM\Modules\PublicTickets\PublicTicketsService;
use SCM\Support\FuncionarioOptions;
use SCM\Support\SchemaInspector;

/** Actividades contractuales › Casos jurídicos. */
trait HandlesLegalCases
{
  private const LEGAL_CASE_MAX_FILES = 10;

  /** Verifica CSRF y la pestaña; las tablas se crean aquí, antes de cualquier transacción. */
  private function legalCaseService(): LegalCaseService
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('casos_juridicos')) $this->jsonFail('No tienes permiso para ver los casos jurídicos.');
    $service = new LegalCaseService($this->db, App::settings());
    $service->ensureSchema();
    return $service;
  }

  private function legalCaseManageService(): LegalCaseService
  {
    $service = $this->legalCaseService();
    $this->requireDashboardAction('legal_case_manage', 'Tu cargo no tiene permiso para gestionar casos jurídicos.');
    return $service;
  }

  /** @return array{id:string,name:string,email:string,phone:string,cargo:string} Funcionario activo real; nunca el `_ID` de la sesión. */
  private function legalCaseActor(): array
  {
    $employeeId = Auth::employeeId();
    foreach ($employeeId === '' ? [] : FuncionarioOptions::activeFuncionarios($this->db, new SchemaInspector($this->db), 'employee', true) as $person) {
      if ($person['employee_id'] === $employeeId) return ['id' => $employeeId, 'name' => $person['name'], 'email' => $person['email'], 'phone' => $person['phone'], 'cargo' => $person['cargo']];
    }
    throw new \DomainException('Tu usuario no está asociado a un funcionario activo.');
  }

  private function legalCaseFail(\Throwable $error, string $fallback): void
  {
    if ($error instanceof \DomainException || $error instanceof \InvalidArgumentException) $this->jsonFail($error->getMessage());
    error_log('[legal-case] ' . $error->getMessage());
    $this->jsonFail($fallback);
  }

  private function legalCasePost(string $key): string
  {
    return trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
  }

  private function legalCaseText(string $key): string
  {
    return trim(sanitize_textarea_field(wp_unslash((string) ($_POST[$key] ?? ''))));
  }

  /** @return array<string,string> */
  private function legalCaseInput(): array
  {
    $input = [];
    foreach (['name', 'type_id', 'contract_id', 'property', 'address', 'responsible_id', 'opened_date', 'due_date', 'next_action'] as $key) $input[$key] = $this->legalCasePost($key);
    $input['description'] = $this->legalCaseText('description');
    return $input;
  }

  /** @return array<string,string> */
  private function legalCasePartyInput(): array
  {
    $party = [];
    foreach (['role', 'name', 'company', 'document', 'email', 'phone'] as $key) $party[$key] = $this->legalCasePost($key);
    return $party;
  }

  /** @return list<array<string,mixed>> */
  private function legalCaseJsonList(string $key): array
  {
    $decoded = json_decode(wp_unslash((string) ($_POST[$key] ?? '[]')), true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
  }

  /** Guarda los archivos del campo (las fotos se comprimen); si uno no sirve se detiene para avisar cuál. @return list<array<string,mixed>> */
  private function legalCaseUploads(string $field): array
  {
    $raw = $_FILES[$field] ?? null;
    if (!is_array($raw) || !is_array($raw['name'] ?? null)) return [];
    $indexes = array_values(array_filter(array_keys($raw['name']), static fn($index): bool => (int) ($raw['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (count($indexes) > self::LEGAL_CASE_MAX_FILES) throw new \DomainException('Puedes cargar máximo ' . self::LEGAL_CASE_MAX_FILES . ' archivos a la vez.');
    $stored = [];
    foreach ($indexes as $index) {
      $name = trim(sanitize_text_field((string) $raw['name'][$index]));
      $result = $this->storedFiles()->storeUploadedFile(['name' => $name, 'type' => (string) ($raw['type'][$index] ?? ''), 'tmp_name' => (string) ($raw['tmp_name'][$index] ?? ''), 'error' => (int) $raw['error'][$index], 'size' => (int) ($raw['size'][$index] ?? 0)]);
      if ($result === null) throw new \DomainException("No se pudo guardar «{$name}». Revisa el tipo y el tamaño del archivo.");
      $stored[] = $result + ['original_name' => $name];
    }
    return $stored;
  }

  public function ajax_handler_legal_case_note(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $service->addNote((int) ($_POST['case_id'] ?? 0), $this->legalCaseText('text'), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Nota guardada.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo guardar la nota.');
    }
  }

  public function ajax_handler_legal_case_reply(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $input = ['channel' => $this->legalCasePost('channel'), 'from' => $this->legalCasePost('from'), 'subject' => $this->legalCasePost('subject'), 'received_date' => $this->legalCasePost('received_date'), 'body' => $this->legalCaseText('body')];
      $service->addManualReply((int) ($_POST['case_id'] ?? 0), $input, $this->legalCaseUploads('files'), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Respuesta registrada.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo registrar la respuesta.');
    }
  }

  public function ajax_handler_legal_case_documents(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $service->addDocuments((int) ($_POST['case_id'] ?? 0), $this->legalCaseUploads('files'), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Documentos cargados.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudieron cargar los documentos.');
    }
  }

  private function legalCommunicationService(LegalCaseService $service): LegalCommunicationService
  {
    return new LegalCommunicationService($this->db, $service, $this->storedFiles(), new LegalDeliveryGateway($this->db));
  }

  /** El cuerpo llega con HTML: LegalHtmlSanitizer lo limpia con lista blanca en el servicio. @return array<string,mixed> */
  private function legalCommunicationInput(): array
  {
    $list = static function (string $key): array {
      $decoded = json_decode(wp_unslash((string) ($_POST[$key] ?? '[]')), true);
      return is_array($decoded) ? array_values($decoded) : [];
    };
    return ['channels' => $list('channels'), 'recipients' => $list('recipients'), 'document_ids' => $list('document_ids'), 'subject' => $this->legalCasePost('subject'), 'body' => wp_unslash((string) ($_POST['body'] ?? '')), 'template_id' => (int) ($_POST['template_id'] ?? 0)];
  }

  public function ajax_handler_legal_case_preview(): void
  {
    $service = $this->legalCaseService();
    $this->requireDashboardAction('legal_case_send', 'Tu cargo no tiene permiso para enviar comunicaciones.');
    try {
      $this->jsonOk($this->legalCommunicationService($service)->preview((int) ($_POST['case_id'] ?? 0), $this->legalCommunicationInput(), $this->legalCaseActor()));
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo preparar la vista previa.');
    }
  }

  /** Con el recibo por request_id, un doble clic o un reintento de red devuelve el mismo envío en vez de repetirlo. */
  public function ajax_handler_legal_case_send(): void
  {
    $service = $this->legalCaseService();
    $this->requireDashboardAction('legal_case_send', 'Tu cargo no tiene permiso para enviar comunicaciones.');
    $requestId = (string) ($_POST['request_id'] ?? '');
    if (preg_match('/^[a-f0-9-]{36}$/D', $requestId) !== 1) $this->jsonFail('Identificador de solicitud inválido. Abre nuevamente el formulario.');
    $directory = SCM_STORAGE_PATH . '/data/legal-communication-receipts';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) $this->jsonFail('No se pudo iniciar el envío.');
    $receipt = fopen($directory . '/' . hash('sha256', Auth::employeeId() . ':' . $requestId) . '.json', 'c+');
    if (!$receipt || !flock($receipt, LOCK_EX)) $this->jsonFail('No se pudo bloquear la solicitud. Intenta nuevamente.');
    $result = null;
    $failure = null;
    try {
      $saved = json_decode((string) stream_get_contents($receipt), true);
      if (is_array($saved) && !empty($saved['communication_id'])) {
        $result = $saved;
      } else {
        $result = $this->legalCommunicationService($service)->send((int) ($_POST['case_id'] ?? 0), $this->legalCommunicationInput(), $this->legalCaseUploads('files'), $this->legalCaseActor());
        rewind($receipt);
        ftruncate($receipt, 0);
        fwrite($receipt, (string) json_encode($result));
        fflush($receipt);
      }
    } catch (\Throwable $error) {
      $failure = $error;
    } finally {
      flock($receipt, LOCK_UN);
      fclose($receipt);
    }
    if ($failure !== null) $this->legalCaseFail($failure, 'No se pudo enviar la comunicación. No se envió nada.');
    $this->jsonOk($result + ['message' => 'Comunicación enviada a la cola.']);
  }

  public function ajax_handler_legal_case_communication(): void
  {
    $service = $this->legalCaseService();
    try {
      $this->jsonOk($this->legalCommunicationService($service)->communication((int) ($_POST['communication_id'] ?? 0)));
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo abrir la comunicación.');
    }
  }

  public function ajax_handler_legal_case_communications(): void
  {
    $service = $this->legalCaseService();
    try {
      $filters = [];
      foreach (['recipient', 'case', 'channel', 'from', 'to'] as $key) $filters[$key] = $this->legalCasePost($key);
      $this->jsonOk($this->legalCommunicationService($service)->list($filters, (int) ($_POST['page'] ?? 1)));
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudieron cargar las comunicaciones.');
    }
  }

  private function legalCaseConfigService(): LegalCaseService
  {
    $service = $this->legalCaseService();
    $this->requireDashboardAction('legal_case_config', 'Tu cargo no tiene permiso para configurar los casos jurídicos.');
    return $service;
  }

  public function ajax_handler_legal_case_config(): void
  {
    $service = $this->legalCaseConfigService();
    try {
      $this->jsonOk(['types' => $service->types(), 'templates' => $this->legalCommunicationService($service)->templates(), 'variables' => LegalCommunicationService::VARIABLES]);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo cargar la configuración.');
    }
  }

  public function ajax_handler_legal_case_type_save(): void
  {
    $service = $this->legalCaseConfigService();
    try {
      $service->saveType((int) ($_POST['id'] ?? 0), $this->legalCasePost('name'), $this->legalCasePost('active') === '1');
      $this->jsonOk(['message' => 'Tipo guardado.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo guardar el tipo.');
    }
  }

  public function ajax_handler_legal_case_template_save(): void
  {
    $service = $this->legalCaseConfigService();
    try {
      $input = ['name' => $this->legalCasePost('name'), 'subject' => $this->legalCasePost('subject'), 'body' => wp_unslash((string) ($_POST['body'] ?? '')), 'active' => $this->legalCasePost('active') === '1'];
      $this->legalCommunicationService($service)->saveTemplate((int) ($_POST['id'] ?? 0), $input, $this->legalCaseActor());
      $this->jsonOk(['message' => 'Plantilla guardada.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo guardar la plantilla.');
    }
  }

  public function ajax_handler_legal_case_bootstrap(): void
  {
    $service = $this->legalCaseService();
    try {
      $this->jsonOk([
        'types' => $service->types(),
        'responsibles' => $service->responsibles(),
        'statuses' => LegalCaseService::STATUSES,
        'roles' => LegalCaseService::ROLES,
        'employee_id' => Auth::employeeId(),
        'templates' => $this->legalCommunicationService($service)->templates(true),
        'variables' => LegalCommunicationService::VARIABLES,
      ]);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo cargar el módulo.');
    }
  }

  public function ajax_handler_legal_case_list(): void
  {
    $service = $this->legalCaseService();
    try {
      $filters = [];
      foreach (['status', 'type_id', 'responsible', 'property', 'search'] as $key) $filters[$key] = $this->legalCasePost($key);
      $this->jsonOk($service->list($filters, (int) ($_POST['page'] ?? 1)));
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudieron cargar los casos.');
    }
  }

  public function ajax_handler_legal_case_detail(): void
  {
    $service = $this->legalCaseService();
    try {
      $detail = $service->detail((int) ($_POST['case_id'] ?? 0));
      $files = $this->storedFiles();
      foreach ($detail['documents'] as &$document) {
        $document['url'] = $files->urlFor($document['stored_name']);
        unset($document['stored_name']);
      }
      unset($document);
      $this->jsonOk($detail);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo abrir el caso.');
    }
  }

  public function ajax_handler_legal_case_save(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $actor = $this->legalCaseActor();
      $id = (int) ($_POST['case_id'] ?? 0);
      if ($id > 0) {
        $service->update($id, $this->legalCaseInput(), $actor);
      } else {
        $id = $service->create($this->legalCaseInput(), $this->legalCaseJsonList('parties'), $actor);
      }
      $this->jsonOk(['case_id' => $id, 'code' => LegalCaseService::code($id), 'message' => 'Caso guardado.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo guardar el caso.');
    }
  }

  public function ajax_handler_legal_case_status(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $service->changeStatus((int) ($_POST['case_id'] ?? 0), $this->legalCasePost('status'), $this->legalCaseText('note'), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Estado actualizado.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo cambiar el estado.');
    }
  }

  public function ajax_handler_legal_case_contract_search(): void
  {
    $this->legalCaseService();
    try {
      $rows = (new PublicTicketsService($this->db, ['app_secret' => SCM_APP_SECRET]))->searchPanelContracts($this->legalCasePost('query'), $this->legalCasePost('by'));
      $this->jsonOk(['rows' => array_map(static fn(array $row): array => [
        'id' => (int) ($row['_ID'] ?? 0), 'contract' => (string) ($row['contrato'] ?? ''), 'property' => (string) ($row['inmueble'] ?? ''), 'address' => (string) ($row['direccion'] ?? ''),
        'owner' => (string) ($row['propietario'] ?? ''), 'tenant' => (string) ($row['arrendatario'] ?? ''), 'status' => (string) ($row['estado'] ?? ''),
      ], $rows)]);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo buscar el contrato.');
    }
  }

  public function ajax_handler_legal_case_contract_parties(): void
  {
    $service = $this->legalCaseService();
    try {
      $this->jsonOk(['parties' => $service->contractParties((int) ($_POST['contract_id'] ?? 0))]);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudieron cargar las partes del contrato.');
    }
  }

  public function ajax_handler_legal_case_property_search(): void
  {
    $service = $this->legalCaseService();
    try {
      $this->jsonOk(['rows' => $service->searchProperties($this->legalCasePost('query'))]);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo buscar el inmueble.');
    }
  }

  public function ajax_handler_legal_case_party_add(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $service->addParty((int) ($_POST['case_id'] ?? 0), $this->legalCasePartyInput(), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Parte agregada.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo agregar la parte.');
    }
  }

  public function ajax_handler_legal_case_party_remove(): void
  {
    $service = $this->legalCaseManageService();
    try {
      $service->removeParty((int) ($_POST['case_id'] ?? 0), (int) ($_POST['party_id'] ?? 0), $this->legalCaseActor());
      $this->jsonOk(['message' => 'Parte retirada.']);
    } catch (\Throwable $error) {
      $this->legalCaseFail($error, 'No se pudo retirar la parte.');
    }
  }
}
