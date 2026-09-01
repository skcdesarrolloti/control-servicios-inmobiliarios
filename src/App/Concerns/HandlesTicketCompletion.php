<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\TicketCompletion\CompletionRepository;
use SCM\Modules\TicketCompletion\CompletionService;
use SCM\Modules\TicketCompletion\CompletionView;

trait HandlesTicketCompletion
{
  public function canAccessTicketCompletion(int $ticketId): bool
  {
    if (!Auth::isLoggedIn() || $ticketId <= 0) {
      return false;
    }
    $ticket = (new CompletionRepository($this->db))->ticket($ticketId);
    if ($this->canAccessDashboardTab('abiertos') || $this->canAccessDashboardTab('actas_satisfaccion')) {
      return true;
    }
    $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
    return $this->canAccessDashboardTab('mis_tickets') && $employee
      && trim((string) $employee['id_empleado']) !== ''
      && trim((string) $ticket['id_empleado']) === trim((string) $employee['id_empleado']);
  }

  public function ticketCompletionDashboardTab(int $ticketId): string
  {
    if (!$this->canAccessTicketCompletion($ticketId)) { return ''; }
    return $this->canAccessDashboardTab('abiertos') ? 'abiertos' : 'mis_tickets';
  }

  public function ajax_handler_ticket_completion(): void
  {
    $this->verifyCsrf();
    $ticketId = (int) ($_POST['ticket_pk'] ?? 0);
    try {
      if (!$this->canAccessTicketCompletion($ticketId)) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para gestionar el acta de este ticket.');
      }
      $repo = new CompletionRepository($this->db);
      $repo->requireSchema();
      $service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
      $operation = (string) ($_POST['operation'] ?? 'read');
      $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
      $actor = ['user_id' => Auth::userId(), 'employee_id' => (string) ($employee['id_empleado'] ?? Auth::userId()), 'name' => Auth::user()];
      $result = [];
      if ($operation === 'create') {
        $input = $_POST;
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $storedPhotos = [];
        $storedBytes = 0;
        $requestedTotal = 0;
        foreach (array_keys($items) as $index) {
          $names = $_FILES['acta_item_photos_' . $index]['name'] ?? [];
          $names = is_array($names) ? array_values(array_filter($names, static fn($name): bool => trim((string) $name) !== '')) : [];
          if (count($names) > 4) { throw new \DomainException('Cada daño admite máximo 4 fotos.'); }
          $requestedTotal += count($names);
        }
        if ($requestedTotal > 12) { throw new \DomainException('El acta admite máximo 12 fotos en total.'); }
        foreach ($items as $index => &$item) {
          if (!is_array($item)) { continue; }
          unset($item['photos']);
          if (!preg_match('/^\d+$/D', (string) $index)) { continue; }
          $field = 'acta_item_photos_' . $index;
          $names = $_FILES[$field]['name'] ?? [];
          $names = is_array($names) ? array_values(array_filter($names, static fn($name): bool => trim((string) $name) !== '')) : [];
          if (!$names) { continue; }
          $photos = $this->handleImageUploadsDetailed($field, 4);
          if (count($photos) !== count($names)) {
            $this->storedFiles()->deleteStoredImages(array_merge($storedPhotos, $photos));
            throw new \DomainException('No se pudieron procesar todas las fotos. Usa imágenes JPG, PNG o WebP de máximo ' . (int) floor(SCM_UPLOAD_MAX_BYTES / 1048576) . ' MB cada una.');
          }
          foreach ($photos as $photo) {
            if ($photo['mime'] !== 'image/jpeg' || $photo['width'] > 1600 || $photo['height'] > 1600 || $photo['bytes'] > 1500000) {
              $this->storedFiles()->deleteStoredImages(array_merge($storedPhotos, $photos));
              throw new \DomainException('Una foto no pudo comprimirse por debajo de 1,5 MB y 1600 px. Prueba con otra imagen.');
            }
            $storedBytes += (int) $photo['bytes'];
            if ($storedBytes > 8000000) {
              $this->storedFiles()->deleteStoredImages(array_merge($storedPhotos, $photos));
              throw new \DomainException('Las fotos del acta superan 8 MB después de comprimir. Retira algunas evidencias.');
            }
          }
          $storedPhotos = array_merge($storedPhotos, $photos);
          $item['photos'] = array_map(static fn(array $photo): array => [
            'name' => $photo['name'], 'mime' => $photo['mime'], 'width' => $photo['width'], 'height' => $photo['height'],
            'bytes' => $photo['bytes'], 'sha256' => $photo['sha256'],
          ], $photos);
        }
        unset($item);
        $input['items'] = $items;
        try { $result = $service->create($ticketId, $input, $actor); }
        catch (\Throwable $error) { $this->storedFiles()->deleteStoredImages($storedPhotos); throw $error; }
      } elseif (in_array($operation, ['resend', 'cancel'], true)) {
        $id = (int) ($_POST['act_id'] ?? 0);
        if ((int) $repo->act($id)['ticket_pk'] !== $ticketId) {
          throw new \DomainException('El acta no pertenece al ticket seleccionado.');
        }
        if ($operation === 'resend') {
          $result = $service->resend($id);
        } else {
          $service->cancel($id, (string) ($_POST['reason'] ?? ''), $actor);
          $result = ['message' => 'Acta anulada. Puedes generar una nueva versión; el ticket sigue abierto.'];
        }
      } elseif ($operation !== 'read') {
        throw new \DomainException('Operación de acta no válida.');
      }
      $this->jsonOk($result + ['html' => (new CompletionView())->panel($service->context($ticketId), $service)]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  public function ajax_handler_ticket_completion_list(): void
  {
    $this->verifyCsrf();
    try {
      if (!$this->canAccessDashboardTab('actas_satisfaccion')) {
        http_response_code(403);
        $this->jsonFail('No tienes permiso para consultar actas de satisfacción.');
      }
      $repo = new CompletionRepository($this->db);
      $service = new CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
      if (($_POST['operation'] ?? '') === 'archive') {
        $id = (int) ($_POST['act_id'] ?? 0);
        $employee = $this->db->getRow('SELECT id_empleado FROM `' . $this->db->table('jet_cct_funcionarios') . '` WHERE _ID = ?', [Auth::userId()]);
        $service->archive($id, (string) ($_POST['reason'] ?? 'Acta de prueba archivada desde la bandeja.'), ['user_id' => Auth::userId(), 'employee_id' => (string) ($employee['id_empleado'] ?? Auth::userId()), 'name' => Auth::user()]);
      }
      $data = $service->dashboardList($_POST);
      $view = new CompletionView();
      $this->jsonOk([
        'table_html' => $view->dashboardTable($data['items'], $service, $data['pagination']),
        'kpis_html' => $view->dashboardKpis($data['stats']),
        'count' => (string) $data['count'],
        'message' => ($_POST['operation'] ?? '') === 'archive' ? 'Acta archivada. Salió de pendientes, el ticket sigue abierto y no se generó cobro.' : '',
      ]);
    } catch (\DomainException $error) {
      $this->jsonFail($error->getMessage());
    }
  }
}
