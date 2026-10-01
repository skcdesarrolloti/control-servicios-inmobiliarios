<?php
declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\HtmlPdfRenderer;

final class CompletionPdf
{
  public function render(array $act, array $payload, bool $staff = false): string
  {
    // Resolve immutable evidence before remote conversion; no private upload URL is leaked.
    foreach ($payload['items'] as &$item) {
      foreach (['damage_photos', 'photos'] as $key) {
        foreach ($item[$key] ?? [] as $index => $photo) {
          $name = (string) $photo['name'];
          if ($name !== basename($name)) throw new \DomainException('Nombre de evidencia no válido.');
          $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $name;
          $bytes = is_file($path) ? file_get_contents($path) : false;
          if (!is_string($bytes) || !hash_equals((string) $photo['sha256'], hash('sha256', $bytes))) {
            throw new \DomainException('Una evidencia fotográfica no está disponible o cambió.');
          }
          $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
          if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new \DomainException('Formato de evidencia no válido.');
          $item[$key][$index]['pdf_data_uri'] = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }
      }
    }
    unset($item);
    $html = CompletionDocument::render($act, $payload, $staff);
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render($html, 'Acta de recibo a satisfacción #' . $act['id'] . ' del contrato #' . $payload['contract'], [
      'stylesheets' => [$root . '/public/assets/css/ticket-completion.css', $root . '/public/assets/css/ticket-completion-document.css'],
      'external_fonts' => false,
      'body_class' => 'scm-acta-page scm-acta-pdf', 'wrapper_class' => 'scm-acta-print-root', 'container_class' => 'scm-acta-pdf-container', 'allow_fallback' => false,
    ]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF del acta con Chromium. Intenta nuevamente; la firma y el cierre solo se guardan si se completa el documento.');
    return $bytes;
  }
}
