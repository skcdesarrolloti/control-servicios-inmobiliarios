<?php

declare(strict_types=1);

namespace SCM\Modules\PublicServicesLiquidator;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

final class PublicServicesLiquidatorPdfGenerator
{
  /**
   * @param array<string,mixed> $context
   * @param array<string,mixed> $service
   * @param array<string,mixed> $result
   * @return array{key:string,title:string,url:string,path:string,attachment_name:string}
   */
  public function generate(array $context, array $service, array $result): array
  {
    $serviceKey = (string) ($service['key'] ?? 'servicio');
    $serviceLabel = (string) ($service['label'] ?? $serviceKey);
    $contract = $this->value($context, 'contrato', '-');
    $tenant = $this->value($context, 'arrendatario', '-');
    $owner = $this->value($context, 'propietario', '-');
    $property = $this->value($context, 'inmueble', $this->value($context, 'id_inmueble', '-'));
    $address = $this->value($context, 'direccion', '-');
    $period = $this->value($context, 'periodo', '-');
    $city = $this->value($context, 'ciudad', 'Cartagena de Indias');
    $timestamp = (int) ($context['fecha'] ?? time());

    $pdf = new SimplePdf();
    $pdf->backgroundImage(dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg');
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Orden de reembolso servicios publicos');
    $pdf->layout(58, 168, 118);

    $pdf->title('Orden de reembolso - ' . $serviceLabel);
    $pdf->line($city . ', ' . $this->longDate($timestamp), 8);
    $pdf->spacer(6);
    $pdf->detailGrid([
      ['Contrato', $contract],
      ['Inmueble', $property],
      ['Direccion', $address],
      ['Periodo', $period],
      ['Inquilino', $tenant],
      ['Propietario', $owner],
    ]);
    $pdf->amountHighlight(
      'Valor a reembolsar',
      $this->money((float) ($result['valor_reembolsar'] ?? 0)),
      'Resultado de total factura menos total a pagar por el inquilino.'
    );

    $pdf->heading('Detalle de liquidacion');
    $pdf->table(
      ['Concepto', 'Resultado'],
      [
        ['Consumo total del periodo', $this->number($result['consumo_total_periodo'] ?? null)],
        ['Consumo antes de entrega', $this->number($result['consumo_antes_entrega'] ?? null)],
        ['Consumo del inquilino', $this->number($result['consumo_inquilino'] ?? null)],
        ['Valor por unidad', $this->moneyNullable($result['valor_unidad'] ?? null)],
        ['Valor consumo antes de entrega', $this->moneyNullable($result['valor_consumo_antes_entrega'] ?? null)],
        ['Valor consumo inquilino', $this->moneyNullable($result['valor_consumo_inquilino'] ?? null)],
        ['Dias ciclo facturacion', $this->number($result['dias_ciclo'] ?? null)],
        ['Dias antes de entrega', $this->number($result['dias_antes_entrega'] ?? null)],
        ['Dias inquilino', $this->number($result['dias_inquilino'] ?? null)],
        ['Cargo fijo antes de entrega', $this->moneyNullable($result['cargo_fijo_antes_entrega'] ?? null)],
        ['Cargo fijo inquilino', $this->moneyNullable($result['cargo_fijo_inquilino'] ?? null)],
        ['Parte anterior / propietario', $this->moneyNullable($result['parte_anterior_propietario'] ?? null)],
        ['Total a pagar por el inquilino', $this->moneyNullable($result['total_pagar_inquilino'] ?? null)],
        ['Diferencia vs. total factura', $this->moneyNullable($result['diferencia_total_factura'] ?? null)],
      ],
      [1.2, 1],
      8,
      [1]
    );

    $validations = is_array($result['validaciones'] ?? null) ? $result['validaciones'] : [];
    $pdf->heading('Validaciones');
    $pdf->table(
      ['Validacion', 'Estado'],
      [
        ['Lectura inicial dentro del rango', (string) ($validations['lectura_inicial_rango'] ?? '')],
        ['Fecha de entrega dentro del ciclo', (string) ($validations['fecha_entrega_ciclo'] ?? '')],
        ['Cargos fijos prorrateados', (string) ($validations['cargos_fijos_prorrateados'] ?? '')],
        ['Cuadre con total factura', (string) ($validations['cuadre_total_factura'] ?? '')],
      ],
      [1.4, 1],
      8
    );

    $accountLines = [];
    foreach ([
      'tipo_cuenta' => 'Tipo de cuenta',
      'numero_cuenta' => 'Numero de cuenta',
      'titular_cuenta' => 'Titular',
      'cedula_titular' => 'Cedula/NIT',
    ] as $key => $label) {
      $value = trim((string) ($context[$key] ?? ''));
      if ($value !== '') {
        $accountLines[] = [$label, $value];
      }
    }
    if ($accountLines !== []) {
      $pdf->heading('Datos para consignar');
      $pdf->detailGrid($accountLines);
    }

    $pdf->paragraph('Esta orden se genera con base en las lecturas, fechas y valores registrados por el funcionario en el liquidador de servicios publicos. La validacion documental de la factura y los soportes de pago permanece a cargo del area responsable.', 8);
    $pdf->signatureBlock(
      'Orden generada por',
      $this->value($context, 'realizado_por', 'Control Servicios Inmobiliarios'),
      $this->signatureDetails([
        $this->value($context, 'realizado_por_cargo', ''),
        $this->value($context, 'realizado_por_telefono', ''),
        $this->value($context, 'realizado_por_correo', ''),
      ])
    );

    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    $pdf->save($path);

    $attachment = 'orden-reembolso-' . $this->slug($serviceLabel) . '-contrato-' . $this->slug($contract) . '.pdf';
    return [
      'key' => $serviceKey,
      'title' => 'Orden de reembolso - ' . $serviceLabel,
      'url' => StoredFileService::fromRuntime()->urlFor($basename),
      'path' => $path,
      'attachment_name' => $attachment,
    ];
  }

  /** @param string[] $parts */
  private function signatureDetails(array $parts): string
  {
    $clean = [];
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part !== '') {
        $clean[] = $part;
      }
    }
    return implode(' | ', array_values(array_unique($clean)));
  }

  private function longDate(int $timestamp): string
  {
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return date('j', $timestamp) . ' de ' . ($months[(int) date('n', $timestamp)] ?? date('m', $timestamp)) . ' de ' . date('Y', $timestamp);
  }

  private function money(float $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  /** @param mixed $value */
  private function moneyNullable($value): string
  {
    return is_numeric($value) ? $this->money((float) $value) : '-';
  }

  /** @param mixed $value */
  private function number($value): string
  {
    return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') : '-';
  }

  /** @param array<string,mixed> $row */
  private function value(array $row, string $key, string $default): string
  {
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $default;
  }

  private function slug(string $value): string
  {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii !== false ? $ascii : $value) ?? '';
    return strtolower(trim($slug, '-')) ?: 'documento';
  }
}
