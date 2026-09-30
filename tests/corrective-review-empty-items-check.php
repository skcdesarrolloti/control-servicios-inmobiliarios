<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');

$appClass = new ReflectionClass(\SCM\App\SuCasaControlServiciosInmobiliarios::class);
$app = $appClass->newInstanceWithoutConstructor();
$storedItems = new ReflectionMethod($app, 'correctiveReviewStoredItems');
$valid = ['indice' => 'Elementos arquitectónicos', 'descripcion_dano' => 'Fisura'];
$blank = ['indice' => '', 'area_afectada_1' => '', 'descripcion_dano' => '', 'registro_foto_dano' => ''];
$defaultsOnly = ['indice' => 'Elementos arquitectónicos', 'nivel_dano' => 'Leve', 'tiempo_atencion' => '2 dias'];
$photoOnly = ['registro_foto_dano' => '42'];
$items = $storedItems->invoke($app, serialize([$valid, $blank, $defaultsOnly, $photoOnly]));
if (count($items) !== 2 || $items[0] !== $valid || $items[1] !== $photoOnly) {
  throw new RuntimeException('La edición incluyó un daño vacío o descartó una evidencia válida.');
}
echo "Corrective review empty item checks passed.\n";
