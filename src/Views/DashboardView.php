<?php

namespace SCM\Views;

final class DashboardView
{
  public function render(string $baseUrl, string $user, string $panelHtml, bool $standaloneFunction = false): void
  {
    $base_url = $baseUrl;
    $user_name = $user;
    $standalone_function = $standaloneFunction;
    $body_class = $standaloneFunction ? 'scm-standalone-function' : '';
    $page_title = $standaloneFunction ? 'Función protegida — SKC SuCasa Inmobiliaria' : 'Panel de Control — SKC SuCasa Inmobiliaria';

    require dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="scm-modern-panel-wrapper w-full">';
    echo $panelHtml;
    echo '</div>';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '/assets/css/modern-ui.css?v=' . (defined('SCM_VERSION') ? SCM_VERSION : time()) . '">';
    require dirname(__DIR__, 2) . '/includes/footer.php';
  }
}
