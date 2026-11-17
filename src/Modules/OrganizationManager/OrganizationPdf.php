<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Support\HtmlPdfRenderer;

/**
 * PDF del Gestor organizacional con el renderizador del servidor (Chromium o Gotenberg, como las constancias de casos
 * jurídicos): políticas agrupadas por área y reporte integral de gestión de un cargo (Mi gestión).
 */
final class OrganizationPdf
{
  private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  /**
   * Políticas agrupadas por su primera área (como el plugin), en orden alfabético.
   * @param list<array<string,mixed>> $policies filas de politicas @param array<int,string> $areas @param array<int,string> $cargos
   */
  public static function policiesHtml(array $policies, array $areas, array $cargos, string $generatedBy, string $filtersLabel = ''): string
  {
    $groups = [];
    foreach ($policies as $policy) {
      $ids = OrganizationRepository::ids($policy['id_area'] ?? '');
      $groups[$areas[$ids[0] ?? 0] ?? 'Sin área asignada'][] = $policy;
    }
    uksort($groups, static fn(string $a, string $b): int => strcmp(OrganizationPolicies::fold($a), OrganizationPolicies::fold($b)));
    $html = self::head('Políticas organizacionales', count($policies) . ' ' . (count($policies) === 1 ? 'política' : 'políticas') . ($filtersLabel !== '' ? ' · ' . $filtersLabel : ''));
    if ($policies === []) {
      $html .= '<p class="ord-empty">No hay políticas para exportar con los filtros actuales.</p>';
    }
    foreach ($groups as $areaName => $list) {
      usort($list, static fn(array $a, array $b): int => strcmp(OrganizationPolicies::fold((string) ($a['titulo'] ?? '')), OrganizationPolicies::fold((string) ($b['titulo'] ?? ''))));
      $html .= '<section class="ord-group"><h2>' . self::e($areaName) . ' <small>' . count($list) . ' ' . (count($list) === 1 ? 'política' : 'políticas') . '</small></h2>';
      foreach ($list as $policy) {
        $areaNames = self::names($policy['id_area'] ?? '', $areas);
        $meta = array_filter([
          trim((string) ($policy['codigo'] ?? '')),
          'Versión ' . max(1, (int) ($policy['version'] ?? 1)),
          trim((string) ($policy['estado'] ?? '')),
          trim((string) ($policy['audiencia'] ?? '')),
        ]);
        $html .= '<article class="ord-item"><h3>' . self::e((string) ($policy['titulo'] ?? 'Política sin título')) . '</h3>'
          . '<p class="ord-meta">' . self::e(implode(' · ', $meta)) . '</p>'
          . '<div class="ord-text">' . (OrganizationPolicies::cleanHtml((string) ($policy['politica'] ?? '')) ?: '<p>Sin descripción registrada.</p>') . '</div>'
          . '<p class="ord-meta">Cargos: ' . self::e(implode(', ', self::names($policy['id_cargo'] ?? '', $cargos)) ?: 'Sin cargos asignados') . '</p>'
          . (count($areaNames) > 1 ? '<p class="ord-meta">También aplica a: ' . self::e(implode(', ', array_slice($areaNames, 1))) . '</p>' : '')
          . '</article>';
      }
      $html .= '</section>';
    }
    return self::wrap($html, $generatedBy);
  }

  /**
   * Reporte integral de gestión: cargo, políticas y funciones (procesos y compensaciones ya no se usan).
   * @param array<string,mixed> $data OrganizationRepository::managementForEmployee() o managementForCargo()
   */
  public static function managementHtml(array $data, string $generatedBy, string $person = ''): string
  {
    $cargoNames = array_map(static fn(array $cargo): string => (string) $cargo['nombre_cargo'], $data['cargos'] ?? []);
    $html = self::head('Reporte integral de gestión', trim(implode(' · ', $cargoNames) . ($person !== '' ? ' — ' . $person : '')) ?: 'Gestión');
    $section = static function (string $title, array $items, callable $render): string {
      $out = '<section class="ord-group"><h2>' . self::e($title) . '</h2>';
      if ($items === []) {
        return $out . '<p class="ord-empty">Sin registros asignados.</p></section>';
      }
      foreach ($items as $item) {
        $out .= '<article class="ord-item">' . $render($item) . '</article>';
      }
      return $out . '</section>';
    };
    $html .= $section('Información del cargo', $data['cargos'] ?? [], static function (array $cargo): string {
      $out = '<h3>' . self::e((string) $cargo['nombre_cargo']) . '</h3>';
      foreach ($cargo['perfil'] ?? [] as $part) {
        $out .= '<p class="ord-label">' . self::e((string) $part['label']) . '</p><p class="ord-pre">' . self::e((string) $part['text']) . '</p>';
      }
      return $out;
    });
    $html .= $section('Políticas', $data['politicas'] ?? [], static fn(array $policy): string => '<h3>' . self::e((string) ($policy['titulo'] ?? '')) . '</h3>'
      . '<p class="ord-meta">' . self::e(implode(' · ', array_filter([(string) ($policy['codigo'] ?? ''), 'Versión ' . max(1, (int) ($policy['version'] ?? 1))]))) . '</p>'
      . '<div class="ord-text">' . (string) ($policy['html'] ?? '') . '</div>');
    $html .= $section('Funciones', $data['funciones'] ?? [], static fn(array $item): string => '<p class="ord-pre">' . self::e((string) ($item['funcion'] ?? '')) . '</p>'
      . (trim((string) ($item['frecuencia'] ?? '')) !== '' ? '<p class="ord-meta">Frecuencia: ' . self::e((string) $item['frecuencia']) . '</p>' : ''));
    return self::wrap($html, $generatedBy);
  }

  public static function bytes(string $html, string $title): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render($html, $title, ['stylesheets' => [$root . '/public/assets/css/organization-document.css'], 'external_fonts' => false, 'body_class' => 'ord-page', 'wrapper_class' => 'ord-root', 'container_class' => 'ord-container', 'allow_fallback' => false]);
    if ($bytes === null) {
      throw new \DomainException('No se pudo generar el PDF. Verifica el servicio de PDF e intenta nuevamente.');
    }
    return $bytes;
  }

  /** @param array<int,string> $source @return list<string> */
  private static function names(mixed $csv, array $source): array
  {
    return array_values(array_filter(array_map(static fn(int $id): string => $source[$id] ?? '', OrganizationRepository::ids($csv))));
  }

  private static function head(string $kind, string $title): string
  {
    return '<header class="ord-head"><p>SKC SuCasa Inmobiliaria · ' . self::e($kind) . '</p><h1>' . self::e($title) . '</h1></header>';
  }

  private static function wrap(string $html, string $generatedBy): string
  {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
    $date = $now->format('j') . ' ' . self::MONTHS[(int) $now->format('n') - 1] . ' ' . $now->format('Y, g:i a');
    return '<article class="ord-document">' . $html . '<footer class="ord-footer">Generado por ' . self::e($generatedBy) . ' el ' . self::e($date) . '</footer></article>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
}
