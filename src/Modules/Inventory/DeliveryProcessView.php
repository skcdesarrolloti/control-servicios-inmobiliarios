<?php

declare(strict_types=1);

namespace SCM\Modules\Inventory;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Actividades administrativas › Proceso de entrega: listados de revisiones antes de la ocupación e inventarios. */
final class DeliveryProcessView
{
  private const TH = '!sp-text-left !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted !sp-px-3 !sp-py-4 !sp-border-x-0 !sp-border-y !sp-border-solid !sp-border-slate-200';
  private const TD = '!sp-border-x-0 !sp-border-y-0 !sp-px-3 !sp-py-4 !sp-align-top';
  private const MUTED = '!sp-block !sp-mt-1 !sp-text-[9px] !sp-text-service-muted';
  public const PER_PAGE = [25, 50, 100];

  public static function shell(string $kind, string $action): string
  {
    $ui = UI::class;
    $inventories = $kind === 'inventarios';
    $title = $inventories ? 'Inventarios' : 'Revisiones antes de la ocupación';
    $description = $inventories
      ? 'Inventarios del inmueble elaborados en los casos de entrega y recibo. Consulta el documento o edítalo sin abrir el caso.'
      : 'Revisiones del inmueble hechas antes de la ocupación en los casos de entrega. Consulta el informe o edítala sin abrir el caso.';
    $field = static fn(string $label, string $control, string $wrap = ''): string => '<label class="!sp-block ' . $wrap . '"><span class="' . $ui::LABEL . '">' . self::e($label) . '</span>' . $control . '</label>';
    $input = static fn(string $name, string $type, string $placeholder = ''): string => '<input class="' . $ui::INPUT . '" name="' . $name . '" type="' . $type . '"' . ($placeholder !== '' ? ' placeholder="' . self::e($placeholder) . '"' : '') . ($type === 'search' ? '' : ' autocomplete="off"') . '>';
    $select = static function (string $name, array $options, string $empty) use ($ui): string {
      $html = '<select class="' . $ui::INPUT . '" name="' . $name . '"><option value="">' . self::e($empty) . '</option>';
      foreach ($options as $option) {
        $html .= '<option value="' . self::e($option) . '">' . self::e($option) . '</option>';
      }
      return $html . '</select>';
    };
    $perPage = '<select class="' . $ui::INPUT . '" name="per_page">' . implode('', array_map(static fn(int $n): string => '<option value="' . $n . '">' . $n . '</option>', self::PER_PAGE)) . '</select>';

    $filters = $field('Buscar', $input('q', 'search', 'Número, caso, contrato, inmueble, dirección, partes o funcionario…'), 'sm:!sp-col-span-2')
      . $field('Contrato', $input('contrato', 'text', 'Ej: 860'))
      . $field('Inmueble SIMI', $input('inmueble', 'text', 'Ej: 10027'))
      . $field('Caso', $input('caso', 'text', 'Ej: 11200'))
      . $field('Propietario', $input('propietario', 'text', 'Nombre del propietario…'))
      . $field('Arrendatario', $input('arrendatario', 'text', 'Nombre del arrendatario…'))
      . $field($inventories ? 'Elaborado por' : 'Realizada por', $input('funcionario', 'text', 'Nombre del funcionario…'));
    if ($inventories) {
      $filters .= $field('Tipo de inventario', $select('tipo', ['Nuevo', 'Anterior'], 'Todos'))
        . $field('Categoría', $select('categoria', ['No amoblado', 'Amoblado'], 'Todas'));
    }
    $filters .= $field('Desde', $input('desde', 'date')) . $field('Hasta', $input('hasta', 'date')) . $field('Registros por página', $perPage);

    return '<div class="!sp-font-sans !sp-text-service-navy !sp-bg-service-background !sp-p-3 sm:!sp-p-5 !sp-space-y-5" data-delivery-process="' . self::e($kind) . '" data-action="' . self::e($action) . '">'
      . '<header class="' . $ui::CARD . ' !sp-p-5 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-4">'
      . '<div class="!sp-flex !sp-items-center !sp-gap-3"><span class="!sp-p-2.5 !sp-bg-amber-50 !sp-rounded-xl !sp-border !sp-border-solid !sp-border-amber-200">' . $ui::icon($inventories ? 'document' : 'building', '!sp-w-6 !sp-h-6') . '</span>'
      . '<div><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Proceso de entrega</p><h2 class="!sp-m-0 !sp-text-xl !sp-font-bold !sp-text-service-navy">' . self::e($title) . '</h2>'
      . '<p class="!sp-m-0 !sp-mt-1 !sp-text-xs !sp-text-service-muted">' . self::e($description) . '</p></div></div>'
      . '<div class="!sp-text-right !sp-border-y-0 !sp-border-r-0 !sp-border-l !sp-border-solid !sp-border-slate-200 !sp-pl-4"><strong class="!sp-text-xl" data-delivery-process-total>·</strong><p class="!sp-m-0 !sp-text-[9px] !sp-uppercase !sp-text-service-muted">Registros encontrados</p></div>'
      . '</header>'
      . '<div class="' . $ui::CARD . ' !sp-p-5">'
      . '<div class="!sp-flex !sp-flex-wrap !sp-justify-between !sp-items-center !sp-gap-2 !sp-pb-3 !sp-mb-4 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100"><h3 class="!sp-m-0 !sp-flex !sp-gap-2 !sp-items-center !sp-text-[10px] !sp-font-semibold !sp-uppercase !sp-tracking-wide">' . $ui::icon('filter') . 'Filtros</h3><span class="!sp-text-[10px] !sp-text-service-muted">Contrato, inmueble y caso buscan el número exacto</span></div>'
      . '<form autocomplete="off" data-delivery-process-search role="search">'
      . '<div class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-4 !sp-gap-4">' . $filters . '</div>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-3 !sp-mt-5"><button class="' . $ui::PRIMARY . '" type="submit">' . $ui::icon('filter') . 'Filtrar</button>'
      . '<button class="!sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-text-xs !sp-font-sans !sp-cursor-pointer hover:!sp-text-service-navy" type="button" data-delivery-process-clear>Limpiar filtros</button></div>'
      . '</form></div>'
      . '<div class="' . $ui::CARD . '" data-delivery-process-content><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando…</p></div>'
      . '</div>';
  }

  /** @param list<array<string,mixed>> $rows @param array{manage:bool} $can */
  public static function inventories(array $rows, array $can, int $page, int $pages, int $total, callable $viewUrl): string
  {
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $ticket = (int) ($row['id_ticket'] ?? 0);
      $type = self::clean($row['tipo_inventario'] ?? '');
      $typeBadge = $type === '' ? '' : '<span class="!sp-inline-flex !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[9px] !sp-font-bold ' . ($type === 'Anterior' ? '!sp-bg-slate-100 !sp-border-slate-200 !sp-text-slate-700' : '!sp-bg-sky-50 !sp-border-sky-200 !sp-text-sky-800') . '">' . self::e($type) . '</span>';
      $category = self::clean($row['categoria_inventario'] ?? '');
      $categoryBadge = $category === '' ? '' : '<span class="!sp-inline-flex !sp-px-2 !sp-py-0.5 !sp-rounded-full !sp-border !sp-border-solid !sp-text-[9px] !sp-font-semibold ' . ($category === 'Amoblado' ? '!sp-bg-violet-50 !sp-border-violet-200 !sp-text-violet-800' : '!sp-bg-white !sp-border-slate-200 !sp-text-service-muted') . '">' . self::e($category) . '</span>';
      $detail = '<div class="!sp-flex !sp-flex-wrap !sp-gap-1">' . $typeBadge . $categoryBadge . '</div>'
        . self::propertyFacts(self::clean($row['tipo_de_inmueble'] ?? ''), $row['cantidad_habitaciones'] ?? '', $row['cantidad_banos'] ?? '');
      $actions = self::viewButton((string) $viewUrl($id), 'Inventario #' . $id)
        . ($can['manage'] && $ticket > 0 ? self::editButton($id, $ticket, 'inventario') : '');
      $body .= self::row($id, $row, $ticket, self::first([$row['propietario'] ?? '', $row['c_propietario'] ?? '']), self::first([$row['arrendatario'] ?? '', $row['c_arrendatario'] ?? '']), $detail, $actions);
    }
    return self::table(['Inventario', 'Caso', 'Inmueble y contrato', 'Partes', 'Detalle', 'Acciones'], $body, $page, $pages, $total, 'inventarios');
  }

  /** @param list<array<string,mixed>> $rows @param array{manage:bool} $can */
  public static function reviews(array $rows, array $can, int $page, int $pages, int $total, callable $viewUrl): string
  {
    $body = '';
    foreach ($rows as $row) {
      $id = (int) $row['_ID'];
      $ticket = (int) ($row['id_ticket'] ?? 0);
      $detail = self::propertyFacts(self::clean($row['tip_inm'] ?? ''), $row['habitaciones'] ?? '', $row['banos'] ?? '', false);
      $actions = self::viewButton((string) $viewUrl($id), 'Revisión antes de la ocupación #' . $id)
        . ($can['manage'] && $ticket > 0 ? self::editButton($id, $ticket, 'entrega') : '');
      $body .= self::row($id, $row, $ticket, self::first([$row['destinatario'] ?? '', $row['c_propietario'] ?? '']), self::clean($row['c_arrendatario'] ?? ''), $detail, $actions);
    }
    return self::table(['Revisión', 'Caso', 'Inmueble y contrato', 'Partes', 'Inmueble revisado', 'Acciones'], $body, $page, $pages, $total, 'revisiones');
  }

  /** @param array<string,mixed> $row */
  private static function row(int $id, array $row, int $ticket, string $owner, string $tenant, string $detail, string $actions): string
  {
    $ts = (int) ($row['fecha'] ?? 0) ?: (strtotime((string) ($row['cct_created'] ?? '')) ?: 0);
    $creator = self::clean($row['creador'] ?? '');
    $property = self::first([$row['inmueble'] ?? '', $row['c_inmueble'] ?? '']);
    $contract = ltrim(self::first([$row['contrato'] ?? '', $row['c_contrato'] ?? '']), '#');
    $address = self::clean($row['direccion'] ?? '');
    $party = static fn(string $label, string $name): string => '<span class="!sp-block !sp-text-[8px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">' . $label . '</span><span class="!sp-block !sp-font-medium !sp-leading-snug">' . self::e($name !== '' ? $name : '-') . '</span>';

    return '<tr class="!sp-bg-white hover:!sp-bg-slate-50 !sp-border-t-0 !sp-border-x-0 !sp-border-b !sp-border-solid !sp-border-slate-100">'
      . '<td class="' . self::TD . ' !sp-whitespace-nowrap"><span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-amber-900 !sp-font-bold">#' . $id . '</span>'
      . '<small class="' . self::MUTED . '">' . ($ts > 0 ? date('d/m/Y', $ts) : '-') . '</small>'
      . '<small class="' . self::MUTED . ' !sp-max-w-[140px] !sp-whitespace-normal">Por ' . self::e($creator !== '' ? $creator : 'Sin registrar') . '</small></td>'
      . '<td class="' . self::TD . ' !sp-whitespace-nowrap">' . ($ticket > 0 ? '<strong class="!sp-block">Caso #' . $ticket . '</strong>' : '<span class="!sp-text-service-muted">Sin caso</span>') . '</td>'
      . '<td class="' . self::TD . ' !sp-max-w-[260px]">' . ($property !== '' ? '<span class="!sp-inline-flex !sp-px-2 !sp-py-1 !sp-rounded !sp-bg-emerald-50 !sp-border !sp-border-solid !sp-border-emerald-200 !sp-text-emerald-700 !sp-font-semibold" title="Código del inmueble en SIMI">SIMI ' . self::e($property) . '</span>' : '<span class="!sp-text-service-muted">Sin inmueble</span>')
      . '<small class="' . self::MUTED . ' !sp-leading-relaxed">' . self::e($address !== '' ? $address : '-') . '</small>'
      . '<small class="' . self::MUTED . '">Contrato ' . ($contract !== '' ? '#' . self::e($contract) : 'sin asignar') . '</small></td>'
      . '<td class="' . self::TD . ' !sp-max-w-[240px] !sp-space-y-2">' . $party('Propietario', $owner) . $party('Arrendatario', $tenant) . '</td>'
      . '<td class="' . self::TD . ' !sp-max-w-[200px]">' . $detail . '</td>'
      . '<td class="' . self::TD . '"><div class="!sp-flex !sp-flex-wrap !sp-gap-1.5 !sp-min-w-[150px]">' . $actions . '</div></td>'
      . '</tr>';
  }

  private static function propertyFacts(string $type, mixed $rooms, mixed $baths, bool $spaced = true): string
  {
    $facts = array_filter([
      trim((string) $rooms) !== '' ? 'Habitaciones: ' . trim((string) $rooms) : '',
      trim((string) $baths) !== '' ? 'Baños: ' . trim((string) $baths) : '',
    ]);
    return '<span class="!sp-block !sp-font-semibold' . ($spaced ? ' !sp-mt-1.5' : '') . '">' . self::e($type !== '' ? $type : 'Tipo sin registrar') . '</span>'
      . ($facts ? '<small class="' . self::MUTED . '">' . self::e(implode(' · ', $facts)) . '</small>' : '');
  }

  private static function viewButton(string $url, string $title): string
  {
    return '<button type="button" class="' . UI::NAVY . ' !sp-whitespace-nowrap" data-scm-open-iframe data-iframe-url="' . self::e($url) . '" data-iframe-title="' . self::e($title) . '">' . UI::icon('eye', '!sp-w-3 !sp-h-3 !sp-text-service-yellow') . 'Ver</button>';
  }

  private static function editButton(int $id, int $ticket, string $kind): string
  {
    return '<button type="button" class="' . UI::SECONDARY . ' !sp-text-[10px] !sp-whitespace-nowrap" data-delivery-process-edit="' . $id . '" data-edit-kind="' . self::e($kind) . '" data-ticket-pk="' . $ticket . '">' . UI::icon('edit', '!sp-w-3 !sp-h-3') . 'Editar</button>';
  }

  /** @param list<string> $headers */
  private static function table(array $headers, string $body, int $page, int $pages, int $total, string $kind): string
  {
    if ($body === '') {
      return '<div class="!sp-p-10 !sp-text-center"><span class="!sp-inline-flex !sp-p-3 !sp-rounded-full !sp-bg-slate-100 !sp-text-service-muted">' . UI::icon('document', '!sp-w-5 !sp-h-5') . '</span>'
        . '<p class="!sp-m-0 !sp-mt-3 !sp-text-xs !sp-font-semibold">No hay registros con esos filtros</p><p class="!sp-m-0 !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">Ajusta o limpia los filtros para ver más resultados.</p></div>';
    }
    $head = implode('', array_map(static fn(string $text): string => '<th class="' . self::TH . '">' . self::e($text) . '</th>', $headers));
    $button = static fn(int $target, bool $disabled, string $label): string => '<button type="button" class="' . UI::SECONDARY . '" data-delivery-process-page="' . $target . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>';
    return '<div class="!sp-overflow-x-auto"><table class="!sp-w-full !sp-border-collapse !sp-text-[10px] !sp-text-service-navy !sp-min-w-[960px]" data-delivery-process-table="' . self::e($kind) . '"><thead class="!sp-bg-slate-50"><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
      . '<div class="!sp-p-4 !sp-bg-slate-50/50 !sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3"><span class="!sp-text-[10px] !sp-text-service-muted">Página ' . $page . ' de ' . $pages . ' · ' . $total . ' registros</span>'
      . ($pages > 1 ? '<div class="!sp-flex !sp-items-center !sp-gap-2">' . $button(max(1, $page - 1), $page <= 1, 'Anterior')
        . '<span class="!sp-bg-service-navy !sp-text-white !sp-text-[10px] !sp-px-3 !sp-py-2 !sp-rounded">' . $page . '</span>'
        . $button(min($pages, $page + 1), $page >= $pages, 'Siguiente') . '</div>' : '')
      . '</div>';
  }

  /** Vacío para los valores que en los registros antiguos marcan "sin dato". */
  private static function clean(mixed $value): string
  {
    $text = trim((string) $value);
    return in_array(mb_strtolower($text, 'UTF-8'), ['', '-', 'no asignado', 'n/a'], true) ? '' : $text;
  }

  /** @param list<mixed> $values */
  private static function first(array $values): string
  {
    foreach ($values as $value) {
      if (($text = self::clean($value)) !== '') {
        return $text;
      }
    }
    return '';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
