<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\ClosingSheets\ClosingSheetFlowView;
use SCM\Modules\ClosingSheets\ClosingSheetPanelView;
use SCM\Modules\ClosingSheets\ClosingSheetService;
use SCM\Modules\ClosingSheets\ClosingSheetSettings;
use SCM\Modules\ClosingSheets\ClosingSheetWorkflow;

/** Hoja de cierre de negocio (hoja-cierre.php): guardar al crear o editar, y su configuración de avisos. */
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

  /** Configuración › Hoja de cierre: avisos al propietario y WhatsApp a funcionarios. */
  public function ajax_handler_closing_sheet_settings(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('closing_sheet_configure', 'Tu cargo no tiene permiso para configurar la hoja de cierre.');
    $settings = new ClosingSheetSettings($this->db);
    if (($_POST['mode'] ?? '') === 'save') {
      try {
        $saved = $settings->save(is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [], Auth::userId());
      } catch (\Throwable $error) {
        error_log('[closing_sheet_settings] ' . $error->getMessage());
        $this->jsonFail('No se pudo guardar la configuración de la hoja de cierre.');
      }
      $this->jsonOk(['html' => ClosingSheetFlowView::settingsForm($saved), 'message' => 'Configuración guardada.']);
    }
    $this->jsonOk(['html' => ClosingSheetFlowView::settingsForm($settings->read())]);
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
