<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Informe "Historial del inmueble": un solo HTML para el panel, el PDF (Chromium),
 * el enlace del bot de WhatsApp y el portal del propietario.
 * Estilos en assets/css/property-history-report.css y filtros en assets/js/property-history-report.js.
 * Las copias en whatsapp-bot-control-servicios y actores/propietario deben mantenerse iguales.
 */
final class PropertyHistoryReport
{
  /** Color de cada fuente (clases phr-tone-*). */
  private const TONES = [
    'historial' => 'navy', 'gestiones_cobro' => 'amber', 'tickets' => 'blue', 'contratos_arrendamiento' => 'emerald',
    'contratos_mandato' => 'teal', 'cierres' => 'violet', 'revisiones_servicios' => 'cyan', 'revision_preventiva' => 'lime',
    'revision_correctiva' => 'rose', 'cotizaciones_mantenimiento' => 'orange', 'estudios_aseguradoras' => 'indigo',
    'actualizaciones' => 'slate', 'llamadas' => 'pink', 'recaptaciones' => 'fuchsia',
  ];
  private const MONTHS = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

  /**
   * @param array<string,mixed> $payload informe (property, contracts, sources, sections, timeline, generated_at)
   * @param array{pdf?:bool,logo?:string,generated_by?:array{name?:string,details?:string},title?:string,exclude?:string[]} $options
   */
  public static function render(array $payload, array $options = []): string
  {
    $pdf = !empty($options['pdf']);
    $exclude = array_flip((array) ($options['exclude'] ?? []));
    $property = (array) ($payload['property'] ?? []);
    $sections = array_values(array_filter((array) ($payload['sections'] ?? []), static fn($section): bool => is_array($section) && !isset($exclude[(string) ($section['key'] ?? '')])));
    $labelToKey = [];
    foreach ($sections as $section) {
      $labelToKey[(string) ($section['label'] ?? '')] = (string) ($section['key'] ?? '');
    }
    $timeline = [];
    foreach ((array) ($payload['timeline'] ?? []) as $item) {
      $key = (string) ($item['source_key'] ?? ($labelToKey[(string) ($item['source'] ?? '')] ?? ''));
      if ($key === '' || isset($exclude[$key])) {
        continue;
      }
      $timeline[] = $item + ['source_key' => $key];
    }
    $contracts = (array) ($payload['contracts'] ?? []);
    $count = static fn(string $key): int => (int) (array_values(array_filter($sections, static fn(array $s): bool => ($s['key'] ?? '') === $key))[0]['count'] ?? 0);
    $code = trim((string) ($property['codigo'] ?? ''));
    $address = trim((string) ($property['direccion'] ?? ''));
    $logo = trim((string) ($options['logo'] ?? ''));

    $html = '<article class="phr' . ($pdf ? ' phr-pdf' : '') . '" data-phr>';
    $html .= '<header class="phr-head">'
      . ($logo !== '' ? '<span class="phr-logo"><img src="' . self::e($logo) . '" alt="SKC SuCasa Inmobiliaria"></span>' : '')
      . '<div class="phr-head-copy"><span class="phr-eyebrow">SKC SuCasa Inmobiliaria · Informe consolidado</span>'
      . '<h1>' . self::e((string) ($options['title'] ?? 'Historial del inmueble')) . ($code !== '' ? ' <span>#' . self::e($code) . '</span>' : '') . '</h1>'
      . '<p>' . self::e($address !== '' ? $address : 'Dirección no registrada') . '</p></div>'
      . '<div class="phr-head-meta"><span>Generado</span><strong>' . self::e((string) ($payload['generated_at'] ?? date('d/m/Y H:i'))) . '</strong></div></header>';

    $movements = count($timeline);
    $html .= '<section class="phr-kpis" aria-label="Resumen">'
      . self::kpi('Movimientos', $movements, 'navy')
      . self::kpi('Contratos', count($contracts), 'emerald')
      . self::kpi('Casos', $count('tickets'), 'blue')
      . self::kpi('Revisiones', $count('revision_preventiva') + $count('revision_correctiva') + $count('revisiones_servicios'), 'lime')
      . self::kpi('Cotizaciones', $count('cotizaciones_mantenimiento'), 'orange')
      . '</section>';

    $facts = [
      'Código web / inmueble' => $code,
      'Dirección' => $address,
      'Barrio / ciudad' => implode(' / ', array_filter([trim((string) ($property['barrio'] ?? '')), trim((string) ($property['ciudad'] ?? ''))])),
      'Propietario' => trim((string) ($property['propietario'] ?? '')),
      'Tipo' => trim((string) ($property['tipo'] ?? '')),
      'Matrícula inmobiliaria' => trim((string) ($property['matricula'] ?? '')),
      'Referencia catastral' => trim((string) ($property['referencia_catastral'] ?? '')),
    ];
    $html .= self::sectionHead('Identificación', 'Datos del inmueble', '')
      . '<dl class="phr-facts">';
    foreach ($facts as $label => $value) {
      $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value !== '' ? $value : 'Sin registrar') . '</dd></div>';
    }
    $html .= '</dl>';

    $html .= self::sectionHead('Contratos', 'Contratos vinculados', count($contracts) . ' registro(s)');
    if ($contracts === []) {
      $html .= '<p class="phr-empty">No se encontraron contratos vinculados a este inmueble.</p>';
    } else {
      $html .= '<div class="phr-contracts">';
      foreach ($contracts as $contract) {
        $status = trim((string) ($contract['status'] ?? ''));
        $html .= '<article class="phr-contract"><div class="phr-contract-id"><span class="phr-tag phr-tone-' . (($contract['type'] ?? '') === 'Administracion' ? 'teal' : 'emerald') . '">' . self::e((string) ($contract['type'] ?? 'Contrato')) . '</span><strong>#' . self::e((string) (($contract['number'] ?? '') ?: '-')) . '</strong></div>'
          . '<div class="phr-contract-body"><strong>' . self::e((string) (($contract['tenant'] ?? '') ?: 'Sin arrendatario registrado')) . '</strong>'
          . (trim((string) ($contract['reference'] ?? '')) !== '' ? '<p>' . self::e((string) $contract['reference']) . '</p>' : '')
          . '<div class="phr-chips">'
          . ($status !== '' ? '<span class="phr-chip phr-status-' . self::e(self::slug($status)) . '">' . self::e($status) . '</span>' : '')
          . (trim((string) ($contract['canon'] ?? '')) !== '' ? '<span class="phr-chip">Canon ' . self::e((string) $contract['canon']) . '</span>' : '')
          . (trim((string) ($contract['date'] ?? '')) !== '' && ($contract['date'] ?? '') !== '-' ? '<span class="phr-chip">Desde ' . self::e((string) $contract['date']) . '</span>' : '')
          . (trim((string) ($contract['end_date'] ?? '')) !== '' ? '<span class="phr-chip">Hasta ' . self::e((string) $contract['end_date']) . '</span>' : '')
          . '</div></div></article>';
      }
      $html .= '</div>';
    }

    $html .= self::sectionHead('Cronología', 'Línea de tiempo del inmueble', $movements . ' movimiento(s)');
    if (!$pdf && $timeline !== []) {
      $html .= '<div class="phr-toolbar" data-phr-toolbar><label class="phr-search"><span>Buscar</span><input type="search" placeholder="Caso, contrato, actividad, detalle..." data-phr-search></label><div class="phr-filters" role="group" aria-label="Filtrar por fuente"><button type="button" class="phr-filter is-active" data-phr-filter="">Todo <b>' . $movements . '</b></button>';
      foreach ($sections as $section) {
        $sectionCount = count(array_filter($timeline, static fn(array $item): bool => $item['source_key'] === ($section['key'] ?? '')));
        if ($sectionCount > 0) {
          $html .= '<button type="button" class="phr-filter phr-tone-' . self::tone((string) $section['key']) . '" data-phr-filter="' . self::e((string) $section['key']) . '">' . self::e((string) $section['label']) . ' <b>' . $sectionCount . '</b></button>';
        }
      }
      $html .= '</div><p class="phr-noresults" data-phr-noresults hidden>Ningún movimiento coincide con el filtro.</p></div>';
    }
    if ($timeline === []) {
      $html .= '<p class="phr-empty">No se encontraron movimientos para este inmueble.</p>';
    } else {
      $html .= '<div class="phr-timeline">';
      $currentMonth = '';
      foreach ($timeline as $item) {
        $ts = (int) ($item['date_ts'] ?? 0);
        $month = $ts > 0 ? ucfirst(self::MONTHS[(int) date('n', $ts)]) . ' ' . date('Y', $ts) : 'Sin fecha';
        if ($month !== $currentMonth) {
          $html .= ($currentMonth !== '' ? '</ol></section>' : '') . '<section class="phr-month" data-phr-month><h4>' . self::e($month) . '</h4><ol>';
          $currentMonth = $month;
        }
        $key = (string) $item['source_key'];
        $detail = trim((string) ($item['detail'] ?? ''));
        $search = mb_strtolower(implode(' ', [(string) ($item['title'] ?? ''), $detail, (string) ($item['reference'] ?? ''), (string) ($item['source'] ?? '')]), 'UTF-8');
        $html .= '<li class="phr-event phr-tone-' . self::tone($key) . '" data-phr-item data-phr-source="' . self::e($key) . '" data-phr-text="' . self::e($search) . '">'
          . '<span class="phr-dot" aria-hidden="true"></span>'
          . '<div class="phr-event-date">' . self::e($ts > 0 ? date('d/m/Y', $ts) : (string) ($item['date'] ?? '-')) . ($ts > 0 && date('H:i', $ts) !== '00:00' ? '<small>' . date('g:i a', $ts) . '</small>' : '') . '</div>'
          . '<div class="phr-event-body"><div class="phr-event-top"><span class="phr-tag phr-tone-' . self::tone($key) . '">' . self::e((string) ($item['source'] ?? '')) . '</span><strong>' . self::e((string) ($item['title'] ?? 'Registro')) . '</strong></div>'
          . ($detail !== '' ? self::detail($detail, $pdf) : '')
          . self::references((string) ($item['reference'] ?? ''))
          . '</div></li>';
      }
      $html .= '</ol></section></div>';
    }

    $withItems = array_values(array_filter($sections, static fn(array $section): bool => (int) ($section['count'] ?? 0) > 0));
    if ($withItems !== []) {
      $html .= self::sectionHead('Fuentes', 'Detalle por fuente', count($withItems) . ' fuente(s) con registros');
      foreach ($withItems as $section) {
        $items = (array) ($section['items'] ?? []);
        $html .= '<details class="phr-source phr-tone-' . self::tone((string) $section['key']) . '"' . ($pdf ? ' open' : '') . '><summary><span><strong>' . self::e((string) $section['label']) . '</strong><small>' . self::e((string) ($section['description'] ?? '')) . '</small></span><b>' . (int) $section['count'] . '</b></summary>'
          . '<table><thead><tr><th>Fecha</th><th>Registro</th><th>Referencia</th><th>Detalle</th></tr></thead><tbody>';
        foreach ($items as $item) {
          $html .= '<tr><td>' . self::e((string) ($item['date'] ?? '-')) . '</td><td>' . self::e((string) ($item['title'] ?? '')) . '</td><td>' . self::e((string) ($item['reference'] ?? '')) . '</td><td>' . self::e(self::clip((string) ($item['detail'] ?? ''), 280)) . '</td></tr>';
        }
        $html .= '</tbody></table>' . ((int) $section['count'] > count($items) ? '<p class="phr-note">Se muestran los ' . count($items) . ' registros más recientes de ' . (int) $section['count'] . '.</p>' : '') . '</details>';
      }
    }

    $by = (array) ($options['generated_by'] ?? []);
    $html .= '<footer class="phr-foot"><div><span>Realizado por</span><strong>' . self::e((string) (($by['name'] ?? '') ?: 'SKC SuCasa Inmobiliaria')) . '</strong>' . (trim((string) ($by['details'] ?? '')) !== '' ? '<p>' . self::e((string) $by['details']) . '</p>' : '') . '</div>'
      . '<p>SKC SuCasa Inmobiliaria · NIT 900623242-4 · Cartagena de Indias</p></footer>';
    return $html . '</article>';
  }

  private static function kpi(string $label, int $value, string $tone): string
  {
    return '<div class="phr-kpi phr-tone-' . $tone . '"><span>' . self::e($label) . '</span><strong>' . number_format($value, 0, ',', '.') . '</strong></div>';
  }

  private static function sectionHead(string $kicker, string $title, string $meta): string
  {
    return '<div class="phr-section-head"><div><span>' . self::e($kicker) . '</span><h2>' . self::e($title) . '</h2></div>' . ($meta !== '' ? '<small>' . self::e($meta) . '</small>' : '') . '</div>';
  }

  private static function detail(string $detail, bool $pdf): string
  {
    if ($pdf || mb_strlen($detail, 'UTF-8') <= 260) {
      return '<p class="phr-event-detail">' . self::e(self::clip($detail, $pdf ? 600 : 260)) . '</p>';
    }
    return '<details class="phr-more"><summary><span class="phr-event-detail">' . self::e(self::clip($detail, 220)) . '</span><em>Ver más</em></summary><p class="phr-event-detail">' . self::e(self::clip($detail, 1500)) . '</p></details>';
  }

  private static function references(string $reference): string
  {
    $parts = array_values(array_filter(array_map('trim', explode(' · ', $reference))));
    if ($parts === []) {
      return '';
    }
    return '<div class="phr-chips">' . implode('', array_map(static fn(string $part): string => '<span class="phr-chip">' . self::e($part) . '</span>', $parts)) . '</div>';
  }

  private static function tone(string $key): string
  {
    return self::TONES[$key] ?? 'slate';
  }

  private static function clip(string $text, int $length): string
  {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return mb_strlen($text, 'UTF-8') > $length ? rtrim(mb_substr($text, 0, $length - 1, 'UTF-8')) . '…' : $text;
  }

  private static function slug(string $value): string
  {
    $value = strtolower(strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
