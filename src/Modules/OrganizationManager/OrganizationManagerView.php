<?php

declare(strict_types=1);

namespace SCM\Modules\OrganizationManager;

use SCM\Modules\AdministrativeReports\AdministrativeReportsView;

/**
 * Estructura del Gestor organizacional; el contenido de cada pestaña lo dibuja organization-manager.js con lo que
 * devuelven las acciones scm_org_*. Las pestañas dependen de los permisos por acción (el servidor los vuelve a exigir).
 */
final class OrganizationManagerView
{
  /** pestaña => [título, permiso necesario ('' = todos)] */
  public const TABS = [
    'mi_gestion' => ['Mi gestión', ''],
    'gestion_cargos' => ['Gestión por cargo', ''],
    'areas_organizacion' => ['Áreas', 'view'],
    'cargos' => ['Cargos', 'view'],
    'politicas' => ['Políticas', 'view'],
    'procesos' => ['Procesos', 'view'],
    'funciones' => ['Funciones', 'view'],
    'compensaciones' => ['Compensaciones', 'view'],
    'funcionarios' => ['Funcionarios', 'view'],
    'traslados' => ['Traslados', 'transfer'],
    'bitacora' => ['Bitácora', 'view'],
  ];

  /** @param array{view:bool,manage:bool,delete:bool,transfer:bool,export:bool} $can */
  public function renderShell(array $can, string $userName, string $quillJs, string $quillCss): string
  {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $flag = static fn(bool $value): string => $value ? '1' : '0';
    $tabs = '';
    $first = true;
    foreach (self::TABS as $key => [$label, $permission]) {
      if ($permission !== '' && empty($can[$permission])) {
        continue;
      }
      $tabs .= '<button type="button" role="tab" aria-selected="' . ($first ? 'true' : 'false') . '" data-om-tab="' . $e($key) . '" class="' . AdministrativeReportsView::tabClass($first) . '">' . $e($label) . '</button>';
      $first = false;
    }
    return '<section class="scm-om !sp-flex !sp-flex-col !sp-gap-4 !sp-font-sans !sp-text-service-navy" data-scm-org-manager'
      . ' data-can-view="' . $flag($can['view']) . '" data-can-manage="' . $flag($can['manage']) . '" data-can-delete="' . $flag($can['delete']) . '"'
      . ' data-can-transfer="' . $flag($can['transfer']) . '" data-can-export="' . $flag($can['export']) . '"'
      . ' data-quill-js="' . $e($quillJs) . '" data-quill-css="' . $e($quillCss) . '" aria-labelledby="scm-om-title">'
      . '<header class="!sp-flex !sp-flex-col md:!sp-flex-row md:!sp-items-end !sp-justify-between !sp-gap-3 !sp-pb-3 !sp-border-x-0 !sp-border-t-0 !sp-border-b !sp-border-solid !sp-border-slate-200">'
      . '<div><p class="!sp-m-0 !sp-text-[9px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-muted">Actividades contractuales</p>'
      . '<h2 id="scm-om-title" class="!sp-m-0 !sp-text-xl !sp-font-bold">Gestor organizacional</h2>'
      . '<p class="!sp-m-0 !sp-text-xs !sp-text-service-muted">Áreas, cargos, políticas, procesos, funciones y compensaciones de SKC SuCasa Inmobiliaria, y la gestión de cada funcionario.</p></div>'
      . '<span class="!sp-inline-flex !sp-items-center !sp-gap-2 !sp-px-3 !sp-py-1.5 !sp-rounded-full !sp-bg-white !sp-border !sp-border-solid !sp-border-slate-200 !sp-text-xs !sp-font-semibold"><span class="material-symbols-outlined" aria-hidden="true">account_circle</span>' . $e($userName) . '</span>'
      . '</header>'
      . '<nav class="!sp-flex !sp-items-center !sp-gap-2 !sp-overflow-x-auto !sp-pb-1" role="tablist" aria-label="Gestor organizacional">' . $tabs . '</nav>'
      . '<div class="scm-om-status" data-om-status role="status" aria-live="polite" hidden></div>'
      . '<div data-om-body><p class="!sp-m-0 !sp-p-8 !sp-text-center !sp-text-xs !sp-text-service-muted">Cargando el Gestor organizacional…</p></div>'
      . '</section>';
  }
}
