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
    $content = self::inlineConfiguredLogo($content);
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><base href="' . $base . '"><title>' . $safeTitle . '</title>'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">'
      . '<style>' . $css . '</style><style>@page{size:A4;margin:10mm}'
      . 'html,body{margin:0;background:#fff!important;font-family:Poppins,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      . '.scm-cotizacion-native-print-root{width:100%;max-width:980px;margin:0 auto;padding:0;background:#fff}'
      . '.scm-cotizacion-native-doc{box-shadow:none!important;margin:0 auto}'
      . '.scm-cotizacion-native-brand,.scm-cotizacion-native-hero-bottom{display:flex!important}'
      . '.scm-cotizacion-native-summary{grid-template-columns:repeat(4,minmax(0,1fr))!important}'
      . '.scm-cotizacion-native-two-col{grid-template-columns:repeat(2,minmax(0,1fr))!important}'
      . '.scm-cotizacion-damage-head{grid-template-columns:repeat(4,minmax(0,1fr))!important}'
      . '.scm-cotizacion-native-footer{grid-template-columns:repeat(3,minmax(0,1fr))!important}'
      . '.scm-cotizacion-native-number,.scm-cotizacion-native-state{text-align:right!important}'
      . '.scm-cotizacion-native-doc,.scm-cotizacion-damage-card,.scm-cotizacion-budget-block,.scm-cotizacion-table-wrap{overflow:visible!important}'
      . '.scm-cotizacion-native-section,.scm-cotizacion-damage-card,.scm-cotizacion-budget-block,.scm-cotizacion-table-wrap{break-inside:auto!important}'
      . '.scm-cotizacion-native-summary>div,.scm-cotizacion-budget-table tr,.scm-cotizacion-media-item,.scm-cotizacion-native-footer>div{break-inside:avoid-page}'
      . '</style></head><body><main class="scm-cotizacion-native-modal"><div class="scm-cotizacion-native-print-root">' . $content . '</div></main></body></html>';

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

  private static function inlineConfiguredLogo(string $content): string
  {
    return (string) preg_replace_callback(
      '/(<div class="scm-cotizacion-native-logo"><img src=")([^"]+)(")/',
      static function (array $match): string {
        $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!str_starts_with($url, 'https://')) {
          return $match[0];
        }
        $context = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
          return $match[0];
        }
        $bytes = stream_get_contents($stream, 2000001);
        fclose($stream);
        if (!is_string($bytes) || strlen($bytes) > 2000000) {
          return $match[0];
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
          return $match[0];
        }
        return $match[1] . 'data:' . $mime . ';base64,' . base64_encode($bytes) . $match[3];
      },
      $content,
      1
    );
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
