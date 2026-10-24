<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\CanonInsuranceAudit\SpreadsheetReader;
use SCM\Modules\Tools\LateFeeCalculator;
use SCM\Modules\Tools\PseArchive;
use SCM\Modules\Tools\PsePaymentFile;
use SCM\Modules\Tools\SuperfinRateService;
use SCM\Modules\Tools\ToolsView;

/** Herramientas del encabezado: Generar PSE y Calculadora de mora. */
trait HandlesTools
{
  /** Formulario de Generar PSE prellenado con la última generación llevada al mes actual. */
  private function toolsPseFormValues(): array
  {
    $last = App::settings()->get('tools_pse_last_params', []);
    return PsePaymentFile::suggested(is_array($last) ? $last : [], new \DateTimeImmutable('today', new \DateTimeZone('America/Bogota')));
  }

  public function ajax_handler_tools_pse_generate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_pse')) $this->jsonFail('No tienes permiso para generar archivos PSE.');
    $file = is_array($_FILES['archivo'] ?? null) ? $_FILES['archivo'] : [];
    try {
      $tmp = (string) ($file['tmp_name'] ?? '');
      if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new \InvalidArgumentException('Selecciona el Excel de facturación (.xls o .xlsx).');
      }
      $period = static fn(int $number): array => [
        'pay1' => sanitize_text_field(wp_unslash((string) ($_POST['p' . $number . '_pay1'] ?? ''))),
        'pay2' => sanitize_text_field(wp_unslash((string) ($_POST['p' . $number . '_pay2'] ?? ''))),
        'pct1' => sanitize_text_field(wp_unslash((string) ($_POST['p' . $number . '_pct1'] ?? '0'))),
        'pct2' => sanitize_text_field(wp_unslash((string) ($_POST['p' . $number . '_pct2'] ?? '0'))),
        'published' => sanitize_text_field(wp_unslash((string) ($_POST['p' . $number . '_published'] ?? ''))),
      ];
      $rows = (new SpreadsheetReader())->read($tmp, (string) ($file['name'] ?? ''));
      $result = PsePaymentFile::build($rows, ['first' => $period(1), 'second' => $period(2)]);
      $archive = PseArchive::default();
      $actor = $this->ticketCompletionActor();
      foreach ($result['files'] as $generated) {
        $archive->save($generated['name'], $generated['content'], [
          'actor_id' => (string) ($actor['employee_id'] ?? ''),
          'actor_name' => (string) ($actor['name'] ?? ''),
          'records' => $result['records'],
        ]);
      }
      $last = [];
      foreach ([1, 2] as $number) {
        foreach (['pay1', 'pay2', 'published', 'pct1', 'pct2'] as $field) {
          $value = $period($number)[$field];
          $last['p' . $number . '_' . $field] = in_array($field, ['pct1', 'pct2'], true) ? $value : (\DateTimeImmutable::createFromFormat('!Ymd', PsePaymentFile::ymd($value)) ?: new \DateTimeImmutable())->format('Y-m-d');
        }
      }
      App::settings()->set('tools_pse_last_params', $last, Auth::userId());
      $view = new ToolsView();
      $this->jsonOk([
        'result_html' => $view->pseResult($result),
        'archive_html' => $view->pseArchive($archive->files()),
        'message' => 'Archivos PSE generados: ' . implode(' y ', array_column($result['files'], 'name')) . '.',
      ]);
    } catch (\InvalidArgumentException | \RuntimeException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[tools-pse] ' . $error->getMessage());
      $this->jsonFail('No se pudieron generar los archivos PSE.');
    }
  }

  public function ajax_handler_tools_pse_archive(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_pse')) $this->jsonFail('No tienes permiso para ver el archivador PSE.');
    $this->jsonOk(['html' => (new ToolsView())->pseArchive(PseArchive::default()->files())]);
  }

  public function ajax_handler_tools_pse_download(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_pse')) $this->jsonFail('No tienes permiso para descargar archivos PSE.');
    $name = basename(sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))));
    try {
      $content = PseArchive::default()->read($name);
    } catch (\Throwable $error) {
      $this->jsonFail($error->getMessage());
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: text/plain; charset=us-ascii');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) strlen($content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $content;
    exit;
  }

  public function ajax_handler_tools_mora_rate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mora')) $this->jsonFail('No tienes permiso para usar la calculadora de mora.');
    try {
      $this->jsonOk(['rate' => SuperfinRateService::default()->getLatest(!empty($_POST['refresh']))]);
    } catch (\Throwable $error) {
      $this->jsonFail($error->getMessage());
    }
  }

  public function ajax_handler_tools_mora_calculate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mora')) $this->jsonFail('No tienes permiso para usar la calculadora de mora.');
    $input = json_decode(wp_unslash((string) ($_POST['payload'] ?? '')), true);
    if (!is_array($input)) $this->jsonFail('No se recibieron datos para calcular.');
    try {
      $this->jsonOk(['calculation' => LateFeeCalculator::run($input)]);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[tools-mora] ' . $error->getMessage());
      $this->jsonFail('No fue posible calcular.');
    }
  }
}
