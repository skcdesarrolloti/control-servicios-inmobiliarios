<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Actividades administrativas › Proceso de entrega: listados de revisiones antes de la ocupación e inventarios. */
final class DeliveryProcessView
{
  private const CELL = '!sp-p-3 !sp-align-top !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-100';
  private const HEAD = '!sp-p-3 !sp-text-left !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted !sp-bg-slate-50';

  public static function shell(string $kind, string $action): string
  {
    $ui = UI::class;
    $inventories = $kind === 'inventarios';
    $title = $inventories ? 'Inventarios' : 'Revisiones antes de la ocupación';
    $filters = $inventories
      ? '<select name="tipo" class="' . $ui::INPUT . ' sm:!sp-max-w-[150px]" aria-label="Tipo de inventario"><option value="">Todos los tipos</option><option>Nuevo</option><option>Anterior</option></select>'
        . '<select name="categoria" class="' . $ui::INPUT . ' sm:!sp-max-w-[160px]" aria-label="Categoría"><option value="">Toda categoría</option><option>No amoblado</option><option>Amoblado</option></select>'
      : '';
    return '<div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-delivery-process="' . self::e($kind) . '" data-action="' . self::e($action) . '">'
      . '<header class="' . $ui::CARD . ' !sp-p-5"><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Proceso de entrega</p>'
      . '<h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">' . self::e($title) . '</h2></header>'
      . '<section class="' . $ui::CARD . ' !sp-p-4 !sp-space-y-4">'
      . '<form class="!sp-flex !sp-flex-wrap !sp-gap-2" data-delivery-process-search role="search">'
      . '<input type="search" name="q" class="' . $ui::INPUT . ' !sp-flex-1 !sp-min-w-[220px]" placeholder="Buscar por número, caso, contrato, inmueble, dirección o funcionario" aria-label="Buscar">'
      . $filters
      . '<input type="date" name="desde" class="' . $ui::INPUT . ' sm:!sp-max-w-[150px]" aria-label="Desde"><input type="date" name="hasta" class="' . $ui::INPUT . ' sm:!sp-max-w-[150px]" aria-label="Hasta">'
      . '<button type="submit" class="' . $ui::NAVY . '">Buscar</button></form>'
      . '<div data-delivery-process-content><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando…</p></div>'
      . '</section></div>';
  }

  /** @param list<array<string,mixed>> $rows @param array{manage:bool} $can */
  public static function inventories(array $rows, array $can, int $page, int $pages, int $total, callable $viewUrl): string
  {
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $ticket = (int) ($row['id_ticket'] ?? 0);
      $body .= '<tr>' . self::idCell($id, $row) . self::textCell((string) ($row['tipo_inventario'] ?? ''), (string) ($row['categoria_inventario'] ?? ''))
        . self::textCell('#' . ltrim((string) ($row['contrato'] ?? ''), '#') . ' · ' . (string) ($row['inmueble'] ?? ''), (string) ($row['direccion'] ?? ''))
        . self::textCell((string) ($row['arrendatario'] ?? ''), '') . self::textCell($ticket > 0 ? '#' . $ticket : '-', (string) ($row['creador'] ?? ''))
        . '<td class="' . self::CELL . '"><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-1.5">' . self::viewButton((string) $viewUrl($id), 'Inventario #' . $id)
        . ($can['manage'] && $ticket > 0 ? '<button type="button" class="' . UI::SECONDARY . '" data-delivery-process-edit="' . $id . '" data-ticket-pk="' . $ticket . '">Editar</button>' : '')
        . '</div></td></tr>';
    }
    return self::table(['Inventario', 'Tipo', 'Contrato e inmueble', 'Arrendatario', 'Caso y funcionario', ''], $body, $page, $pages, $total, 'inventarios');
  }

  /** @param list<array<string,mixed>> $rows */
  public static function reviews(array $rows, int $page, int $pages, int $total, callable $viewUrl): string
  {
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $ticket = (int) ($row['id_ticket'] ?? 0);
      $facts = array_filter([trim((string) ($row['habitaciones'] ?? '')) !== '' ? 'Habitaciones: ' . trim((string) $row['habitaciones']) : '', trim((string) ($row['banos'] ?? '')) !== '' ? 'Baños: ' . trim((string) $row['banos']) : '']);
      $body .= '<tr>' . self::idCell($id, $row) . self::textCell('#' . ltrim((string) ($row['contrato'] ?? ''), '#') . ' · ' . (string) ($row['inmueble'] ?? ''), (string) ($row['direccion'] ?? ''))
        . self::textCell((string) ($row['tip_inm'] ?? ''), implode(' · ', $facts)) . self::textCell($ticket > 0 ? '#' . $ticket : '-', (string) ($row['creador'] ?? ''))
        . '<td class="' . self::CELL . '"><div class="!sp-flex !sp-flex-wrap !sp-justify-end !sp-gap-1.5">' . self::viewButton((string) $viewUrl($id), 'Revisión antes de la ocupación #' . $id) . '</div></td></tr>';
    }
    return self::table(['Revisión', 'Contrato e inmueble', 'Inmueble', 'Caso y funcionario', ''], $body, $page, $pages, $total, 'revisiones');
  }

  /** @param array<string,mixed> $row */
  private static function idCell(int $id, array $row): string
  {
    $ts = (int) ($row['fecha'] ?? 0) ?: (strtotime((string) ($row['cct_created'] ?? '')) ?: 0);
    return '<td class="' . self::CELL . '"><strong>#' . $id . '</strong><small class="!sp-block !sp-text-service-muted">' . ($ts > 0 ? date('d/m/Y', $ts) : '-') . '</small></td>';
  }

  private static function textCell(string $main, string $sub): string
  {
    return '<td class="' . self::CELL . '">' . self::e(trim($main) !== '' ? $main : '-') . ($sub !== '' ? '<small class="!sp-block !sp-text-service-muted">' . self::e($sub) . '</small>' : '') . '</td>';
  }

  private static function viewButton(string $url, string $title): string
  {
    return '<button type="button" class="' . UI::SECONDARY . '" data-scm-open-iframe data-iframe-url="' . self::e($url) . '" data-iframe-title="' . self::e($title) . '">Ver</button>';
  }

  /** @param list<string> $headers */
  private static function table(array $headers, string $body, int $page, int $pages, int $total, string $kind): string
  {
    if ($body === '') {
      return '<p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">No hay registros con esos filtros.</p>';
    }
    $head = implode('', array_map(static fn(string $text): string => '<th class="' . self::HEAD . '">' . self::e($text) . '</th>', $headers));
    return '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-xs" data-delivery-process-table="' . self::e($kind) . '"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
      . '<div class="!sp-flex !sp-items-center !sp-justify-between !sp-gap-2 !sp-mt-3"><span class="!sp-text-[10px] !sp-text-service-muted">' . $total . ' registros · página ' . $page . ' de ' . $pages . '</span><span class="!sp-flex !sp-gap-1.5">'
      . ($page > 1 ? '<button type="button" class="' . UI::SECONDARY . '" data-delivery-process-page="' . ($page - 1) . '">Anterior</button>' : '')
      . ($page < $pages ? '<button type="button" class="' . UI::SECONDARY . '" data-delivery-process-page="' . ($page + 1) . '">Siguiente</button>' : '')
      . '</span></div>';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
