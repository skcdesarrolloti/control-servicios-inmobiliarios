<?php
declare(strict_types=1);

namespace SCM\Support;

/** Renders the same quotation HTML/CSS used by the dashboard and public view. */
final class HtmlPdfRenderer
{
  public static function render(string $content, string $title, array $options = []): ?string
  {
    $fallback = static fn(): ?string => ($options['allow_fallback'] ?? true) ? HtmlPdfFallback::render($content, $title) : null;
    $browser = self::browserPath();
    $remoteUrl = trim((string) getenv('SCM_GOTENBERG_URL'));
    $cssPaths = $options['stylesheets'] ?? [dirname(__DIR__, 2) . '/public/assets/css/admin/04-dashboard-pending.css', dirname(__DIR__, 2) . '/public/assets/css/quote-print.css'];
    if (($browser === null && $remoteUrl === '') || array_filter($cssPaths, static fn(string $path): bool => !is_readable($path))) {
      return $fallback();
    }

    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/scm-quote-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
      return $fallback();
    }
    $htmlPath = $directory . '/quote.html';
    $pdfPath = $directory . '/quote.pdf';
    $base = htmlspecialchars(rtrim((string) SCM_BASE_URL, '/') . '/', ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $css = implode("\n", array_map(static fn(string $path): string => (string) file_get_contents($path), $cssPaths));
    // The hosted Chromium may have no system fonts. Send both body and signature
    // fonts with the document rather than relying on Arial or remote font requests.
    foreach (['noto-sans.ttf', 'caveat.ttf'] as $fontName) {
      $reference = '../fonts/' . $fontName;
      if (!str_contains($css, $reference)) continue;
      $fontPath = dirname(__DIR__, 2) . '/public/assets/fonts/' . $fontName;
      if (!is_readable($fontPath)) throw new \RuntimeException('Falta una fuente requerida para el PDF: ' . $fontName);
      $css = str_replace($reference, 'data:font/ttf;base64,' . base64_encode((string) file_get_contents($fontPath)), $css);
    }
    $bodyClass = htmlspecialchars((string) ($options['body_class'] ?? ''), ENT_QUOTES, 'UTF-8');
    $wrapperClass = htmlspecialchars((string) ($options['wrapper_class'] ?? 'scm-cotizacion-native-print-root'), ENT_QUOTES, 'UTF-8');
    $containerClass = htmlspecialchars((string) ($options['container_class'] ?? 'scm-cotizacion-native-modal'), ENT_QUOTES, 'UTF-8');
    $content = self::inlineConfiguredLogo($content);
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><base href="' . $base . '"><title>' . $safeTitle . '</title>'
      . (($options['external_fonts'] ?? true) ? '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">' : '')
      . '<style>' . $css . '</style></head><body class="' . $bodyClass . '"><main class="' . $containerClass . '"><div class="' . $wrapperClass . '">' . $content . '</div></main></body></html>';

    try {
      if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
        return $fallback();
      }
      if ($remoteUrl !== '') {
        $remotePdf = self::renderWithGotenberg($htmlPath, $remoteUrl);
        if ($remotePdf !== null) {
          return $remotePdf;
        }
      }
      if ($browser === null || !function_exists('proc_open')) {
        return $fallback();
      }
      $command = [
        $browser, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run',
        '--disable-extensions', '--allow-file-access-from-files', '--no-pdf-header-footer',
        '--virtual-time-budget=10000', '--user-data-dir=' . $directory . '/profile',
        '--print-to-pdf=' . $pdfPath, 'file:///' . str_replace('\\', '/', ltrim($htmlPath, '/')),
      ];
      $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      if (!is_resource($process)) {
        return $fallback();
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
      return is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : $fallback();
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

  private static function renderWithGotenberg(string $htmlPath, string $baseUrl): ?string
  {
    if (!str_starts_with($baseUrl, 'https://') || !function_exists('curl_init')) {
      return null;
    }
    $url = rtrim($baseUrl, '/') . '/forms/chromium/convert/html';
    $curl = curl_init($url);
    if ($curl === false) {
      return null;
    }
    $username = trim((string) getenv('SCM_GOTENBERG_USERNAME'));
    $password = (string) getenv('SCM_GOTENBERG_PASSWORD');
    $options = [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => [
        'files' => new \CURLFile($htmlPath, 'text/html', 'index.html'),
        'preferCssPageSize' => 'true',
        'printBackground' => 'true',
      ],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 55,
      CURLOPT_HTTPHEADER => ['Accept: application/pdf'],
    ];
    if ($username !== '' && $password !== '') {
      $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
      $options[CURLOPT_USERPWD] = $username . ':' . $password;
    }
    curl_setopt_array($curl, $options);
    $bytes = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return $status === 200 && is_string($bytes) && str_starts_with($bytes, '%PDF-') ? $bytes : null;
  }

  private static function inlineConfiguredLogo(string $content): string
  {
    return (string) preg_replace_callback(
      '/(<(?:div|span) class="(?:scm-cotizacion-native-logo|scm-order-invoice-brand|scm-acta-logo)"[^>]*><img src=")([^"]+)(")/',
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
