<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Modules\CollectionManagement\CollectionLetterPdfGenerator;
use SCM\Modules\Pending\PublicServicesReviewPdfGenerator;
use SCM\Modules\Pending\TicketPdfGenerator;
use SCM\Modules\PublicServicesLiquidator\PublicServicesLiquidatorPdfGenerator;
use SCM\Modules\RentIncrease\RentIncreasePdfGenerator;
use SCM\Modules\TicketCompletion\CompletionPdf;
use SCM\Modules\TicketCompletion\CompletionPolicy;

$out = dirname(__DIR__) . '/output/pdf/ejemplos-formatos';
if (!is_dir($out) && !mkdir($out, 0755, true) && !is_dir($out)) {
  throw new RuntimeException('No se pudo crear el directorio de salida.');
}

$files = [];
$add = static function (string $name, string $source) use ($out, &$files): void {
  $target = $out . '/' . $name . '.pdf';
  if (!is_file($source) || !copy($source, $target)) {
    throw new RuntimeException('No se pudo generar ' . $name);
  }
  if (realpath($source) !== realpath($target) && str_starts_with(realpath($source) ?: '', realpath((string) SCM_UPLOAD_PATH) ?: '\0')) {
    @unlink($source);
  }
  $files[] = basename($target);
};
$save = static function (string $name, \SCM\Support\SimplePdf $pdf) use ($out, &$files): void {
  $target = $out . '/' . $name . '.pdf';
  $pdf->save($target);
  $files[] = basename($target);
};

$date = strtotime('2026-09-30 10:00:00');
$ticket = [
  '_ID' => 1001, 'id_ticket' => 'EJEMPLO-1001', 'contrato' => 'CONTRATO-EJEMPLO-01',
  'id_inmueble' => 'INMUEBLE-EJEMPLO-01', 'inmueble' => 'INMUEBLE-EJEMPLO-01',
  'direccion' => 'Calle de Ejemplo 10, Cartagena', 'ciudad' => 'Cartagena de Indias',
  'arrendatario' => 'Arrendatario de Ejemplo', 'propietario' => 'Propietaria de Ejemplo',
  'solicitante' => 'Arrendatario de Ejemplo', 'tema_ayuda' => 'Reparación de tubería',
  'nombre_creador_ticket' => 'Funcionaria de Ejemplo', 'cargo_creador_ticket' => 'Coordinadora de servicios',
  'celular_creador_ticket' => '300 000 0000', 'correo_creador_ticket' => 'ejemplo@example.com',
  'nombre_empleado' => 'Verificador de Ejemplo', 'celular_empleado' => '300 000 0001',
  'nombre_contractual' => 'Coordinador de Ejemplo',
];
$quote = [
  '_ID' => 507, 'id_ticket' => 'EJEMPLO-1001', 'contrato' => $ticket['contrato'],
  'inmueble' => $ticket['inmueble'], 'direccion' => $ticket['direccion'],
  'destinatario' => 'Propietaria de Ejemplo', 'tipo_mantenimiento' => 'Correctivo',
  'estado' => 'Pendiente', 'fecha' => '2026-09-30', 'fecha_envio' => '2026-09-25',
  'total_materiales' => 280000, 'total_mano_obra' => 180000, 'total_admon' => 46000,
  'iva_admon' => 8740, 'total' => 514740, 'creador' => 'Funcionaria de Ejemplo',
];

$ticketGenerator = new TicketPdfGenerator();
foreach ($ticketGenerator->generate('preventiva', '', 1001, $ticket) as $key => $doc) {
  $add($key, $doc['path']);
}
foreach ($ticketGenerator->generate('', 'Recibo de inmuebles', 1001, $ticket) as $key => $doc) {
  $add($key, $doc['path']);
}
$add('comunicacion_no_acceso_preventiva', $ticketGenerator->generatePreventivaNoAccessNotice(1001, $ticket)['path']);
$add('seguimiento_reparaciones', $ticketGenerator->generateRepairFollowupNotice(1001, $ticket, $quote, 12)['path']);

$context = [
  'fecha' => $date, 'fecha_ts' => $date, 'ciudad' => 'Cartagena de Indias',
  'arrendatario' => $ticket['arrendatario'], 'propietario' => $ticket['propietario'],
  'contrato' => $ticket['contrato'], 'inmueble' => $ticket['inmueble'],
  'direccion' => $ticket['direccion'], 'representante_legal' => 'Representante de Ejemplo',
  'realizado_por' => 'Funcionaria de Ejemplo', 'realizado_por_cargo' => 'Coordinadora de servicios',
  'realizado_por_telefono' => '300 000 0000',
];
$review = new PublicServicesReviewPdfGenerator();
foreach (['energia' => 'Energía', 'agua' => 'Agua', 'gas' => 'Gas'] as $key => $label) {
  foreach (['al dia' => 'al_dia', '30 dias' => 'mora'] as $status => $suffix) {
    $docs = $review->generate($context, [$key => [
      'label' => $label, 'status' => $status, 'account_label' => 'Cuenta',
      'account' => 'CUENTA-EJEMPLO-01', 'meter' => 'MEDIDOR-EJEMPLO-01',
      'amount' => $suffix === 'mora' ? 78000 : 0,
    ]]);
    foreach ($docs as $doc) {
      $add('acta_servicio_' . $key . '_' . $suffix, $doc['path']);
    }
  }
}

$liquidator = new PublicServicesLiquidatorPdfGenerator();
foreach (['energia' => 'Energía', 'agua' => 'Agua', 'gas' => 'Gas'] as $key => $label) {
  $doc = $liquidator->generate($context + ['periodo' => 'Septiembre 2026'],
    ['key' => $key, 'label' => $label], [
      'valor_reembolsar' => 42000, 'consumo_total_periodo' => 120,
      'consumo_antes_entrega' => 40, 'consumo_inquilino' => 80,
      'valor_unidad' => 900, 'valor_consumo_antes_entrega' => 36000,
      'valor_consumo_inquilino' => 72000, 'dias_ciclo' => 30,
      'dias_antes_entrega' => 10, 'dias_inquilino' => 20,
      'cargo_fijo_antes_entrega' => 6000, 'cargo_fijo_inquilino' => 12000,
      'parte_anterior_propietario' => 42000, 'total_pagar_inquilino' => 84000,
      'diferencia_total_factura' => 0,
      'validaciones' => ['lectura_inicial_rango' => 'Correcta', 'fecha_entrega_ciclo' => 'Correcta',
        'cargos_fijos_prorrateados' => 'Correcto', 'cuadre_total_factura' => 'Correcto'],
    ]);
  $add('orden_reembolso_' . $key, $doc['path']);
}

$rent = new RentIncreasePdfGenerator();
$rentContext = $context + [
  'contractual' => 'Coordinador de Ejemplo', 'incremento' => '8 %',
  'canon' => 1350000, 'canon_letras' => 'UN MILLÓN TRESCIENTOS CINCUENTA MIL PESOS',
  'administracion' => 250000, 'administracion_letras' => 'DOSCIENTOS CINCUENTA MIL PESOS',
  'vigencia_ts' => strtotime('2026-10-01'),
];
foreach (['canon', 'administracion'] as $type) {
  $add('carta_aumento_' . $type, $rent->generate($type, $rentContext)['path']);
}

$collection = new CollectionLetterPdfGenerator();
$item = [
  'tenant_name' => $ticket['arrendatario'], 'landlord_name' => $ticket['propietario'],
  'contract_number' => $ticket['contrato'], 'property_code' => $ticket['inmueble'],
  'property_address' => $ticket['direccion'], 'balance' => 850000,
  'source_date' => '2026-09-30', 'codeudores' => [['nombre' => 'Codeudor de Ejemplo']],
];
$sender = ['name' => 'Funcionaria de Ejemplo', 'cargo' => 'Coordinadora de cartera',
  'phone' => '300 000 0000', 'email' => 'ejemplo@example.com'];
foreach (['prejuridico', 'siniestro'] as $type) {
  $add('carta_' . $type, $collection->generate($item, $type, $sender)['path']);
}

$payload = [
  'ticket_number' => 'EJEMPLO-1001', 'property' => $ticket['inmueble'],
  'contract' => $ticket['contrato'], 'address' => $ticket['direccion'],
  'executor' => 'inmobiliaria', 'created_at' => $date,
  'signer' => ['name' => 'Arrendatario de Ejemplo', 'role' => 'arrendatario'],
  'items' => [['damage' => 'Fuga de agua bajo el lavaplatos.',
    'solution' => 'Se reemplazó el acople y se comprobó el funcionamiento.',
    'damage_photos' => [], 'photos' => []]],
  'observations' => 'Documento de ejemplo. La información y las personas son ficticias.',
  'actor' => ['name' => 'Funcionaria de Ejemplo', 'cargo' => 'Coordinadora de servicios'],
];
$completion = new CompletionPdf();
foreach (['pending' => 'pendiente', 'signed' => 'firmada'] as $status => $suffix) {
  $act = ['id' => 1001, 'status' => $status, 'payload_hash' => str_repeat('a', 64),
    'signed_at' => $date, 'signed_json' => json_encode([
      'name' => 'Arrendatario de Ejemplo', 'document' => 'DOCUMENTO-EJEMPLO',
      'consent_text' => CompletionPolicy::TYPED_OTP_CONSENT,
    ], JSON_UNESCAPED_UNICODE)];
  $target = $out . '/acta_satisfaccion_' . $suffix . '.pdf';
  file_put_contents($target, $completion->render($act, $payload));
  $files[] = basename($target);
}

$app = (new ReflectionClass(SuCasaControlServiciosInmobiliarios::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($app, 'db'))->setValue($app, \SCM\Core\App::db());
$invoke = static function (string $method, array $args) use ($app): mixed {
  return (new ReflectionMethod($app, $method))->invokeArgs($app, $args);
};
foreach (['funcionario', 'destinatario'] as $audience) {
  $save('cotizacion_mantenimiento_' . $audience,
    $invoke('build_cotizacion_mantenimiento_pdf', [$quote, [], $audience]));
}
$order = [
  '_ID' => 701, 'id_cotizacion' => 507, 'id_ticket' => 'EJEMPLO-1001',
  'contrato' => $ticket['contrato'], 'inmueble' => $ticket['inmueble'],
  'direccion' => $ticket['direccion'], 'estado' => 'Pendiente', 'valor' => 514740,
  'proveedor' => 'Proveedor de Ejemplo', 'categoria' => 'Plomería',
  'actividad' => 'Reparar fuga de agua bajo lavaplatos.', 'creador' => 'Funcionaria de Ejemplo',
  'coordinador' => 'Coordinador de Ejemplo', 'autorizador' => 'Autorizador de Ejemplo',
];
$save('orden_mantenimiento_cartera', $invoke('build_cotizacion_order_pdf', [$order]));

$history = [
  'generated_at' => '30/09/2026 10:00',
  'property' => ['codigo' => $ticket['inmueble'], 'id_interno' => '1001',
    'direccion' => $ticket['direccion'], 'barrio' => 'Barrio de Ejemplo',
    'ciudad' => 'Cartagena', 'propietario' => $ticket['propietario'],
    'tipo' => 'Apartamento', 'matricula' => 'MATRÍCULA-EJEMPLO'],
  'contracts' => [['type' => 'Arrendamiento', 'number' => $ticket['contrato'],
    'status' => 'Vigente', 'tenant' => $ticket['arrendatario'], 'canon' => '$1.350.000',
    'date' => '01/10/2025']],
  'sources' => [['label' => 'Tickets', 'description' => 'Solicitudes de servicio', 'count' => 1]],
  'timeline' => [['date' => '30/09/2026', 'source' => 'Tickets',
    'title' => 'Reparación de tubería', 'reference' => 'EJEMPLO-1001']],
];
$save('reporte_historial_inmueble', $invoke('property_history_build_pdf', [$history]));

foreach (['terminacion' => 'Terminación', 'no_prorroga' => 'No prórroga'] as $group => $label) {
  foreach (['dentro' => 'dentro de término', 'fuera' => 'fuera de término'] as $term => $termLabel) {
    $response = 'Cartagena de Indias, 30/09/2026. Señor(a) Arrendatario de Ejemplo.' . "\n\n"
      . 'SKC SuCasa Inmobiliaria responde la solicitud del contrato de ejemplo. La comunicación se clasifica como '
      . $termLabel . '. Este documento contiene datos ficticios para revisión del formato.';
    $method = $group === 'terminacion' ? 'generateContractTerminationActa' : 'generateContractNonRenewalActa';
    $args = $group === 'terminacion'
      ? [$ticket, $term, $response, '2026-09-01', '2026-12-31', 'Funcionaria de Ejemplo', 'Coordinadora de servicios']
      : [$ticket, $term, $response, 'Funcionaria de Ejemplo', 'Coordinadora de servicios'];
    $add('acta_' . $group . '_' . $term, $invoke($method, $args)['path']);
  }
}

sort($files);
file_put_contents($out . '/inventario.txt', "MUESTRAS CON DATOS FICTICIOS - NO VÁLIDAS PARA TRÁMITES\n\n" . implode("\n", $files) . "\n");
fwrite(STDOUT, count($files) . " PDFs generados en {$out}\n");
