<?php

declare(strict_types=1);

namespace SCM\Modules\CollectionManagement;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

final class CollectionLetterPdfGenerator
{
  /**
   * @param array<string,mixed> $item
   * @param array<string,string> $sender
   * @return array{url:string,path:string,filename:string,title:string}
   */
  public function generate(array $item, string $type, array $sender): array
  {
    $pdf = new SimplePdf();
    $pdf->backgroundImage($this->letterheadPath());
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Gestión de cartera');
    $pdf->layout(58, 166, 116);
    if ($type === 'siniestro') {
      $this->buildClaimNotice($pdf, $item, $sender);
    } else {
      $this->buildPreLegalNotice($pdf, $item, $sender);
    }

    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    $pdf->save($path);
    $files = StoredFileService::fromRuntime();
    $contract = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($item['contract_number'] ?? 'contrato')) ?: 'contrato';
    $title = $type === 'siniestro' ? 'Carta de siniestro' : 'Carta prejurídica';

    return [
      'url' => $files->urlFor($basename),
      'path' => $path,
      'filename' => ($type === 'siniestro' ? 'carta-siniestro-' : 'carta-prejuridica-') . $contract . '.pdf',
      'title' => $title,
    ];
  }

  /** @param array<string,mixed> $item @param array<string,string> $sender */
  private function buildPreLegalNotice(SimplePdf $pdf, array $item, array $sender): void
  {
    $tenant = $this->value($item, 'tenant_name', 'Arrendatario(a)');
    $contract = $this->value($item, 'contract_number', '-');
    $property = $this->value($item, 'property_code', '-');
    $address = $this->value($item, 'property_address', 'inmueble identificado con código ' . $property);
    $balance = $this->money($item['balance'] ?? 0);
    $cutoff = $this->date($item['source_date'] ?? '');

    $pdf->title('Gestión prejurídica de cobro');
    $pdf->line('Cartagena de Indias, ' . date('d/m/Y'), 8);
    $pdf->spacer(5);
    $pdf->line('Señor(a): ' . $tenant, 10, 'F2');
    $pdf->line('Contrato: ' . $contract . ' | Inmueble SIMI: ' . $property, 8, 'F2');
    $pdf->line('Dirección: ' . $address, 8);
    $pdf->spacer(12);
    $pdf->paragraph('Cordial saludo,');
    $pdf->paragraph('Por medio de la presente nos dirigimos a usted con el fin de llegar a una solución de pago respecto de las obligaciones pendientes derivadas del contrato de arrendamiento identificado anteriormente.');
    $pdf->paragraph('De acuerdo con el auxiliar contable cargado en nuestro sistema, con corte a ' . $cutoff . ', se registra un saldo pendiente de ' . $balance . '. Este valor debe ser validado con los comprobantes y movimientos aplicables al contrato.');
    $pdf->paragraph('Le solicitamos comunicarse con SuCasa Inmobiliaria dentro de las setenta y dos (72) horas siguientes al recibo de esta comunicación, con el fin de acreditar el pago o acordar una solución oportuna.');
    $pdf->paragraph('De no normalizarse la obligación, podrán adelantarse las actuaciones previstas en el contrato, incluido el reporte ante la aseguradora y la remisión del caso para gestión jurídica, con los costos e intereses que legal y contractualmente correspondan.');
    $pdf->callout('Importante', 'Si ya realizó el pago, envíe el soporte para que podamos validarlo y actualizar el control de cartera.');
    $pdf->paragraph('La presente comunicación corresponde a la etapa de cobro prejurídico y busca facilitar una solución directa antes de escalar el caso.');
    $this->signature($pdf, $sender);
  }

  /** @param array<string,mixed> $item @param array<string,string> $sender */
  private function buildClaimNotice(SimplePdf $pdf, array $item, array $sender): void
  {
    $this->claimPage($pdf, $item, $sender, 'arrendatario', [
      'name' => $this->value($item, 'tenant_name', 'Arrendatario(a)'),
      'role' => 'Arrendatario',
    ]);

    $landlord = trim((string) ($item['landlord_name'] ?? ''));
    if ($landlord !== '') {
      $pdf->pageBreak();
      $this->claimPage($pdf, $item, $sender, 'propietario', ['name' => $landlord, 'role' => 'Propietario']);
    }

    foreach ((array) ($item['codeudores'] ?? []) as $codeudor) {
      $name = trim((string) ($codeudor['nombre'] ?? ''));
      if ($name === '') {
        continue;
      }
      $pdf->pageBreak();
      $this->claimPage($pdf, $item, $sender, 'codeudor', ['name' => $name, 'role' => 'Codeudor']);
    }
  }

  /** @param array<string,mixed> $item @param array<string,string> $sender @param array{name:string,role:string} $recipient */
  private function claimPage(SimplePdf $pdf, array $item, array $sender, string $audience, array $recipient): void
  {
    $tenant = $this->value($item, 'tenant_name', 'Arrendatario(a)');
    $contract = $this->value($item, 'contract_number', '-');
    $property = $this->value($item, 'property_code', '-');
    $address = $this->value($item, 'property_address', 'inmueble identificado con código ' . $property);
    $balance = $this->money($item['balance'] ?? 0);
    $cutoff = $this->date($item['source_date'] ?? '');

    $pdf->title('Aviso de siniestro por mora en canon de arrendamiento');
    $pdf->line('Cartagena de Indias, ' . date('d/m/Y'), 8);
    $pdf->spacer(5);
    $pdf->line('Señor(a): ' . $recipient['name'], 10, 'F2');
    $pdf->line('Calidad: ' . $recipient['role'] . ' | Contrato: ' . $contract . ' | Inmueble SIMI: ' . $property, 8, 'F2');
    $pdf->line('Dirección: ' . $address, 8);
    $pdf->spacer(12);

    if ($audience === 'arrendatario') {
      $pdf->paragraph('Por medio de la presente le comunicamos que, debido a la mora registrada en el contrato de arrendamiento, el caso ha sido marcado como siniestro para su trámite ante la aseguradora.');
      $pdf->paragraph('El auxiliar contable cargado en nuestro sistema registra, con corte a ' . $cutoff . ', un saldo pendiente de ' . $balance . '. A partir de la radicación del siniestro, cualquier acuerdo o instrucción de pago deberá coordinarse con la aseguradora o con el profesional jurídico asignado, según corresponda.');
      $pdf->paragraph('Hasta recibir confirmación formal de la aseguradora sobre la normalización o acuerdo de pago, no deben realizarse pagos por canales distintos de los que ésta indique.');
    } elseif ($audience === 'propietario') {
      $pdf->paragraph('Por medio de la presente le informamos que el arrendatario ' . $tenant . ', vinculado al inmueble de su propiedad, registra mora y el caso ha sido marcado como siniestro para su trámite ante la aseguradora.');
      $pdf->paragraph('El saldo contable registrado con corte a ' . $cutoff . ' es de ' . $balance . '. La aseguradora continuará el trámite conforme a las condiciones, requisitos y límites de la póliza aplicable.');
    } else {
      $pdf->paragraph('Por medio de la presente le informamos que el arrendatario ' . $tenant . ', respecto del contrato en el cual usted figura como codeudor(a), registra mora y el caso ha sido marcado como siniestro para su trámite ante la aseguradora.');
      $pdf->paragraph('El saldo contable registrado con corte a ' . $cutoff . ' es de ' . $balance . '. Le recomendamos comunicarse oportunamente con el arrendatario y con la aseguradora para conocer las alternativas de normalización.');
    }
    $pdf->paragraph('Nuestro deber es mantener informadas a las partes sobre la situación actual y dejar trazabilidad de esta comunicación.');
    $this->signature($pdf, $sender);
  }

  /** @param array<string,string> $sender */
  private function signature(SimplePdf $pdf, array $sender): void
  {
    $pdf->spacer(8);
    $pdf->signatureBlock(
      'Cordialmente',
      trim((string) ($sender['name'] ?? 'SuCasa Inmobiliaria')),
      trim((string) ($sender['cargo'] ?? 'Control Servicios Inmobiliarios'))
        . (trim((string) ($sender['phone'] ?? '')) !== '' ? ' | Cel. ' . trim((string) $sender['phone']) : '')
    );
  }

  private function money(mixed $value): string
  {
    return '$' . number_format((float) $value, 2, ',', '.') . ' M/CTE';
  }

  private function date(mixed $value): string
  {
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? date('d/m/Y') : date('d/m/Y', $timestamp);
  }

  /** @param array<string,mixed> $row */
  private function value(array $row, string $key, string $default): string
  {
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $default;
  }

  private function letterheadPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg';
  }
}
