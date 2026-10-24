<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\App;
use SCM\Core\Auth;
use SCM\Modules\CanonInsuranceAudit\SpreadsheetReader;
use SCM\Modules\Tools\CleaningCalculator;
use SCM\Modules\Tools\CleaningQuotePdf;
use SCM\Modules\Tools\CleaningSettings;
use SCM\Modules\Tools\LateFeeCalculator;
use SCM\Modules\Tools\MarketStatsService;
use SCM\Modules\Tools\PseArchive;
use SCM\Modules\Tools\PsePaymentFile;
use SCM\Modules\Tools\SuperfinRateService;
use SCM\Modules\Tools\ToolsView;

/** Herramientas del encabezado: Generar PSE, Calculadora de mora, Calculadora de mercado y Calculadora de aseo. */
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
    $content = '';
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

  /** Estadística completa y tabla de inmuebles (antes "vista de administrador" del shortcode). */
  private function canSeeMarketStatsAdmin(): bool
  {
    return $this->canUseDashboardAction('market_stats_admin') || $this->canManageDashboardPermissions();
  }

  public function ajax_handler_tools_market_options(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mercado')) $this->jsonFail('No tienes permiso para usar la calculadora de mercado.');
    try {
      $this->jsonOk(['options' => (new MarketStatsService($this->db))->options(), 'admin' => $this->canSeeMarketStatsAdmin()]);
    } catch (\Throwable $error) {
      error_log('[tools-market] ' . $error->getMessage());
      $this->jsonFail('No se pudieron cargar los filtros de la calculadora de mercado.');
    }
  }

  public function ajax_handler_tools_market_analyze(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mercado')) $this->jsonFail('No tienes permiso para usar la calculadora de mercado.');
    $text = static fn(string $key): string => trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
    $filters = ['estados' => array_map(static fn($value): string => trim(sanitize_text_field(wp_unslash((string) $value))), (array) ($_POST['estados'] ?? []))];
    foreach (['ruta', 'barrio', 'estrato', 'tipo_inmueble', 'categoria', 'destinacion', 'habitaciones', 'edad', 'parqueadero', 'deposito', 'vigilancia'] as $key) {
      $filters[$key] = $text($key);
    }
    if ($filters['estados'] === []) $this->jsonFail('Selecciona al menos un estado del inmueble.');
    $result = ['venta' => [], 'arriendo' => [], 'items' => [], 'without_area' => 0];
    try {
      $result = (new MarketStatsService($this->db))->analyze($filters);
    } catch (\Throwable $error) {
      error_log('[tools-market] ' . $error->getMessage());
      $this->jsonFail('No se pudo calcular la estadística de mercado.');
    }
    $admin = $this->canSeeMarketStatsAdmin();
    $round = static fn(array $values): array => array_map(static fn(float $value): float => round($value, 2), $values);
    $this->jsonOk([
      'admin' => $admin,
      'venta' => $round($result['venta']),
      'arriendo' => $round($result['arriendo']),
      'without_area' => $result['without_area'],
      // El listado de inmuebles (códigos y barrios) solo viaja a quien tiene la vista completa.
      'items' => $admin ? array_map(static fn(array $item): array => ['precio' => round($item['precio'], 2), 'area' => round($item['area'], 2), 'm2' => round($item['m2'], 2)] + $item, $result['items']) : [],
    ]);
  }

  /** Ficha del inmueble del panel (la misma de Contratos de arrendamiento) para la tabla de control. */
  public function ajax_handler_tools_market_property(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mercado') || !$this->canSeeMarketStatsAdmin()) $this->jsonFail('No tienes permiso para ver la ficha del inmueble.');
    $property = (new \SCM\Modules\LeaseContracts\LeaseAnnexesService($this->db))->propertyById((int) ($_POST['property_id'] ?? 0));
    if ($property === []) $this->jsonFail('No se encontró el inmueble.');
    $code = trim((string) ($property['codigo'] ?? ''));
    $this->jsonOk([
      'title' => 'Inmueble ' . ($code !== '' ? '#' . $code : '_ID ' . (int) $property['_ID']) . ' · ' . trim((string) ($property['direccion'] ?? $property['barrio'] ?? '')),
      'html' => (new \SCM\Modules\LeaseContracts\LeaseContractsView())->renderProperty([], $property),
    ]);
  }

  /** Membrete institucional (el de las actas) para los PDF que se arman en el navegador. */
  public function ajax_handler_tools_letterhead(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_mora')) $this->jsonFail('No tienes permiso para usar la calculadora de mora.');
    $path = dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa-header.jpg';
    $size = @getimagesize($path);
    if (!is_file($path) || $size === false) $this->jsonFail('No se encontró el membrete.');
    $this->jsonOk(['header' => 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path)), 'width' => (int) $size[0], 'height' => (int) $size[1]]);
  }

  /** Costos, márgenes, utilidades y los PDF internos de la calculadora de aseo. */
  private function canSeeCleaningInternal(): bool
  {
    return $this->canUseDashboardAction('cleaning_internal_view') || $this->canManageDashboardPermissions();
  }

  /** @return array<string,string> */
  private function cleaningInput(): array
  {
    $input = [];
    foreach (['m2', 'jornada', 'distancia', 'dificultad', 'trabajador', 'cliente', 'direccion'] as $key) {
      $input[$key] = trim(sanitize_text_field(wp_unslash((string) ($_POST[$key] ?? ''))));
    }
    return $input;
  }

  public function ajax_handler_tools_cleaning_calculate(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_aseo')) $this->jsonFail('No tienes permiso para usar la calculadora de aseo.');
    $result = [];
    try {
      $result = CleaningCalculator::calculate((new CleaningSettings($this->db))->read(), $this->cleaningInput());
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[tools-cleaning] ' . $error->getMessage());
      $this->jsonFail('No se pudo calcular el servicio de aseo.');
    }
    $internal = $this->canSeeCleaningInternal();
    $public = ['input', 'horas', 'protocolo', 'base', 'iva', 'total', 'cliente'];
    // Costos, márgenes y utilidades solo viajan a quien tiene la vista interna.
    $this->jsonOk(['internal' => $internal, 'result' => $internal ? $result : array_intersect_key($result, array_flip($public))]);
  }

  public function ajax_handler_tools_cleaning_pdf(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('herramienta_aseo')) $this->jsonFail('No tienes permiso para usar la calculadora de aseo.');
    $mode = sanitize_key((string) ($_POST['mode'] ?? 'cliente'));
    if (!isset(CleaningQuotePdf::MODES[$mode])) $this->jsonFail('Tipo de PDF no válido.');
    if (in_array($mode, CleaningQuotePdf::INTERNAL_MODES, true) && !$this->canSeeCleaningInternal()) $this->jsonFail('Tu cargo no tiene permiso para los reportes internos de aseo.');
    $bytes = '';
    try {
      $config = (new CleaningSettings($this->db))->read();
      $input = $this->cleaningInput();
      $result = CleaningCalculator::calculate($config, $input);
      $bytes = CleaningQuotePdf::bytes($mode, $result, $config, ['cliente' => $input['cliente'], 'direccion' => $input['direccion']], (string) ($this->ticketCompletionActor()['name'] ?? ''));
    } catch (\InvalidArgumentException | \DomainException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[tools-cleaning-pdf] ' . $error->getMessage());
      $this->jsonFail('No se pudo generar el PDF.');
    }
    if (ob_get_level() > 0) ob_end_clean();
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="aseo-' . $mode . '-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  public function ajax_handler_tools_cleaning_settings(): void
  {
    $this->verifyCsrf();
    $this->requireDashboardAction('cleaning_configure', 'Tu cargo no tiene permiso para configurar la calculadora de aseo.');
    $settings = new CleaningSettings($this->db);
    $view = new ToolsView();
    try {
      if (($_POST['mode'] ?? '') === 'save') {
        $input = json_decode(wp_unslash((string) ($_POST['settings'] ?? '')), true);
        if (!is_array($input)) throw new \InvalidArgumentException('No se recibió la configuración.');
        $saved = $settings->save($input, (string) ($this->ticketCompletionActor()['employee_id'] ?? ''));
        $this->jsonOk(['html' => $view->cleaningSettingsForm($saved), 'message' => 'Configuración de aseo guardada. Recarga la calculadora para usar los nuevos valores.']);
      }
      $this->jsonOk(['html' => $view->cleaningSettingsForm($settings->read())]);
    } catch (\InvalidArgumentException $error) {
      $this->jsonFail($error->getMessage());
    } catch (\Throwable $error) {
      error_log('[tools-cleaning-settings] ' . $error->getMessage());
      $this->jsonFail('No se pudo guardar la configuración de aseo.');
    }
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
