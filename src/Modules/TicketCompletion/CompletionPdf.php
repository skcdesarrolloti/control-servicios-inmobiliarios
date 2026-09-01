<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\SimplePdf;

final class CompletionPdf
{
  public function render(array $act, array $payload, bool $staff = false): string
  {
    $pdf = new SimplePdf();
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - NIT 900623242-4');
    $pdf->line('SKC SuCasa Inmobiliaria | NIT 900623242-4', 10, 'F2');
    $pdf->title('Acta de satisfacción #' . $act['id']);
    $pdf->callout('Ticket #' . $payload['ticket_number'], $act['status'] === 'signed' ? 'FIRMADA - Cierre registrado al firmar' : ($act['status'] === 'cancelled' ? 'ANULADA - No válida para firma' : 'PENDIENTE DE FIRMA - Este documento no cierra el ticket'));
    $pdf->heading('Datos del servicio');
    $pdf->table(['Dato', 'Detalle'], [
      ['Inmueble / contrato', $payload['property'] . ' / ' . $payload['contract']],
      ['Dirección', $payload['address']], ['Solución realizada por', CompletionPolicy::ROLES[$payload['executor']]],
      ['Fecha del acta', date('d/m/Y H:i', (int) $payload['created_at']) . ' (Colombia)'],
      ['Firmante', $payload['signer']['name'] . ' - ' . CompletionPolicy::ROLES[$payload['signer']['role']]],
    ], [1, 3], 9);
    $pdf->heading('Daños encontrados y soluciones realizadas');
    $rows = [];
    foreach ($payload['items'] as $index => $item) {
      // Bound each physical row so 3000-character entries can span pages with repeated headers.
      $damage = $this->chunks($item['damage'], 500); $solution = $this->chunks($item['solution'], 500);
      for ($i = 0; $i < max(count($damage), count($solution)); $i++) {
        $rows[] = [$i === 0 ? (string) ($index + 1) : ($index + 1) . ' cont.', $damage[$i] ?? '', $solution[$i] ?? ''];
      }
    }
    $pdf->table(['#', 'Daño encontrado', 'Solución realizada'], $rows, [.45, 2.7, 2.7], 9);
    foreach ($payload['items'] as $index => $item) {
      if (empty($item['photos'])) { continue; }
      $pdf->heading('Evidencias del daño #' . ($index + 1));
      foreach ($item['photos'] as $photoIndex => $photo) {
        $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename((string) $photo['name']);
        $hash = is_file($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals((string) $photo['sha256'], $hash) || !$pdf->image($path, 360, 250)) {
          throw new \DomainException('Una evidencia fotográfica del acta no está disponible o cambió.');
        }
        $pdf->paragraph('Foto ' . ($photoIndex + 1) . ' del daño #' . ($index + 1), 8);
      }
    }
    $pdf->heading('Observaciones');
    foreach ($this->chunks($payload['observations'], 900) as $chunk) { $pdf->paragraph($chunk, 9); }
    if ($staff) {
      $pdf->heading('Reporte administrativo - Uso interno');
      $pdf->table(['Concepto', 'Valor'], [
        ['Servicio administrativo', CompletionView::money((int) $payload['report']['service_fee'])],
        ['Transporte', CompletionView::money((int) $payload['report']['transport'])],
        ['Total', CompletionView::money((int) $payload['report']['total'])],
      ], [3, 1], 9, [1]);
    }
    if ($act['status'] === 'signed') {
      $evidence = json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR);
      $pdf->reserveSpace(370);
      $pdf->heading('Firma electrónica registrada');
      if (!empty($evidence['strokes'])) { $pdf->drawnSignature($evidence['strokes']); }
      $pdf->paragraph($evidence['name'] . ' | Documento: ' . $evidence['document'], 10);
      $pdf->paragraph('Firmada el ' . date('d/m/Y H:i:s', (int) $act['signed_at']) . ' (Colombia). ' . (!empty($evidence['verification']) ? 'Contacto verificado por código vía ' . $evidence['verification']['channel'] . '.' : 'Firma histórica mediante nombre escrito y aceptación.'), 9);
      $pdf->paragraph($evidence['consent_text'], 9);
      $pdf->paragraph('Firma electrónica; no corresponde a una firma digital certificada. El código verifica acceso al contacto, no la identidad documental.', 8);
    }
    $pdf->heading('Trazabilidad del documento');
    $pdf->paragraph('Preparada por ' . $payload['actor']['name'], 9);
    $pdf->paragraph('Identificador de contenido SHA-256: ' . $act['payload_hash'], 8);
    return $pdf->bytes();
  }

  private function chunks(string $text, int $limit): array
  {
    $out = [];
    foreach (explode("\n", str_replace("\r", '', $text)) as $paragraph) {
      while (mb_strlen($paragraph) > $limit) {
        $part = mb_substr($paragraph, 0, $limit);
        $lastSpace = mb_strrpos($part, ' ');
        $length = $lastSpace !== false && $lastSpace > $limit / 2 ? $lastSpace : $limit;
        $out[] = trim(mb_substr($paragraph, 0, $length));
        $paragraph = ltrim(mb_substr($paragraph, $length));
      }
      $out[] = $paragraph;
    }
    return $out;
  }
}
