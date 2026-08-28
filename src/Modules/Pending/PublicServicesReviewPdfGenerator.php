<?php

declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

final class PublicServicesReviewPdfGenerator
{
  /**
   * @param array<string,mixed> $context
   * @param array<string,array<string,mixed>> $services
   * @return array<string,array{key:string,title:string,url:string,path:string,attachment_name:string}>
   */
  public function generate(array $context, array $services): array
  {
    $documents = [];
    try {
      foreach ($services as $serviceKey => $service) {
        $isCurrent = $this->normalizeStatus((string) ($service['status'] ?? '')) === 'al dia';
        $field = $this->documentField($serviceKey, $isCurrent);
        if ($field === '') {
          continue;
        }

        $kind = $isCurrent ? 'felicitaciones' : 'mora';
        $serviceLabel = (string) ($service['label'] ?? $serviceKey);
        $tenantSlug = $this->slug((string) ($context['arrendatario'] ?? 'arrendatario'));
        $attachmentName = 'Acta-de-' . $kind . '-servicio-de-' . $this->slug($serviceLabel) . '-' . $tenantSlug . '.pdf';
        $title = $isCurrent
          ? 'Acta de reconocimiento - servicio de ' . $serviceLabel
          : 'Acta de mora - servicio de ' . $serviceLabel;

        $documents[$field] = $this->save(
          $this->build($context, $service, $isCurrent),
          $field,
          $title,
          $attachmentName
        );
      }
    } catch (\Throwable $exception) {
      foreach ($documents as $document) {
        $path = trim($document['path']);
        if ($path !== '' && is_file($path)) {
          @unlink($path);
        }
      }
      throw $exception;
    }

    return $documents;
  }

  /** @param array<string,mixed> $context @param array<string,mixed> $service */
  private function build(array $context, array $service, bool $isCurrent): SimplePdf
  {
    $pdf = new SimplePdf();
    $pdf->backgroundImage($this->letterheadPath());
    $pdf->layout(58, 168, 118);

    $serviceLabel = (string) ($service['label'] ?? 'servicio publico');
    $status = (string) ($service['status'] ?? '');
    $statusLabel = $this->statusLabel($status);
    $accountLabel = (string) ($service['account_label'] ?? 'Cuenta');
    $account = (string) ($service['account'] ?? '-');
    $meter = (string) ($service['meter'] ?? '-');
    $amount = max(0, (int) ($service['amount'] ?? 0));
    $tenant = $this->value($context, 'arrendatario', 'arrendatario(a)');
    $contract = $this->value($context, 'contrato', '-');
    $property = $this->value($context, 'inmueble', $this->value($context, 'id_inmueble', '-'));
    $address = $this->value($context, 'direccion', '-');
    $city = $this->value($context, 'ciudad', 'Cartagena de Indias');
    $timestamp = max(1, (int) ($context['fecha'] ?? time()));
    $representative = $this->value($context, 'representante_legal', 'Representante legal');
    $representativePhone = $this->value($context, 'celular_legal', '');
    $reviewer = $this->value($context, 'realizado_por', 'Control Servicios Inmobiliarios');

    $pdf->title($isCurrent ? 'Acta de reconocimiento por pago oportuno' : 'Acta de revisión con saldo pendiente');
    $pdf->line($city . ', ' . $this->longDate($timestamp), 8);
    $pdf->spacer(5);
    $pdf->line('Apreciado(a) arrendatario(a): ' . $tenant, 10, 'F2');
    $pdf->line('Contrato: ' . $contract . ' | Inmueble SIMI: ' . $property, 8, 'F2');
    $pdf->line('Dirección: ' . $address, 8);
    $pdf->spacer(10);

    $pdf->heading('Resultado de la revisión');
    $pdf->line('Servicio: ' . $serviceLabel . ' | ' . $accountLabel . ': ' . $account . ' | Medidor: ' . $meter, 8, 'F2');
    $pdf->line('Estado reportado: ' . $statusLabel . ' | Valor informado: $' . number_format($amount, 0, ',', '.') . ' COP', 8, 'F2');
    $pdf->spacer(8);

    if ($isCurrent) {
      $pdf->paragraph('La revisión periódica de servicios públicos realizada al inmueble evidenció que la cuenta del servicio de ' . $serviceLabel . ' se encuentra al día, sin saldo vencido reportado a la fecha de esta acta.');
      $pdf->paragraph('Reconocemos su responsabilidad y compromiso con el cumplimiento oportuno de las obligaciones derivadas del contrato de arrendamiento. Mantener las cuentas de servicios públicos al día contribuye al cuidado del inmueble y a una relación contractual clara y ordenada.');
      $pdf->paragraph('Agradecemos su buena gestión. Nuestro compromiso es acompañarle con información oportuna y brindar una experiencia de servicio memorable.');
    } else {
      $pdf->paragraph('La revisión periódica de servicios públicos realizada al inmueble evidenció que la cuenta del servicio de ' . $serviceLabel . ' presenta un saldo pendiente de $' . number_format($amount, 0, ',', '.') . ' COP y un estado de mora de ' . $statusLabel . '.');
      foreach ($this->moraParagraphs($status) as $paragraph) {
        $pdf->paragraph($paragraph);
      }
    }

    $pdf->spacer(6);
    $pdf->signatureBlock('Revisión registrada por', $reviewer, 'Control Servicios Inmobiliarios');
    $pdf->signatureBlock('Firma institucional', $representative, trim('Representante legal' . ($representativePhone !== '' ? ' | Cel. ' . $representativePhone : '')));

    return $pdf;
  }

  /** @return string[] */
  private function moraParagraphs(string $status): array
  {
    $normalized = $this->normalizeStatus($status);
    if ($normalized === '30 dias') {
      return [
        'Le invitamos a cancelar el valor adeudado en el menor tiempo posible. Entendemos que pueden presentarse circunstancias imprevistas; por ello, esta comunicación tiene también el carácter de recordatorio preventivo.',
        'Quedamos a su disposición para aclarar la información registrada. Una atención oportuna evita costos de reconexión y otras consecuencias asociadas a la suspensión del servicio.',
      ];
    }
    if ($normalized === '60 dias') {
      return [
        'Solicitamos realizar el pago de manera inmediata y remitir el soporte correspondiente. La permanencia de la mora constituye un incumplimiento de las obligaciones asumidas en el contrato de arrendamiento.',
        'De continuar el saldo pendiente, se informará a las personas solidariamente responsables y el caso podrá ser trasladado al área contractual para las actuaciones que correspondan.',
      ];
    }

    return [
      'El servicio presenta un estado crítico. Se requiere el pago inmediato y el envío del soporte dentro de las 48 horas siguientes a la recepción de esta comunicación.',
      'El retraso en el pago de los servicios públicos constituye un incumplimiento del contrato de arrendamiento y puede generar costos de reconexión, cobros contractuales, terminación con justa causa y traslado del caso al área jurídica.',
      'Esta situación también podrá ser informada a las personas solidariamente responsables. Nuestro propósito es facilitar una solución oportuna y evitar que la deuda o sus consecuencias aumenten.',
    ];
  }

  /** @return array{key:string,title:string,url:string,path:string,attachment_name:string} */
  private function save(SimplePdf $pdf, string $key, string $title, string $attachmentName): array
  {
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = (string) SCM_UPLOAD_PATH . '/' . $basename;
    $pdf->save($path);

    return [
      'key' => $key,
      'title' => $title,
      'url' => StoredFileService::fromRuntime()->urlFor($basename),
      'path' => $path,
      'attachment_name' => $attachmentName,
    ];
  }

  private function documentField(string $service, bool $isCurrent): string
  {
    $prefix = match ($service) {
      'energia' => 'luz',
      'agua' => 'agua',
      'gas' => 'gas',
      default => '',
    };
    if ($prefix === '') {
      return '';
    }
    return 'acta_' . ($isCurrent ? 'felicitaciones_' : 'mora_') . $prefix;
  }

  private function normalizeStatus(string $status): string
  {
    $status = mb_strtolower(trim($status), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $status);
    return preg_replace('/\s+/', ' ', $ascii !== false ? $ascii : $status) ?? $status;
  }

  private function statusLabel(string $status): string
  {
    return match ($this->normalizeStatus($status)) {
      'al dia' => 'Al día',
      '30 dias' => '30 días',
      '60 dias' => '60 días',
      'estado critico' => 'Estado crítico',
      default => $status,
    };
  }

  private function longDate(int $timestamp): string
  {
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp)] . ' de ' . date('Y', $timestamp);
  }

  private function letterheadPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg';
  }

  private function slug(string $value): string
  {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value));
    $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii !== false ? $ascii : $value) ?? '';
    return trim($slug, '-') ?: 'documento';
  }

  /** @param array<string,mixed> $row */
  private function value(array $row, string $key, string $default): string
  {
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $default;
  }
}
