<?php
declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Support\EmailTemplate;

final class ContractRetentionEmail
{
  public static function subject(int $caseId): string
  {
    return 'Tarea comercial: Retención de contrato · Caso #' . $caseId;
  }

  public static function content(int $caseId, array $case): string
  {
    $html = '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Se ha creado una <strong>tarea comercial de retención</strong> en SKC SuCasa Inmobiliaria para acompañar al arrendatario y buscar alternativas que permitan conservarlo como cliente.</p>';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dbe4f0;background:#f8fafc;border-radius:8px;font-size:14px;line-height:1.5;table-layout:fixed;">';
    foreach ([
      'Caso' => '#' . $caseId,
      'Contrato' => (string) ($case['contrato'] ?? ''),
      'Inmueble SIMI' => (string) ($case['inmueble'] ?? ''),
      'Dirección' => (string) ($case['direccion'] ?? ''),
      'Arrendatario' => (string) ($case['arrendatario'] ?? ''),
      'Responsable comercial' => (string) ($case['nombre_empleado'] ?? $case['empleado'] ?? ''),
    ] as $label => $value) {
      if (trim($value) === '') continue;
      $html .= '<tr><td width="38%" style="padding:10px 12px;vertical-align:top;color:#64748b;overflow-wrap:anywhere;"><strong>' . EmailTemplate::e($label) . '</strong></td><td style="padding:10px 12px;vertical-align:top;color:#061d49;overflow-wrap:anywhere;">' . EmailTemplate::e($value) . '</td></tr>';
    }
    $html .= '</table>';
    $html .= '<h4 style="margin:24px 0 10px;color:#061d49;font-size:17px;">Gestión comercial solicitada</h4>';
    $html .= '<p style="margin:0 0 16px;line-height:1.6;">Contactar al arrendatario, conocer sus necesidades y ofrecer inmuebles similares o acordes con su presupuesto. Registrar en el caso las opciones propuestas, los acuerdos y el resultado de la gestión.</p>';
    $description = trim((string) ($case['descripcion'] ?? ''));
    if ($description !== '') {
      $html .= '<div style="padding:16px;border-left:4px solid #f59120;background:#fff8ef;color:#334155;line-height:1.65;overflow-wrap:anywhere;">';
      $html .= '<p style="margin:0 0 10px;font-weight:700;color:#061d49;">Contexto de la tarea</p>';
      // Keep paragraph/list boundaries from the sanitized case description.
      $html .= wp_kses_post($description) . '</div>';
    }
    $html .= '<p style="margin:20px 0 0;color:#64748b;font-size:14px;line-height:1.6;">Abre el caso para consultar su información, revisar el acta cuando esté disponible y registrar el seguimiento comercial.</p>';
    return $html;
  }
}
