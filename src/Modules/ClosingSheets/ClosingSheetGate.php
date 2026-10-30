<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\Auth;
use SCM\Core\Database;

/**
 * Quién actúa y qué puede hacer en las páginas de la hoja (revision-servicios-cierre.php y hoja-cierre-ver.php):
 * - Con sesión del panel: el funcionario de la sesión, según los permisos de su cargo.
 * - Con enlace firmado (correo o WhatsApp): el funcionario del enlace, solo la acción del enlace y sobre esa hoja.
 */
final class ClosingSheetGate
{
  /**
   * @param array<string,mixed> $params GET o POST
   * @return array{actor:array<string,string>|null,access:array{purpose:string,employee_id:string}|null,session:bool,can:array{view:bool,review:bool,respond:bool,edit:bool},error:string}
   */
  public static function resolve(Database $db, array $params, int $closingId): array
  {
    $workflow = new ClosingSheetWorkflow($db);
    $out = ['actor' => null, 'access' => null, 'session' => false, 'can' => ['view' => false, 'review' => false, 'respond' => false, 'edit' => false], 'error' => ''];
    $access = ClosingSheetAccess::verify($params, $closingId);
    if ($access !== null) {
      try {
        $out['actor'] = $workflow->actor($access['employee_id']);
        $out['access'] = $access;
        $out['can']['view'] = true;
        $out['can']['review'] = $access['purpose'] === ClosingSheetAccess::REVIEW;
        $out['can']['respond'] = $access['purpose'] === ClosingSheetAccess::RESPOND;
      } catch (\DomainException $error) {
        $out['error'] = $error->getMessage();
      }
    } elseif (isset($params['sig'])) {
      $out['error'] = 'El enlace no es válido o ya venció. Pide uno nuevo o entra al panel.';
    }
    if (Auth::isLoggedIn()) {
      $app = new SuCasaControlServiciosInmobiliarios($db);
      $out['session'] = true;
      $out['can']['view'] = true;
      $out['can']['review'] = $out['can']['review'] || $app->canUseClosingSheetAction('closing_sheet_review');
      $out['can']['respond'] = $out['can']['respond'] || $app->canUseClosingSheetAction('closing_sheet_respond');
      $out['can']['edit'] = $app->canUseClosingSheetAction('closing_sheet_edit');
      if ($out['actor'] === null) {
        try {
          $out['actor'] = $workflow->actorByUser(Auth::userId());
          $out['error'] = '';
        } catch (\DomainException $error) {
          $out['error'] = $error->getMessage();
        }
      }
    }
    return $out;
  }
}
