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
    Auth::requireLogin(SCM_BASE_URL . '/login.php');
  }

  public function getPanelHtml(): string
  {
    return $this->app->renderPanel();
  }
}
