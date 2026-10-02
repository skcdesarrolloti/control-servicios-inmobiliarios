<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

final class PublicServicesDocument
{
  public static function url(int $id, ?int $expires = null): string
  {
    $expires ??= time() + 180 * 86400;
    return rtrim((string) SCM_BASE_URL, '/') . '/revision-servicios-publicos.php?numero=' . $id . '&expires=' . $expires . '&sig=' . self::signature($id, $expires);
  }

  public static function signature(int $id, int $expires): string { return hash_hmac('sha256', 'public-services-review|' . $id . '|' . $expires, (string) SCM_APP_SECRET); }

  public static function valid(int $id, int $expires, string $signature): bool
  {
    return $id > 0 && $expires > time() && hash_equals(self::signature($id, $expires), $signature);
  }

  public static function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

  /** Limited editor formatting; escape all source HTML before adding safe tags. */
  public static function paragraph(string $value): string
  {
    $escaped = self::e($value);
    $escaped = preg_replace('/\*\*([^\n]+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/__([^\n]+?)__/u', '<u>$1</u>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
    return nl2br($escaped);
  }

  public static function status(string $value): string
  {
    return match($value) {'Al dia'=>'Al día','30 dias'=>'30 días','60 dias'=>'60 días','Estado critico'=>'Crítico / superior a 90 días',default=>$value};
  }

  public static function header(string $title, bool $showCompany = true): string
  {
    $logo = function_exists('system_image') ? \system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL) : '';
    return '<header class="scm-acta-receipt-head">' . ($logo !== '' ? '<span class="scm-acta-logo"><img src="' . self::e($logo) . '" alt="SKC SuCasa Inmobiliaria"></span>' : '')
      . ($showCompany ? '<p class="scm-acta-company">SKC SuCasa Inmobiliaria</p>' : '') . '<h1>' . self::e($title) . '</h1></header>';
  }

  public static function table(array $rows): string
  {
    $html = '<table class="scm-acta-receipt-data"><tbody>';
    foreach ($rows as $label => $value) $html .= '<tr><th scope="row">' . self::e($label) . '</th><td>' . self::e($value !== '' ? $value : 'Sin registrar') . '</td></tr>';
    return $html . '</tbody></table>';
  }

  public static function footer(array $context, bool $institutional = false): string
  {
    $details = array_filter([$context['realizado_por_cargo'] ?? '', $context['realizado_por_telefono'] ?? '']);
    $html = '<footer class="scm-acta-receipt-footer scm-services-signatures"><div><span>Realizado por</span><strong>' . self::e($context['realizado_por'] ?? 'Sin registrar') . '</strong><p>' . self::e(implode(' · ', $details)) . '</p></div>';
    if ($institutional && !empty($context['representante_legal'])) $html .= '<div><span>Firma institucional</span><strong>' . self::e($context['representante_legal']) . '</strong><p>Representante legal' . (!empty($context['celular_legal']) ? ' · ' . self::e($context['celular_legal']) : '') . '</p></div>';
    return $html . '<p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer>';
  }

  public static function act(array $context, array $service, array $template, bool $pdf = false): string
  {
    $title = PublicServicesActTemplates::expand($template['title'], $context, $service);
    $body = PublicServicesActTemplates::expand($template['body'], $context, $service);
    $letterhead = '';
    $letterheadFooter = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
      $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>';
      $letterheadFooter = '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div>';
    }
    $html = '<article class="scm-acta-receipt scm-services-document scm-services-act">' . $letterhead . self::header($title, false)
      . '<p class="scm-acta-receipt-date">' . self::e($context['ciudad'] ?? 'Cartagena de Indias') . ', ' . date('d/m/Y', (int) $context['fecha']) . '</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Datos del servicio</h2>' . self::table([
        'Contrato / Inmueble SIMI' => '#' . ($context['contrato'] ?? '') . ' · ' . ($context['inmueble'] ?? ''),
        'Dirección' => $context['direccion'] ?? '', 'Arrendatario' => $context['arrendatario'] ?? '',
        'Servicio / Referencia' => ($service['display_label'] ?? $service['label']) . ' · ' . $service['account'],
        'Medidor' => $service['meter'], 'Resultado' => self::status($service['status']),
        'Valor reportado' => '$' . number_format((int) $service['amount'], 0, ',', '.') . ' COP',
      ]) . '</section><section class="scm-acta-receipt-section scm-services-letter"><h2>2. Comunicación</h2>';
    foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) $html .= '<p>' . self::paragraph($paragraph) . '</p>';
    return $html . '</section>' . self::footer($context, true) . $letterheadFooter . '</article>';
  }

  public static function review(array $review, array $context, array $services, array $documents, string $publicUrl): string
  {
    $html = '<article class="scm-acta-receipt scm-services-document">' . self::header('Revisión de servicios públicos #' . $review['_ID'])
      . '<p class="scm-acta-receipt-date">Fecha de revisión: ' . date('d/m/Y H:i', (int) $context['fecha']) . ' (Colombia)</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Datos de la revisión</h2>' . self::table([
        'Contrato / Inmueble SIMI' => '#' . ($context['contrato'] ?? '') . ' · ' . ($context['inmueble'] ?? ''),
        'Dirección' => $context['direccion'] ?? '', 'Arrendatario' => $context['arrendatario'] ?? '',
        'Tipo de revisión' => $review['tipo'] ?? 'Durante la ocupación',
        'Inicio del contrato' => !empty($review['inicio_contrato']) ? date('d/m/Y', PublicServicesWorkspace::timestamp($review['inicio_contrato'])) : '',
        'Fin del contrato' => !empty($review['fin_contrato']) ? date('d/m/Y', PublicServicesWorkspace::timestamp($review['fin_contrato'])) : '',
        'Revisiones hasta este registro' => (string) ($review['revisiones_servicios'] ?? ''),
      ]) . '</section><section class="scm-acta-receipt-section"><h2>2. Servicios revisados</h2><div class="scm-services-result-grid">';
    foreach ($services as $key => $service) {
      $html .= '<section class="scm-services-result"><h3>' . self::e($service['display_label'] ?? $key) . '</h3>' . self::table([
        'Identificador' => $service['account'], 'Medidor' => $service['meter'], 'Resultado' => self::status($service['status']),
        'Fecha de revisión' => !empty($service['reviewed_at']) ? date('d/m/Y', $service['reviewed_at']) : '',
        'Fecha de corte' => !empty($service['cutoff_at']) ? date('d/m/Y', $service['cutoff_at']) : '',
        'Valor reportado' => '$' . number_format((int) $service['amount'], 0, ',', '.') . ' COP',
      ]);
      if (isset($documents[$key])) $html .= '<button type="button" class="scm-services-button" data-services-preview="' . self::e($documents[$key]['url']) . '">Ver acta emitida</button>';
      $html .= '</section>';
    }
    return $html . '</div></section>' . self::footer($context) . '</article>';
  }
}
