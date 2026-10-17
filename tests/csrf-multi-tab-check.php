<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SCM\Core\Csrf;

$check = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$_SESSION = [];
$csrf = new Csrf(str_repeat('s', 40));
$tabA = $csrf->token('scm_nonce');
$tabB = $csrf->token('scm_nonce'); // otra pestaña del panel
$acta = $csrf->token('scm_nonce'); // crear-acta.php o revision.php
$check($csrf->verify('scm_nonce', $tabA, false), 'La primera pestaña sigue funcionando después de abrir otras.');
$check($csrf->verify('scm_nonce', $tabB, false) && $csrf->verify('scm_nonce', $acta, false), 'Todas las pestañas abiertas son válidas.');
$check($csrf->verify('scm_nonce', $tabA, false), 'Los nonces AJAX se pueden usar muchas veces.');
$check(!$csrf->verify('scm_nonce', 'x|y', false) && !$csrf->verify('scm_nonce', '', false) && !$csrf->verify('otra', $tabA, false), 'Tokens inválidos o de otra acción se rechazan.');
$check($_SESSION['scm_csrf']['scm_nonce'] === $acta, 'scm_csrf conserva el último token para las páginas que lo reutilizan.');

$form = $csrf->token('form-x');
$check($csrf->verify('form-x', $form) && !$csrf->verify('form-x', $form), 'Un token de formulario solo sirve una vez.');
$old = $csrf->token('form-y');
$new = $csrf->token('form-y');
$check($csrf->verify('form-y', $old) && $csrf->verify('form-y', $new), 'Dos formularios abiertos de la misma acción se pueden enviar.');

for ($i = 0; $i < 40; $i++) {
  $last = $csrf->token('scm_nonce');
}
$check(!$csrf->verify('scm_nonce', $tabA, false) && $csrf->verify('scm_nonce', $last, false), 'Solo se conservan los tokens más recientes.');

$_SESSION = ['scm_csrf' => ['legacy' => 'abc|def']];
$check($csrf->verify('legacy', 'abc|def', false), 'Los tokens guardados antes del cambio siguen funcionando.');

echo "CSRF multi-tab checks passed.\n";
