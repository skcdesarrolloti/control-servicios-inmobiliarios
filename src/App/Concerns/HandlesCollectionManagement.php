<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Modules\CollectionManagement\CollectionPortfolioService;

trait HandlesCollectionManagement
{
  public function ajax_handler_collection_portfolio_import(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para importar la cartera.');
    }
    try {
      $result = $this->get_collection_portfolio_service()->import((array) ($_FILES['file'] ?? []));
      $duplicate = !empty($result['duplicate']);
      $this->jsonOk($result + [
        'message' => $duplicate
          ? 'Este auxiliar ya había sido procesado. La cartera no se duplicó.'
          : 'Auxiliar procesado. La cartera y los pagos detectados quedaron actualizados.',
      ]);
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_collection_portfolio_action(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para actualizar la cartera.');
    }
    $portfolioId = max(0, (int) ($_POST['portfolio_id'] ?? 0));
    $operation = sanitize_key((string) ($_POST['operation'] ?? ''));
    $note = trim(wp_strip_all_tags((string) ($_POST['note'] ?? '')));
    if ($portfolioId <= 0) {
      $this->jsonFail('Selecciona un registro de cartera válido.');
    }
    try {
      $service = $this->get_collection_portfolio_service();
      if (in_array($operation, ['mark_normal', 'mark_prejuridico', 'mark_siniestro'], true)) {
        $stage = str_replace('mark_', '', $operation);
        $item = $service->updateStage($portfolioId, $stage, $note);
        $this->jsonOk(['item' => $item, 'message' => 'Estado de cobranza actualizado.']);
      }
      if (in_array($operation, ['send_prejuridico', 'send_siniestro'], true)) {
        $type = str_replace('send_', '', $operation);
        $result = $service->generateLetter($portfolioId, $type, true);
        $queued = (int) ($result['queued'] ?? 0);
        $this->jsonOk($result + [
          'message' => $queued > 0
            ? 'Carta generada y ' . $queued . ' correo(s) encolado(s).'
            : 'La carta se generó, pero no se encontró un correo válido para encolarla.',
        ]);
      }
      $this->jsonFail('Operación de cartera no válida.');
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  public function ajax_handler_collection_portfolio_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestiones_cobro')) {
      $this->jsonFail('No tienes permiso para generar cartas de cartera.');
    }
    $portfolioId = max(0, (int) ($_POST['portfolio_id'] ?? 0));
    $letterType = sanitize_key((string) ($_POST['letter_type'] ?? ''));
    $preview = sanitize_key((string) ($_POST['mode'] ?? '')) === 'preview';
    try {
      $portfolioService = $this->get_collection_portfolio_service();
      $document = $preview
        ? $portfolioService->previewLetter($portfolioId, $letterType)
        : $portfolioService->generateLetter($portfolioId, $letterType, false);
      $path = (string) ($document['path'] ?? '');
      if ($path === '' || !is_file($path)) {
        throw new \RuntimeException('No se pudo preparar la carta en PDF.');
      }
      if (ob_get_level() > 0) {
        ob_end_clean();
      }
      header_remove('Content-Type');
      header('Content-Type: application/pdf');
      header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . addslashes((string) ($document['filename'] ?? 'carta-cartera.pdf')) . '"');
      header('Content-Length: ' . (string) filesize($path));
      header('Cache-Control: private, max-age=0, must-revalidate');
      readfile($path);
      if ($preview) {
        @unlink($path);
      }
      exit;
    } catch (\Throwable $exception) {
      $this->jsonFail($exception->getMessage());
    }
  }

  private function get_collection_portfolio_service(): CollectionPortfolioService
  {
    return new CollectionPortfolioService($this->db);
  }
}
