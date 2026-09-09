<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$coreJs = (string) file_get_contents($root . '/public/assets/js/scm-admin.js');
$coreCss = (string) file_get_contents($root . '/public/assets/css/admin/01-core.css');
$dashboard = (string) file_get_contents($root . '/src/App/Concerns/RendersDashboard.php');
$adminCss = (string) file_get_contents($root . '/public/assets/css/scm-admin.css');

$checks = [
  'admin core defines reusable image lightbox' => str_contains($coreJs, 'function openImageLightbox') && str_contains($coreJs, 'function bindGlobalImageLightbox'),
  'admin core intercepts image links without leaving the panel' => str_contains($coreJs, 'imageLinkFromEventTarget') && str_contains($coreJs, 'event.preventDefault();') && str_contains($coreJs, 'document.addEventListener("click", handleImageLightboxClick)'),
  'admin lightbox supports gallery navigation' => str_contains($coreJs, 'moveImageLightbox(-1)') && str_contains($coreJs, 'moveImageLightbox(1)') && str_contains($coreJs, 'ArrowLeft') && str_contains($coreJs, 'ArrowRight'),
  'admin lightbox has accessible popup styles' => str_contains($coreJs, 'aria-modal="true"') && str_contains($coreCss, '.scm-image-lightbox') && str_contains($coreCss, 'min-height: 44px'),
  'quote images are explicitly marked for popup preview' => str_contains($dashboard, 'data-scm-lightbox="1"') && str_contains($dashboard, 'data-scm-lightbox-title'),
  'admin css cache points to refreshed core css' => str_contains($adminCss, './admin/01-core.css?v=3.3.74'),
];

$failed = [];
foreach ($checks as $label => $ok) {
  if (!$ok) {
    $failed[] = $label;
  }
}

if ($failed) {
  fwrite(STDERR, "Admin image lightbox checks failed:\n- " . implode("\n- ", $failed) . "\n");
  exit(1);
}

echo 'Admin image lightbox checks passed: ' . count($checks) . PHP_EOL;
