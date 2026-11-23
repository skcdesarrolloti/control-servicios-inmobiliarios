<?php

declare(strict_types=1);

// El responsable de la cotización es el coordinador (quien la envía), con sus datos; "Elaboró la cotización" es el creador.
require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  echo "OK: {$message}\n";
};

$app = (new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
$contact = new ReflectionMethod($app, 'cotizacion_responsable_contact');
$row = ['creador' => 'Alvaro Florez De Oro', 'email_creador' => 'creador@example.invalid', 'celular_creador' => '3000000001',
  'coordinador' => 'Elsie Johana Perez Kelly', 'email_coordinador' => 'coordina@example.invalid', 'celular_coordinador' => '3145965046', 'cargo_coordinador' => 'Coordinadora de mantenimiento'];
$responsible = $contact->invoke($app, $row);
$check($responsible === ['nombre' => 'Elsie Johana Perez Kelly', 'cargo' => 'Coordinadora de mantenimiento', 'email' => 'coordina@example.invalid', 'celular' => '3145965046'], 'the responsible person is the coordinator who sent the quote, with their own contact');
$partial = $contact->invoke($app, ['coordinador' => 'Elsie Johana Perez Kelly'] + array_diff_key($row, ['email_coordinador' => 1, 'celular_coordinador' => 1, 'cargo_coordinador' => 1]));
$check($partial['nombre'] === 'Elsie Johana Perez Kelly' && $partial['email'] === '' && $partial['celular'] === '', 'the coordinator is never mixed with the creator contact');
$fallback = $contact->invoke($app, array_diff_key($row, ['coordinador' => 1]));
$check($fallback['nombre'] === 'Alvaro Florez De Oro' && $fallback['email'] === 'creador@example.invalid', 'without coordinator the creator is the responsible person');

$view = (new ReflectionMethod($app, 'render_native_cotizacion_mantenimiento_view'))->invoke($app, ['_ID' => '608', 'total' => '100'] + $row, []);
$check(preg_match('/<span>Responsable de cotización<\/span><strong>Elsie Johana Perez Kelly<\/strong><p>Coordinadora de mantenimiento<\/p>/u', $view) === 1, 'the quote document shows the coordinator as responsible');
$check(preg_match('/<span>Elaboró la cotización<\/span><strong>Alvaro Florez De Oro<\/strong>/u', $view) === 1, 'the quote document still shows who made the quote');

$send = (string) file_get_contents(dirname(__DIR__) . '/src/App/Concerns/HandlesMaintenanceActions.php');
$check(str_contains($send, '$documentRow = array_merge($quote, $senderFields, ['), 'the PDF sent with the quote is built with the sender as coordinator');
$check(substr_count($send, "'cargo_coordinador' => trim((string) (\$actor['cargo'] ?? ''))") === 2, 'saving and sending store the coordinator position');

echo "Quote responsible checks passed.\n";
