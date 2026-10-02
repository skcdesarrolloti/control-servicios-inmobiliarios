<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
define('SCM_VERSION', 'test');
$_SESSION['scm_user_id'] = 42;
$source = file_get_contents(dirname(__DIR__) . '/src/App/Concerns/RendersDashboard.php');
preg_match('/^.*data-scm-draft-user=.*$/m', $source, $matches);
if (!isset($matches[0])) throw new RuntimeException('No se encontró la etiqueta del borrador.');
$class = \SCM\App\SuCasaControlServiciosInmobiliarios::class;
$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
$render = Closure::bind(function () use ($matches): string {
  $assetBaseUrl = 'https://example.test/assets/';
  ob_start();
  try { eval('?>' . $matches[0]); return (string) ob_get_contents(); }
  finally { ob_end_clean(); }
}, $instance, $class);
$html = $render();
if (!str_contains($html, 'data-scm-draft-user="42"') || !str_contains($html, 'defer></script>')) {
  throw new RuntimeException('El panel no terminó de renderizar el identificador del borrador.');
}
echo "Dashboard draft user render check passed.\n";
