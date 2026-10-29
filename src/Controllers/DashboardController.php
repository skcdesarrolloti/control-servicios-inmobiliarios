<?php

namespace SCM\Controllers;

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\Auth;

final class DashboardController
{
  private SuCasaControlServiciosInmobiliarios $app;

  public function __construct(\SCM\Core\Database $db)
  {
    $this->app = new SuCasaControlServiciosInmobiliarios($db);
  }

  public function requireAuth(): void
  {
    $loginUrl = SCM_BASE_URL . '/login.php';
    $caseId = (string) ($_GET['scm_case'] ?? '');
    if (preg_match('/^[1-9][0-9]{0,18}$/D', $caseId)) {
      $loginUrl .= '?next=' . rawurlencode('index.php?scm_case=' . $caseId);
    } elseif ((string) ($_GET['tab'] ?? '') === 'credito_propietario') {
      $creditId = (string) ($_GET['credito'] ?? '');
      $loginUrl .= '?next=' . rawurlencode('index.php?tab=credito_propietario' . (preg_match('/^[1-9][0-9]{0,18}$/D', $creditId) ? '&credito=' . $creditId : ''));
    }
    Auth::requireLogin($loginUrl);
  }

  public function getPanelHtml(): string
  {
    return $this->app->renderPanel();
  }

  /** @return array<int,string> */
  public function getAllowedTabs(): array
  {
    return $this->app->currentAllowedTabs();
  }
}
