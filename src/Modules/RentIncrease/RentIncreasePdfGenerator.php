<?php

declare(strict_types=1);

namespace SCM\Modules\RentIncrease;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

final class RentIncreasePdfGenerator
{
  /** @param array<string,mixed> $context @return array{url:string,path:string,name:string,attachment_name:string} */
  public function generate(string $type, array $context): array
  {
    $pdf = new SimplePdf();
    $pdf->backgroundImage(dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg');
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Carta de aumento');
    $pdf->layout(58, 168, 112);

    $isCanon = $type === 'canon';
    $title = $isCanon ? 'Carta de aumento de canon' : 'Carta de aumento de administración';
    $city = $this->value($context, 'ciudad', 'Cartagena de Indias');
    $date = (int) ($context['fecha_ts'] ?? time());
    $tenant = $this->value($context, 'arrendatario', 'arrendatario(a)');
    $contract = $this->value($context, 'contrato', '-');
    $property = $this->value($context, 'inmueble', $this->value($context, 'id_inmueble', '-'));
    $address = $this->value($context, 'direccion', '-');
    $representative = $this->value($context, 'representante_legal', 'Representante legal');
    $representativeEmail = $this->value($context, 'correo_representante_legal', '');
    $representativePhone = $this->value($context, 'celular_representante_legal', '');
    $representativeSignature = $this->prepareSignatureImage($this->value($context, 'firma_representante_legal_path', ''));
    $contractual = $this->value($context, 'contractual', 'Coordinador Contractual, Mantenimiento y Servicios Públicos');
    $contractualEmail = $this->value($context, 'correo_contractual', '');
    $contractualPhone = $this->value($context, 'celular_contractual', '');

    $pdf->title($title);
    $pdf->line($city . ', ' . $this->longDate($date), 8);
    $pdf->spacer(8);
    $pdf->line('Apreciado(a): ' . $tenant, 10, 'F2');
    $pdf->line('Contrato: ' . $contract . ' | Inmueble: ' . $property, 8, 'F2');
    $pdf->line('Dirección: ' . $address, 8);
    $pdf->spacer(12);

    if ($isCanon) {
      $pdf->heading('REF: AUMENTO DE CANON DE ARRENDAMIENTO');
      $pdf->paragraph('Por medio de la presente nos permitimos comunicarle que el canon de arrendamiento mensual del inmueble que usted ocupa en calidad de arrendatario será incrementado en ' . $this->value($context, 'incremento', '-') . ', es decir, que el nuevo valor a cancelar por concepto de canon será de ' . $this->money((float) ($context['canon'] ?? 0)) . ' (' . $this->value($context, 'canon_letras', '-') . ').', 8);
      $pdf->paragraph('El aumento en el canon se aplicará conforme a la ley y al término estipulado en el contrato de arrendamiento.', 8);
      $pdf->paragraph('El término de vigencia del contrato ha sido renovado por el mismo término estipulado en el contrato de arrendamiento.', 8);
    } else {
      $pdf->heading('REF: AUMENTO CUOTA ADMINISTRACIÓN');
      $pdf->paragraph('Por medio de la presente nos permitimos comunicarle que la nueva cuota de administración mensual del inmueble que usted ocupa en calidad de arrendatario será de ' . $this->money((float) ($context['administracion'] ?? 0)) . ' (' . $this->value($context, 'administracion_letras', '-') . '), valor que comenzará a regir a partir del ' . $this->dateLabel((int) ($context['vigencia_ts'] ?? $date)) . '.', 8);
      $retro = trim((string) ($context['texto_retroactivos'] ?? ''));
      if ($retro !== '') {
        $pdf->paragraph($retro, 8);
      }
    }

    $pdf->paragraph('Esta carta hace parte integrante para todos los efectos legales del contrato de arrendamiento suscrito entre usted y nuestra firma.', 8);
    $pdf->paragraph('Agradecemos su atención.', 8);
    $pdf->spacer(10);
    $pdf->signatureBlock('Cordialmente', $contractual, $this->signatureDetails([$contractualPhone, $contractualEmail]));
    $pdf->signatureBlock('Representante Legal', $representative, $this->signatureDetails([$representativePhone, $representativeEmail]), $representativeSignature['path']);

    $slug = $isCanon ? 'carta-aumento-canon' : 'carta-aumento-administracion';
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    try {
      $pdf->save($path);
    } finally {
      if ($representativeSignature['temporary'] && $representativeSignature['path'] !== '') {
        @unlink($representativeSignature['path']);
      }
    }

    return [
      'url' => StoredFileService::fromRuntime()->urlFor($basename),
      'path' => $path,
      'name' => $basename,
      'attachment_name' => $slug . '-contrato-' . $this->slug($contract) . '.pdf',
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

  private function dateLabel(int $timestamp): string
  {
    return $timestamp > 0 ? date('d/m/Y', $timestamp) : '-';
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

  /** @return array{path:string,temporary:bool} */
  private function prepareSignatureImage(string $path): array
  {
    $path = trim($path);
    if ($path === '' || !is_file($path)) {
      return ['path' => '', 'temporary' => false];
    }
    $info = @getimagesize($path);
    if (!is_array($info)) {
      return ['path' => '', 'temporary' => false];
    }
    if ((string) ($info['mime'] ?? '') === 'image/jpeg') {
      return ['path' => $path, 'temporary' => false];
    }
    if (!extension_loaded('gd')) {
      return ['path' => '', 'temporary' => false];
    }
    $source = match ((string) ($info['mime'] ?? '')) {
      'image/png' => @imagecreatefrompng($path),
      'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
      'image/gif' => @imagecreatefromgif($path),
      default => false,
    };
    if (!$source) {
      return ['path' => '', 'temporary' => false];
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
      imagedestroy($source);
      return ['path' => '', 'temporary' => false];
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);
    $tmp = tempnam(sys_get_temp_dir(), 'scm_signature_');
    if (!is_string($tmp) || $tmp === '') {
      imagedestroy($canvas);
      return ['path' => '', 'temporary' => false];
    }
    $jpg = $tmp . '.jpg';
    $ok = imagejpeg($canvas, $jpg, 92);
    imagedestroy($canvas);
    @unlink($tmp);
    return $ok && is_file($jpg) ? ['path' => $jpg, 'temporary' => true] : ['path' => '', 'temporary' => false];
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
    return trim($slug, '-') ?: 'sin-contrato';
  }
}
