<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Support\HtmlPdfRenderer;
use SCM\Support\StoredFileService;

final class PublicServicesReviewPdfGenerator
{
  public function generate(array $context, array $services): array
  {
    $documents = [];
    try {
      foreach ($services as $key => $service) {
        $type = PublicServicesActTemplates::type($service['status']);
        $template = ($context['templates'] ?? PublicServicesActTemplates::defaults())[$type];
        $field = $service[$type === 'al_dia' ? 'good_document_field' : 'debt_document_field'];
        $title = PublicServicesActTemplates::expand($template['title'], $context, $service);
        $root = dirname(__DIR__, 3);
        $bytes = HtmlPdfRenderer::render(PublicServicesDocument::act($context, $service, $template, true), $title, [
          'stylesheets' => [$root . '/public/assets/css/ticket-completion-document.css', $root . '/public/assets/css/public-services-document.css'],
          'external_fonts' => false, 'body_class' => 'scm-services-page scm-services-pdf',
          'wrapper_class' => 'scm-services-pdf-root', 'container_class' => 'scm-services-public-root', 'allow_fallback' => false,
        ]);
        if ($bytes === null) throw new \DomainException('No se pudo generar el acta con Chromium. Verifica el servicio de PDF e intenta nuevamente.');
        $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
        $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new \RuntimeException('No fue posible guardar el PDF.');
        $documents[$field] = ['key' => $field, 'title' => $title . ' · ' . $service['display_label'],
          'url' => StoredFileService::fromRuntime()->urlFor($basename), 'path' => $path,
          'attachment_name' => 'Acta-' . $type . '-' . $key . '.pdf'];
      }
    } catch (\Throwable $error) {
      foreach ($documents as $document) if (is_file($document['path'])) @unlink($document['path']);
      throw $error;
    }
    return $documents;
  }
}
