<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\ContractHistory\ContractNoteService;
use SCM\Support\StoredFileService;

/** "+ Añadir nota al contrato": guarda gestiones en el historial del contrato de arrendamiento. */
trait HandlesContractNotes
{
  private function contractNoteService(): ContractNoteService
  {
    $this->verifyCsrf();
    // Mismo acceso que crear casos desde el panel, que comparte la búsqueda de contratos.
    if (!Auth::isLoggedIn() || !$this->canAccessDashboardTab('metricas')) {
      $this->jsonFail('No tienes permiso para añadir notas a contratos.');
    }
    return new ContractNoteService($this->db);
  }

  public function ajax_handler_contract_note_options(): void
  {
    $this->jsonOk($this->contractNoteService()->options());
  }

  public function ajax_handler_contract_note_history(): void
  {
    $this->jsonOk(['notes' => $this->contractNoteService()->recent((int) ($_POST['contract_id'] ?? 0))]);
  }

  public function ajax_handler_contract_note_create(): void
  {
    $service = $this->contractNoteService();
    $requestId = (string) ($_POST['request_id'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/D', $requestId)) {
      $this->jsonFail('Identificador de solicitud inválido. Abre nuevamente el formulario.');
    }
    // Un recibo por solicitud: un doble clic o un reintento de red devuelve la misma nota.
    $directory = SCM_STORAGE_PATH . '/data/contract-note-receipts';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
      $this->jsonFail('No se pudo iniciar el registro de la nota.');
    }
    $receipt = fopen($directory . '/' . hash('sha256', Auth::employeeId() . ':' . $requestId) . '.json', 'c+');
    if (!$receipt || !flock($receipt, LOCK_EX)) {
      $this->jsonFail('No se pudo bloquear la solicitud. Intenta nuevamente.');
    }
    $stored = ['images' => [], 'documents' => []];
    try {
      $saved = json_decode((string) stream_get_contents($receipt), true);
      if (is_array($saved) && !empty($saved['id'])) {
        $result = $saved;
      } else {
        $employeeId = $this->current_employee_id();
        $expected = $service->validateFiles($_FILES);
        $files = StoredFileService::fromRuntime();
        $stored = ContractNoteService::storeFiles($files);
        if (count($stored['images']) !== $expected['images'] || count($stored['documents']) !== $expected['documents']) {
          throw new \RuntimeException('No se pudieron guardar todos los adjuntos. Intenta nuevamente.');
        }
        $note = $service->create($_POST, ['employee_id' => $employeeId, 'name' => Auth::user()], $stored);
        $result = $note + ['message' => 'Nota registrada en el historial del contrato ' . $note['contrato'] . ' y del inmueble.'];
        rewind($receipt);
        ftruncate($receipt, 0);
        fwrite($receipt, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        fflush($receipt);
      }
    } catch (\InvalidArgumentException | \DomainException $e) {
      $error = $e->getMessage();
    } catch (\Throwable $e) {
      error_log('[contract_note_create] ' . $e->getMessage());
      $error = $e instanceof \RuntimeException && !$e instanceof \PDOException ? $e->getMessage() : 'No se pudo guardar la nota. Intenta nuevamente.';
    } finally {
      flock($receipt, LOCK_UN);
      fclose($receipt);
    }
    if (isset($error)) {
      $this->removeStoredContractNoteFiles($stored);
      $this->jsonFail($error);
    }
    $this->jsonOk($result);
  }

  /** @param array{images:list<string>,documents:list<string>} $stored */
  private function removeStoredContractNoteFiles(array $stored): void
  {
    $files = StoredFileService::fromRuntime();
    foreach (array_merge($stored['images'], $stored['documents']) as $url) {
      parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
      $path = $files->pathFor((string) ($query['n'] ?? ''));
      if ($path !== null) {
        @unlink($path);
      }
    }
  }
}
