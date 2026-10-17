<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Support\ActaAutologin;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$secret = str_repeat('a1', 32);
$token = ActaAutologin::tokenFor($secret, '1508');
$check(strlen($token) === 64 && $token !== $secret, 'El token firmado no expone el secreto.');
$check(ActaAutologin::valid($secret, '1508', $token), 'El token firmado abre la sesión de su funcionario.');
$check(!ActaAutologin::valid($secret, '2999', $token), 'El token de un funcionario no sirve para otro.');
$check(ActaAutologin::valid($secret, '2999', $secret), 'Los enlaces ya publicados con el secreto siguen funcionando.');
$check(!ActaAutologin::valid('', '1508', $token) && !ActaAutologin::valid($secret, '', $token) && !ActaAutologin::valid($secret, '1508', ''), 'Sin secreto, funcionario o token no hay autologin.');
$link = ActaAutologin::link($secret, 'https://x.test/puente-funcionario.php?accion=crear_cotizacion&ticket_pk=10', '1508');
$check($link === 'https://x.test/puente-funcionario.php?accion=crear_cotizacion&ticket_pk=10&id_empleado=1508&token=' . $token, 'El enlace agrega id_empleado y token firmado.');
$check(!str_contains($link, $secret), 'El enlace generado nunca incluye el secreto.');
foreach (['public/crear-acta.php', 'public/puente-funcionario.php'] as $page) {
  $source = (string) file_get_contents(dirname(__DIR__) . '/' . $page);
  $check(str_contains($source, 'ActaAutologin::valid($secret, $employeeId, $token)'), $page . ' debe validar con ActaAutologin.');
}
$runtime = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin-dashboard-runtime.js');
$check(!preg_match('/[0-9a-f]{64}/', $runtime), 'El JavaScript del panel no contiene secretos.');
$check(str_contains($runtime, 'scm_autologin_link') && str_contains($runtime, 'runtime.dashboardPermissions.canManage'), 'El generador solo aparece para administradores de permisos.');

echo "Acta autologin checks passed.\n";
