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
    $pdf->title('Acta de satisfacción - Ticket #' . $payload['ticket_number']);
    $pdf->callout('Ticket #' . $payload['ticket_number'], $act['status'] === 'signed' ? 'FIRMADA - Cierre registrado al firmar' : ($act['status'] === 'archived' ? 'ARCHIVADA - No válida para firma' : ($act['status'] === 'cancelled' ? 'ANULADA - No válida para firma' : 'PENDIENTE DE FIRMA - Este documento no cierra el ticket')));
    $pdf->heading('Datos del servicio');
    $pdf->table(['Dato', 'Detalle'], [
      ['Inmueble / contrato', $payload['property'] . ' / ' . $payload['contract']],
      ['Dirección', $payload['address']], ['Solución realizada por', CompletionPolicy::EXECUTORS[$payload['executor']]],
      ['Fecha del acta', date('d/m/Y H:i', (int) $payload['created_at']) . ' (Colombia)'],
      ['Firmante', $payload['signer']['name'] . ' - ' . CompletionPolicy::ROLES[$payload['signer']['role']]],
    ], [1, 3], 9);
    $pdf->heading('Daños encontrados y soluciones realizadas');
    foreach ($payload['items'] as $index => $item) {
      $pdf->heading('Daño y solución #' . ($index + 1));
      $damage = $this->chunks($item['damage'], 500); $solution = $this->chunks($item['solution'], 500);
      $rows = [];
      for ($i = 0; $i < max(count($damage), count($solution)); $i++) {
        $rows[] = [$i === 0 ? 'Daño encontrado' : 'Daño encontrado cont.', $damage[$i] ?? ''];
        $rows[] = [$i === 0 ? 'Solución realizada' : 'Solución realizada cont.', $solution[$i] ?? ''];
      }
      $pdf->table(['Detalle', 'Descripción'], $rows, [1.25, 4.75], 9);
      if (empty($item['photos'])) { continue; }
      $pdf->paragraph('Evidencias del daño #' . ($index + 1), 9);
      foreach ($item['photos'] as $photoIndex => $photo) {
        $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename((string) $photo['name']);
        $hash = is_file($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals((string) $photo['sha256'], $hash) || !$pdf->image($path, 420, 260)) {
          throw new \DomainException('Una evidencia fotográfica del acta no está disponible o cambió.');
        }
        $pdf->paragraph('Foto ' . ($photoIndex + 1) . ' del daño #' . ($index + 1), 8);
      }
    }
    $pdf->heading('Observaciones');
    foreach ($this->chunks($payload['observations'], 900) as $chunk) { $pdf->paragraph($chunk, 9); }
    if ($act['status'] === 'signed') {
      $evidence = json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR);
      $pdf->reserveSpace(370);
      $pdf->heading('Firma electrónica registrada');
      if (!empty($evidence['strokes'])) { $pdf->drawnSignature($evidence['strokes']); }
      $identityLine = (string) $evidence['name'] . (!empty($evidence['document']) ? ' | Documento: ' . $evidence['document'] : '');
      $pdf->paragraph($identityLine, 10);
      $pdf->paragraph('Firmada el ' . date('d/m/Y H:i:s', (int) $act['signed_at']) . ' (Colombia). ' . (!empty($evidence['verification']) ? 'Nombre confirmado y contacto verificado por código vía ' . $evidence['verification']['channel'] . '.' : 'Firma histórica mediante nombre escrito y aceptación.'), 9);
      $pdf->paragraph($evidence['consent_text'], 9);
      $pdf->paragraph('Firma electrónica; no corresponde a una firma digital certificada. El código verifica acceso al contacto registrado.', 8);
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
