<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

use SCM\Support\SimplePdf;

final class CompletionPdf
{
  public function render(array $act, array $payload, bool $staff = false): string
  {
    $pdf = new SimplePdf();
    $actor = CompletionView::actor($payload);
    $status = $act['status'] === 'signed'
      ? 'Firmada - Caso cerrado'
      : ($act['status'] === 'archived' ? 'Archivada - No válida para firma' : ($act['status'] === 'cancelled' ? 'Anulada - No válida para firma' : 'Acta sin firmar - Caso abierto'));
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - NIT 900623242-4');
    $pdf->actaHeader(
      'Acta de satisfacción del caso #' . $payload['ticket_number'],
      'Registro interno #' . $act['id'] . ' - Solución de daños',
      $status
    );
    $pdf->sectionTitle('Datos del servicio');
    $pdf->detailGrid([
      ['Inmueble / contrato', $payload['property'] . ' / ' . $payload['contract']],
      ['Dirección', $payload['address']],
      ['Solución realizada por', CompletionPolicy::EXECUTORS[$payload['executor']]],
      ['Fecha del acta', date('d/m/Y H:i', (int) $payload['created_at']) . ' (Colombia)'],
      ['Firmante seleccionado', $payload['signer']['name'] . ' - ' . CompletionPolicy::ROLES[$payload['signer']['role']]],
    ]);
    $pdf->sectionTitle('Daños encontrados y soluciones realizadas');
    foreach ($payload['items'] as $index => $item) {
      $damage = $this->chunks($item['damage'], 500); $solution = $this->chunks($item['solution'], 500);
      $pdf->serviceCard($index + 1, implode(' ', $damage), implode(' ', $solution));
      if (empty($item['photos'])) { continue; }
      $pdf->sectionTitle('Evidencias del daño #' . ($index + 1));
      foreach ($item['photos'] as $photoIndex => $photo) {
        $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . basename((string) $photo['name']);
        $hash = is_file($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals((string) $photo['sha256'], $hash) || !$pdf->imageEvidence($path, 'Foto ' . ($photoIndex + 1) . ' del daño #' . ($index + 1))) {
          throw new \DomainException('Una evidencia fotográfica del acta no está disponible o cambió.');
        }
      }
    }
    $pdf->sectionTitle('Observaciones');
    foreach ($this->chunks($payload['observations'], 900) as $chunk) { $pdf->paragraph($chunk, 9); }
    if ($act['status'] === 'signed') {
      $evidence = json_decode((string) $act['signed_json'], true, 16, JSON_THROW_ON_ERROR);
      $pdf->reserveSpace(370);
      $pdf->sectionTitle('Firma electrónica registrada');
      if (!empty($evidence['strokes'])) { $pdf->drawnSignature($evidence['strokes']); }
      $identityLine = (string) $evidence['name'] . (!empty($evidence['document']) ? ' | Documento: ' . $evidence['document'] : '');
      $pdf->paragraph($identityLine, 10);
      $pdf->paragraph('Firmada el ' . date('d/m/Y H:i:s', (int) $act['signed_at']) . ' (Colombia).', 9);
      $pdf->paragraph($evidence['consent_text'], 9);
    }
    $pdf->sectionTitle('Trazabilidad del documento');
    $pdf->signatureBlock('Elaborada por', $actor['name'], CompletionView::actorDetails($actor));
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
