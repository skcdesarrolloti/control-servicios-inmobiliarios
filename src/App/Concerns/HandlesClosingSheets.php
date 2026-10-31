<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetDocuments;
use SCM\Modules\ClosingSheets\ClosingSheetDocumentsView;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;
use SCM\Modules\ClosingSheets\ClosingSheetPanelView;
use SCM\Modules\ClosingSheets\ClosingSheetService;
use SCM\Modules\ClosingSheets\ClosingSheetTenants;
use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;

/** Hoja de cierre de negocio (hoja-cierre.php): guardar al crear o editar, buscar y registrar el arrendatario, sus documentos y el listado del panel. */
trait HandlesClosingSheets
{
  public function ajax_handler_closing_sheet_save(): void
  {
    $this->verifyCsrf();
    $closingId = (int) ($_POST['id_cierre'] ?? 0);
    $creating = $closingId <= 0;
    if (!$this->canOpenClosingSheet($creating)) {
      $this->jsonFail($creating ? 'Tu cargo no tiene permiso para añadir hojas de cierre.' : 'Tu cargo no tiene permiso para editar hojas de cierre.');
    }
    $clean = static function ($value) use (&$clean) {
      if (is_array($value)) {
        return array_map($clean, $value);
      }
      return trim(strip_tags(wp_unslash((string) $value)));
    };
    $input = [];
    foreach ($_POST as $key => $value) {
      if (is_string($key) && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
        $input[$key] = $clean($value);
      }
    }
    try {
      $result = (new ClosingSheetService($this->db))->save($input, $this->closing_sheet_sender(), $closingId);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_save] #' . $closingId . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo guardar la hoja de cierre. Intenta nuevamente.');
    }
    if ($creating) {
      // Lo que se adjuntó antes de que la hoja tuviera número queda en la hoja.
      try {
        (new ClosingSheetDocuments($this->db))->claim((int) ($input['id_contrato'] ?? 0), (int) $result['id']);
      } catch (\Throwable $error) {
        error_log('[closing_sheet_documents_claim] #' . $result['id'] . ': ' . $error->getMessage());
      }
    }
    $synced = $result['synced'] !== [] ? ' También se actualizó el mandato (' . count($result['synced']) . ' ' . (count($result['synced']) === 1 ? 'dato' : 'datos') . ').' : '';
    $this->jsonOk([
      'title' => $creating ? 'Hoja de cierre #' . $result['id'] . ' creada' : 'Hoja de cierre #' . $result['id'] . ' actualizada',
      'message' => ($creating ? 'Quedó en "' . ClosingSheetService::STATUS_NEW . '" y se avisó al equipo para la revisión de servicios públicos.' : 'Los cambios quedaron guardados.')
        . ($result['resubmitted'] ? ' Como no estaba aprobada, volvió a quedar "Esperando respuesta" y se avisó para responderla.' : '') . $synced,
      'url' => $result['url'],
      'edit_url' => $result['edit_url'],
      'id' => $result['id'],
    ]);
  }

  /** Hoja nueva › Arrendatario: buscar por nombre, documento o celular. */
  public function ajax_handler_closing_sheet_tenants(): void
  {
    $this->verifyCsrf();
    if (!$this->canOpenClosingSheet(true)) {
      $this->jsonFail('Tu cargo no tiene permiso para añadir hojas de cierre.');
    }
    $query = mb_substr(trim(strip_tags(wp_unslash((string) ($_POST['q'] ?? '')))), 0, 80, 'UTF-8');
    try {
      $items = (new ClosingSheetTenants($this->db))->search($query);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_tenants] ' . $error->getMessage());
      $this->jsonFail('No se pudo buscar el arrendatario.');
    }
    $this->jsonOk(['items' => $items]);
  }

  /** Hoja nueva › Crear arrendatario (reemplaza el JetForm "Añadir arrendatario"): registra al nuevo; si el documento existe, lo ofrece. */
  public function ajax_handler_closing_sheet_tenant_save(): void
  {
    $this->verifyCsrf();
    if (!$this->canOpenClosingSheet(true)) {
      $this->jsonFail('Tu cargo no tiene permiso para añadir hojas de cierre.');
    }
    $input = [];
    foreach (['tipo_persona', 'nombre', 'tipo_documento', 'documento', 'expedicion', 'representante_legal', 'documento_legal', 'indicativo', 'pais', 'celular', 'correo', 'direccion', 'departamento', 'ciudad', 'sexo', 'cumpleanos'] as $key) {
      $input[$key] = mb_substr(trim(strip_tags(wp_unslash((string) ($_POST[$key] ?? '')))), 0, 200, 'UTF-8');
    }
    try {
      $result = (new ClosingSheetTenants($this->db))->save($input, $this->closing_sheet_sender());
    } catch (\Throwable $error) {
      error_log('[closing_sheet_tenant_save] ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo registrar el arrendatario. Intenta nuevamente.');
    }
    $this->jsonOk([
      'tenant' => $result['tenant'],
      'exists' => $result['exists'],
      'message' => $result['exists']
        ? 'Ese documento ya está registrado a nombre de ' . $result['tenant']['nombre'] . '. Úsalo en la hoja; si algún dato cambió, corrígelo en los campos del arrendatario de la hoja y se actualiza su ficha al guardar.'
        : 'Arrendatario registrado' . ($result['notified'] ? ' y se le envió la bienvenida por correo.' : '.'),
    ]);
  }

  /** Documentos de la hoja o del contrato: sube un archivo (closing-documents.js los envía de a uno). */
  public function ajax_handler_closing_documents_upload(): void
  {
    $this->verifyCsrf();
    $scope = [];
    foreach (['closing_id', 'mandate_id', 'contract_id'] as $key) {
      $value = trim((string) ($_POST[$key] ?? ''));
      $scope[$key] = ctype_digit($value) ? (int) $value : 0;
    }
    $allowed = $this->canManageContractDocuments() || ($scope['contract_id'] <= 0 && $this->canOpenClosingSheet($scope['closing_id'] <= 0));
    if (!$allowed) {
      $this->jsonFail('Tu cargo no tiene permiso para subir documentos aquí.');
    }
    $file = $_FILES['archivo'] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null)) {
      $this->jsonFail('Adjunta un archivo.');
    }
    $item = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['item'] ?? ''))) ?? '';
    $title = mb_substr(trim(strip_tags(wp_unslash((string) ($_POST['titulo'] ?? '')))), 0, 120, 'UTF-8');
    try {
      $document = (new ClosingSheetDocuments($this->db))->store($scope, $item, (int) ($_POST['persona'] ?? 0), $title, $file, $this->closing_sheet_sender());
    } catch (\Throwable $error) {
      error_log('[closing_documents_upload] ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo guardar el documento. Intenta nuevamente.');
    }
    $this->jsonOk(['document' => $document, 'html' => ClosingSheetDocumentsView::chip($document, true)]);
  }

  /** Documentos de la hoja o del contrato: eliminar (queda quién y cuándo). */
  public function ajax_handler_closing_documents_delete(): void
  {
    $this->verifyCsrf();
    $documents = new ClosingSheetDocuments($this->db);
    $document = $documents->find((int) ($_POST['id'] ?? 0));
    if ($document === [] || $document['deleted_at'] > 0) {
      $this->jsonFail('Ese documento ya no está en la carpeta.');
    }
    $allowed = $this->canManageContractDocuments()
      || ($document['closing_id'] > 0 && $this->canOpenClosingSheet(false))
      || ($document['closing_id'] === 0 && $document['contract_id'] === 0 && $this->canOpenClosingSheet(true));
    if (!$allowed) {
      $this->jsonFail('Tu cargo no tiene permiso para eliminar este documento.');
    }
    try {
      $documents->delete($document['id'], $this->closing_sheet_sender());
    } catch (\Throwable $error) {
      error_log('[closing_documents_delete] #' . $document['id'] . ': ' . $error->getMessage());
      $this->jsonFail($error instanceof \DomainException ? $error->getMessage() : 'No se pudo eliminar el documento. Intenta nuevamente.');
    }
    $this->jsonOk(['id' => $document['id'], 'message' => '«' . $document['name'] . '» se eliminó de la carpeta.']);
  }

  /** Actividades administrativas › Hojas de cierre: pestañas por estado y tabla. */
  public function ajax_handler_closing_sheet_list(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('hojas_cierre')) {
      $this->jsonFail('No tienes permiso para ver las hojas de cierre.');
    }
    $state = (string) wp_unslash((string) ($_POST['estado'] ?? ''));
    $query = mb_substr(trim(strip_tags(wp_unslash((string) ($_POST['q'] ?? '')))), 0, 120, 'UTF-8');
    try {
      $workflow = new ClosingSheetWorkflow($this->db);
      if (!in_array($state, ClosingSheetWorkflow::LIST_STATES, true)) {
        // Al abrir: el primer estado con hojas pendientes (por responder, por revisar, no aprobadas).
        $counts = $workflow->listing(ClosingSheetWorkflow::STATUS_REVIEWED, '')['counts'];
        $state = ClosingSheetWorkflow::STATUS_REVIEWED;
        foreach ([ClosingSheetWorkflow::STATUS_REVIEWED, ClosingSheetService::STATUS_NEW, ClosingSheetWorkflow::REJECTED] as $pending) {
          if (($counts[$pending] ?? 0) > 0) {
            $state = $pending;
            break;
          }
        }
      }
      $listing = $workflow->listing($state, $query);
    } catch (\Throwable $error) {
      error_log('[closing_sheet_list] ' . $error->getMessage());
      $this->jsonFail('No se pudieron cargar las hojas de cierre.');
    }
    $this->jsonOk(['html' => ClosingSheetPanelView::content($listing['counts'], $listing['rows'], $state, $query, [
      'review' => $this->canUseClosingSheetAction('closing_sheet_review'),
      'respond' => $this->canUseClosingSheetAction('closing_sheet_respond'),
    ])]);
  }

  /** Funcionario que guarda: su id_empleado real (autor de los registros), nombre, correo y sucursal. @return array{employee_id:string,name:string,email:string,branch:string} */
  private function closing_sheet_sender(): array
  {
    $employeeId = trim((string) $this->current_employee_id());
    $sender = ['employee_id' => $employeeId !== '' ? $employeeId : (string) Auth::userId(), 'name' => trim(Auth::user()), 'email' => '', 'branch' => ''];
    $table = $this->db->table('jet_cct_funcionarios');
    if (Auth::userId() > 0 && $this->table_exists($table)) {
      $row = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [Auth::userId()]);
      if (is_array($row)) {
        $sender['name'] = trim((string) ($row['nombre'] ?? '')) ?: $sender['name'];
        $sender['email'] = trim((string) ($row['correo'] ?? ''));
        $sender['branch'] = trim((string) ($row['id_sucursal'] ?? ''));
      }
    }
    return $sender;
  }
}
