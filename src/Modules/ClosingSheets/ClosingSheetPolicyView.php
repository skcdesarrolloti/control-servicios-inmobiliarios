<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Modules\Pending\PublicServicesUi as UI;

/**
 * Configuración › Políticas de la hoja de cierre (public/assets/js/admin-tools.js, initClosingPolicySettings): por cada
 * ítem de la lista de chequeo, las políticas de wp_jet_cct_politicas que incumple, los estados que la incumplen y una
 * regla escrita opcional.
 */
final class ClosingSheetPolicyView
{
  /**
   * @param array<string,array{policies:list<int>,states:list<string>,note:string}> $rules
   * @param array<int,array{id:int,title:string,text:string}> $catalog
   */
  public static function form(array $rules, array $catalog): string
  {
    $options = '';
    foreach ($catalog as $policy) {
      $options .= '<option value="#' . $policy['id'] . ' · ' . self::e($policy['title']) . '"></option>';
    }
    $sections = '';
    foreach (ClosingSheetForm::CHECKLIST as [$group, , $items]) {
      $rows = '';
      foreach ($items as [$column, $text]) {
        $rule = $rules[$column] ?? ['policies' => [], 'states' => ['No cumple'], 'note' => ''];
        $active = isset($rules[$column]);
        $chips = '';
        foreach ($rule['policies'] as $id) {
          $chips .= self::chip($id, (string) ($catalog[$id]['title'] ?? 'Política #' . $id . ' (ya no existe)'));
        }
        $states = '';
        foreach (ClosingSheetPolicies::STATES as $state) {
          $states .= '<label class="cpp-state"><input type="checkbox" value="' . self::e($state) . '" data-cpp-state' . (in_array($state, $active ? $rule['states'] : ['No cumple'], true) ? ' checked' : '') . '> ' . self::e($state) . '</label>';
        }
        $rows .= '<div class="cpp-row' . ($active ? ' is-active' : '') . '" data-cpp-item="' . self::e($column) . '">'
          . '<p class="cpp-text">' . self::e($text) . '</p>'
          . '<div class="cpp-policies"><div class="cpp-chips" data-cpp-chips>' . $chips . '</div>'
          . '<div class="cpp-add"><input class="' . UI::INPUT . '" list="cpp-catalog" placeholder="Buscar política por número o título" data-cpp-search aria-label="Agregar política">'
          . '<button type="button" class="' . UI::SECONDARY . '" data-cpp-add>Agregar</button></div></div>'
          . '<div class="cpp-states">' . $states . '</div>'
          . '<input class="' . UI::INPUT . ' cpp-note" type="text" maxlength="600" placeholder="Regla escrita (opcional), p. ej. el término mínimo es de 12 meses" value="' . self::e($active ? $rule['note'] : '') . '" data-cpp-note aria-label="Regla escrita">'
          . '</div>';
      }
      $sections .= '<details class="cpp-group"' . (self::groupActive($items, $rules) ? ' open' : '') . '><summary>' . self::e($group) . '</summary>' . $rows . '</details>';
    }
    return '<form class="!sp-flex !sp-flex-col !sp-min-h-0 !sp-font-sans !sp-text-service-navy" data-cpp-form autocomplete="off" novalidate>'
      . self::style()
      . '<datalist id="cpp-catalog">' . $options . '</datalist>'
      . '<div class="!sp-flex-1 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-4">'
      . '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted !sp-leading-relaxed">Cuando un ítem de la lista de chequeo queda en un estado marcado aquí, la hoja de cierre avisa con la política, pide una justificación y quien aprueba la ve en rojo antes de aprobar. Un ítem sin políticas ni regla escrita no avisa. Las políticas salen de la tabla de políticas (' . count($catalog) . ').</p>'
      . $sections . '</div>'
      . '<footer class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 sm:!sp-px-6 !sp-py-3 !sp-bg-white !sp-border-0 !sp-border-t !sp-border-solid !sp-border-slate-200">'
      . '<span class="!sp-text-[11px] !sp-text-service-muted" role="status" data-cpp-status></span>'
      . '<button type="submit" class="' . UI::PRIMARY . '"><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">save</span>Guardar configuración</button></footer>'
      . '</form>';
  }

  public static function chip(int $id, string $title): string
  {
    return '<span class="cpp-chip" data-cpp-policy="' . $id . '" title="' . self::e($title) . '">#' . $id . ' · ' . self::e(mb_strimwidth($title, 0, 48, '…', 'UTF-8'))
      . '<button type="button" data-cpp-remove aria-label="Quitar la política #' . $id . '">×</button></span>';
  }

  /** @param list<array{0:string,1:string,2:string}> $items @param array<string,mixed> $rules */
  private static function groupActive(array $items, array $rules): bool
  {
    foreach ($items as [$column]) {
      if (isset($rules[$column])) {
        return true;
      }
    }
    return false;
  }

  private static function style(): string
  {
    return '<style>'
      . '.cpp-group{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:0 12px}'
      . '.cpp-group>summary{cursor:pointer;padding:10px 0;font-size:12px;font-weight:700}'
      . '.cpp-row{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1.5fr) auto;gap:8px 12px;align-items:start;padding:10px 0;border-top:1px solid #f1f5f9}'
      . '.cpp-row.is-active{background:#fff7f7}'
      . '.cpp-text{margin:0;font-size:11px;line-height:1.45}'
      . '.cpp-chips{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px}'
      . '.cpp-chip{display:inline-flex;align-items:center;gap:4px;padding:2px 4px 2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:10px;font-weight:600}'
      . '.cpp-chip button{border:0;background:none;color:inherit;font-size:13px;line-height:1;cursor:pointer}'
      . '.cpp-add{display:flex;gap:6px}.cpp-add input{flex:1;min-width:0}'
      . '.cpp-states{display:flex;flex-direction:column;gap:2px;font-size:11px;white-space:nowrap}'
      . '.cpp-note{grid-column:1/-1}'
      . '@media (max-width:720px){.cpp-row{grid-template-columns:1fr}.cpp-states{flex-direction:row;gap:12px}}'
      . '</style>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
