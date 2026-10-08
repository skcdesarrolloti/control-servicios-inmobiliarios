<?php

declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

/** Shared letter layout for termination/non-renewal, within/after the deadline. */
final class ContractRequestPdfGenerator
{
  public const COMPANY = 'Soluciones Comerciales y Constructivas Sas.';

  public function generate(string $title, string $recordLine, string $responseText, string $creatorName, string $creatorDetails): array
  {
    if (!defined('SCM_UPLOAD_PATH')) throw new \RuntimeException('No está configurada la ruta de almacenamiento.');
    $name = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $path = rtrim((string) SCM_UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $name;
    $pdf = new SimplePdf();
    $pdf->actaDesign('');
    $membrete = defined('SCM_RESOURCES_PATH') ? SCM_RESOURCES_PATH . '/assets/membrete-sucasa.jpg' : '';
    if ($membrete !== '' && is_file($membrete)) $pdf->backgroundImage($membrete);
    $pdf->hideFooter();
    $pdf->title($title);
    $pdf->paragraph($recordLine, 8, 'F1', false);
    $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', $this->companyText($responseText)) ?: [])));
    // All four letter variants use the same greeting, including older responses.
    foreach ($paragraphs as $index => $paragraph) {
      $isRecipient = str_starts_with($paragraph, 'Señor(a):');
      $isGreeting = preg_match('/^Cordial saludo\.?$/iu', $paragraph) === 1;
      $bold = str_starts_with($paragraph, 'Cartagena de Indias D.T. y C.,') || $isRecipient || $isGreeting;
      $pdf->paragraph($paragraph, 9, $bold ? 'F2' : 'F1', false);
      if ($isRecipient && preg_match('/^Cordial saludo\.?$/iu', $paragraphs[$index + 1] ?? '') !== 1) {
        $pdf->paragraph('Cordial saludo.', 9, 'F2', false);
      }
    }
    $pdf->signatureGroup([[
      'label' => 'Atentamente', 'name' => $creatorName,
      'details' => $creatorDetails !== '' ? $this->companyText($creatorDetails) : self::COMPANY,
    ]]);
    $pdf->save($path);
    return ['title' => $title, 'url' => StoredFileService::fromRuntime()->urlFor($name), 'path' => $path];
  }

  private function companyText(string $text): string
  {
    return str_replace(['SKC SuCasa Inmobiliaria', 'SKCSuCasaInmobiliaria'], self::COMPANY, $text);
  }
}
