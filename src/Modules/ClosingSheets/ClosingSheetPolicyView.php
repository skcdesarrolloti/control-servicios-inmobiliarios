<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\Modules\Pending\PublicServicesUi as UI;

/**
 * Configuración › Configuración hoja de cierre (public/assets/js/admin-tools.js, initClosingPolicySettings).
 * - Políticas por ítem: las políticas de wp_jet_cct_politicas que incumple cada ítem de la lista de chequeo, los estados
 *   que la incumplen y una regla escrita. Al lado, el catálogo de políticas con su texto completo para leerlas antes de
 *   asignarlas.
 * - Archivos obligatorios: qué documentos deben adjuntarse (o justificarse) cuando quedan en «Cumple».
 */
final class ClosingSheetPolicyView
{
  /**
   * @param array<string,array{policies:list<int>,states:list<string>,note:string}> $rules
   * @param array<int,array{id:int,title:string,text:string}> $catalog
   * @param list<string> $requiredFiles
   */
  public static function form(array $rules, array $catalog, array $requiredFiles): string
  {
    $policyRows = '';
    $fileRows = '';
    foreach (ClosingSheetForm::CHECKLIST as [$group, , $items]) {
      $rows = '';
      $files = '';
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
          . '<button type="button" class="cpp-pick" data-cpp-pick><span class="material-symbols-outlined" aria-hidden="true">add</span> Agregar política</button></div>'
          . '<div class="cpp-states">' . $states . '</div>'
          . '<input class="' . UI::INPUT . ' cpp-note" type="text" maxlength="600" placeholder="Regla escrita (opcional), p. ej. el término mínimo es de 12 meses" value="' . self::e($active ? $rule['note'] : '') . '" data-cpp-note aria-label="Regla escrita">'
          . '</div>';
        if (isset(ClosingSheetForm::DOCUMENT_ITEMS[$column])) {
          $files .= '<label class="cpp-file"><input type="checkbox" value="' . self::e($column) . '" data-cpp-file' . (in_array($column, $requiredFiles, true) ? ' checked' : '') . '>'
            . '<span><strong>' . self::e(ClosingSheetForm::DOCUMENT_ITEMS[$column]) . '</strong><small>' . self::e($text) . '</small></span></label>';
        }
      }
      $policyRows .= '<details class="cpp-group"' . (self::groupActive($items, $rules) ? ' open' : '') . '><summary>' . self::e($group) . '</summary>' . $rows . '</details>';
      if ($files !== '') {
        $fileRows .= '<fieldset class="cpp-files"><legend>' . self::e($group) . '</legend>' . $files . '</fieldset>';
      }
    }
    $data = json_encode(array_values($catalog), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

    return '<form class="!sp-flex !sp-flex-col !sp-min-h-0 !sp-font-sans !sp-text-service-navy" data-cpp-form autocomplete="off" novalidate>'
      . self::style()
      . '<script type="application/json" data-cpp-catalog>' . $data . '</script>'
      . '<nav class="cpp-tabs" role="tablist">'
      . '<button type="button" role="tab" class="is-active" data-cpp-tab="policies" aria-selected="true">Políticas por ítem</button>'
      . '<button type="button" role="tab" data-cpp-tab="files" aria-selected="false">Archivos obligatorios</button></nav>'
      . '<div class="!sp-flex-1 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6">'
      . '<section data-cpp-panel="policies"><p class="cpp-lead">Cuando un ítem queda en un estado marcado aquí, la hoja avisa con la política, pide una justificación y quien aprueba la ve en rojo. Un ítem sin políticas ni regla escrita no avisa. Con <b>Agregar política</b> se abre el catálogo (' . count($catalog) . ' políticas) para leerlas completas antes de escoger.</p>'
      . '<div class="cpp-layout"><div class="cpp-items">' . $policyRows . '</div>'
      . '<aside class="cpp-browser" data-cpp-browser><header><strong data-cpp-browser-title>Catálogo de políticas</strong><button type="button" class="cpp-x" data-cpp-browser-close aria-label="Cerrar catálogo">×</button></header>'
      . '<p class="cpp-target" data-cpp-target>Elige un ítem con <b>Agregar política</b> para asignarle una, o lee el catálogo.</p>'
      . '<input class="' . UI::INPUT . '" type="search" placeholder="Buscar por número, título o texto" data-cpp-filter aria-label="Buscar política">'
      . '<div class="cpp-list" data-cpp-list></div></aside></div></section>'
      . '<section data-cpp-panel="files" hidden><p class="cpp-lead">Marca los documentos cuyo archivo es <b>obligatorio</b> cuando el ítem queda en «Cumple». Si falta, la hoja pide decir por qué no se adjunta ahora (no se bloquea el negocio) y quien aprueba lo ve en rojo; al subirlo, la excepción desaparece. Los que no marques siguen siendo opcionales: solo se avisan al guardar.</p>'
      . '<div class="cpp-files-wrap">' . $fileRows . '</div></section>'
      . '</div>'
      . '<footer class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 sm:!sp-px-6 !sp-py-3 !sp-bg-white !sp-border-0 !sp-border-t !sp-border-solid !sp-border-slate-200">'
      . '<span class="!sp-text-[11px] !sp-text-service-muted" role="status" data-cpp-status></span>'
      . '<button type="submit" class="' . UI::PRIMARY . '"><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">save</span>Guardar configuración</button></footer>'
      . '</form>';
  }

  public static function chip(int $id, string $title): string
  {
    return '<span class="cpp-chip" data-cpp-policy="' . $id . '"><button type="button" class="cpp-chip-view" data-cpp-view title="Ver la política: ' . self::e($title) . '">#' . $id . ' · ' . self::e(mb_strimwidth($title, 0, 48, '…', 'UTF-8')) . '</button>'
      . '<button type="button" class="cpp-chip-x" data-cpp-remove aria-label="Quitar la política #' . $id . '">×</button></span>';
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
      . '.cpp-tabs{display:flex;gap:4px;padding:10px 16px 0;background:#fff;border-bottom:1px solid #e2e8f0}'
      . '.cpp-tabs button{padding:8px 14px;border:0;border-bottom:3px solid transparent;background:none;font:inherit;font-size:12px;font-weight:700;color:#64748b;cursor:pointer}'
      . '.cpp-tabs button.is-active{border-bottom-color:#0f1e36;color:#0f1e36}'
      . '.cpp-lead{margin:0 0 12px;font-size:11px;line-height:1.55;color:#64748b}'
      . '.cpp-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,380px);gap:14px;align-items:start}'
      . '.cpp-items{display:grid;gap:10px;min-width:0}'
      . '.cpp-group{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:0 12px}'
      . '.cpp-group>summary{cursor:pointer;padding:10px 0;font-size:12px;font-weight:700}'
      . '.cpp-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) auto;gap:8px 12px;align-items:start;padding:10px 6px;border-top:1px solid #f1f5f9;border-radius:8px}'
      . '.cpp-row.is-active{background:#fff7f7}.cpp-row.is-target{outline:2px solid #0f1e36;outline-offset:-2px}'
      . '.cpp-text{margin:0;font-size:11px;line-height:1.45}'
      . '.cpp-chips{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px}'
      . '.cpp-chip{display:inline-flex;align-items:center;border-radius:999px;background:#fee2e2;color:#991b1b;font-size:10px;font-weight:600}'
      . '.cpp-chip button{border:0;background:none;color:inherit;font:inherit;cursor:pointer}.cpp-chip-view{padding:3px 4px 3px 9px;text-align:left}.cpp-chip-view:hover{text-decoration:underline}.cpp-chip-x{padding:0 7px;font-size:13px}'
      . '.cpp-pick{display:inline-flex;align-items:center;gap:2px;padding:4px 8px;border:1px dashed #94a3b8;border-radius:8px;background:#fff;color:#0f1e36;font:inherit;font-size:11px;font-weight:600;cursor:pointer}'
      . '.cpp-pick .material-symbols-outlined{font-size:16px}'
      . '.cpp-states{display:flex;flex-direction:column;gap:2px;font-size:11px;white-space:nowrap}'
      . '.cpp-note{grid-column:1/-1}'
      . '.cpp-browser{position:sticky;top:0;display:grid;gap:8px;max-height:70vh;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}'
      . '.cpp-browser header{display:flex;justify-content:space-between;align-items:center;font-size:12px}'
      . '.cpp-x{border:0;background:none;font-size:18px;cursor:pointer;color:#64748b}'
      . '.cpp-target{margin:0;padding:8px;border-radius:8px;background:#f8fafc;font-size:11px;color:#334155}'
      . '.cpp-list{display:grid;gap:6px;overflow-y:auto;min-height:0}'
      . '.cpp-pol{border:1px solid #e2e8f0;border-radius:8px;padding:6px 8px}'
      . '.cpp-pol summary{cursor:pointer;font-size:11px;font-weight:700;color:#0f1e36}'
      . '.cpp-pol p{margin:6px 0;font-size:11px;line-height:1.55;white-space:pre-line;color:#334155}'
      . '.cpp-pol button{padding:4px 10px;border:0;border-radius:6px;background:#0f1e36;color:#fff;font:inherit;font-size:11px;font-weight:700;cursor:pointer}'
      . '.cpp-pol button[disabled]{background:#cbd5e1;cursor:default}'
      . '.cpp-pol.is-prov summary::after{content:" · para proveedores";color:#b45309;font-weight:600}'
      . '.cpp-files-wrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px}'
      . '.cpp-files{margin:0;padding:8px 12px 10px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}'
      . '.cpp-files legend{padding:0 4px;font-size:12px;font-weight:700}'
      . '.cpp-file{display:flex;gap:8px;align-items:flex-start;padding:5px 0;font-size:11px;cursor:pointer}'
      . '.cpp-file input{margin-top:2px}.cpp-file small{display:block;color:#64748b;line-height:1.4}'
      . '@media (max-width:900px){.cpp-layout{grid-template-columns:1fr}.cpp-browser{position:static;max-height:none}.cpp-row{grid-template-columns:1fr}.cpp-states{flex-direction:row;gap:12px}}'
      . '</style>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
