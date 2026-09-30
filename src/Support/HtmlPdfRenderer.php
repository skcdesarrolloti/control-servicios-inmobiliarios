<?php
declare(strict_types=1);

namespace SCM\Support;

/** Renders the same quotation HTML/CSS used by the dashboard and public view. */
final class HtmlPdfRenderer
{
  public static function render(string $content, string $title): ?string
  {
    $browser = self::browserPath();
    $cssPath = dirname(__DIR__, 2) . '/public/assets/css/admin/04-dashboard-pending.css';
    if ($browser === null || !is_readable($cssPath) || !function_exists('proc_open')) {
      return null;
    }

    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/scm-quote-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
      return null;
    }
    $htmlPath = $directory . '/quote.html';
    $pdfPath = $directory . '/quote.pdf';
    $base = htmlspecialchars(rtrim((string) SCM_BASE_URL, '/') . '/', ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $css = (string) file_get_contents($cssPath);
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><base href="' . $base . '"><title>' . $safeTitle . '</title>'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">'
      . '<style>' . $css . '</style><style>@page{size:A4;margin:10mm}'
      . 'html,body{margin:0;background:#fff!important;font-family:Poppins,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      . '.scm-cotizacion-native-print-root{max-width:980px;margin:0 auto;padding:0;background:#fff}'
      . '.scm-cotizacion-native-doc{box-shadow:none!important;margin:0 auto}'
      . '.scm-cotizacion-native-section,.scm-cotizacion-damage-card,.scm-cotizacion-budget-block,.scm-cotizacion-table-wrap,.scm-cotizacion-native-footer{break-inside:avoid-page}'
      . '</style></head><body><main class="scm-cotizacion-native-print-root">' . $content . '</main></body></html>';

    try {
      if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
        return null;
      }
      $command = [
        $browser, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run',
        '--disable-extensions', '--allow-file-access-from-files', '--no-pdf-header-footer',
        '--virtual-time-budget=10000', '--user-data-dir=' . $directory . '/profile',
        '--print-to-pdf=' . $pdfPath, 'file:///' . str_replace('\\', '/', ltrim($htmlPath, '/')),
      ];
      $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (!is_resource($process)) {
        return null;
      }
      fclose($pipes[0]);
      stream_set_blocking($pipes[1], false);
      stream_set_blocking($pipes[2], false);
      $deadline = microtime(true) + 35;
      do {
        $status = proc_get_status($process);
        if (!$status['running']) {
          break;
        }
        usleep(100000);
      } while (microtime(true) < $deadline);
      if ($status['running']) {
        proc_terminate($process);
      }
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      proc_close($process);
      $bytes = is_file($pdfPath) ? file_get_contents($pdfPath) : false;
      return is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : null;
    } finally {
      if (is_dir($directory . '/profile')) {
        $files = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($directory . '/profile', \FilesystemIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
          $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
      }
      @unlink($htmlPath);
      @unlink($pdfPath);
      @rmdir($directory . '/profile');
      @rmdir($directory);
    }
  }

  private static function browserPath(): ?string
  {
    $configured = trim((string) getenv('SCM_CHROMIUM_BIN'));
    $paths = array_filter([
      $configured,
      'C:/Program Files/Google/Chrome/Application/chrome.exe',
      'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
      '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    ]);
    foreach ($paths as $path) {
      if (is_file($path) && is_executable($path)) {
        return $path;
      }
    }
    return null;
  }
}
