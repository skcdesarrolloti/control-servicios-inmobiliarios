<?php

namespace SCM\Views;

final class DashboardView
{
  public function render(string $baseUrl, string $user, string $panelHtml, bool $standaloneFunction = false): void
  {
    $base_url = $baseUrl;
    $user_name = $user;
    $standalone_function = $standaloneFunction;
    $page_title = $standaloneFunction ? 'Función protegida — SKC SuCasa Inmobiliaria' : 'Panel de Control — SKC SuCasa Inmobiliaria';

    require dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="scm-modern-panel-wrapper w-full">';
    echo $panelHtml;
    echo '</div>';
    require dirname(__DIR__, 2) . '/includes/footer.php';
  }
}
