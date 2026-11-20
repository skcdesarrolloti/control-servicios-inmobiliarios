<?php
declare(strict_types=1);

namespace SCM\Modules\Contracts;

use SCM\Support\EmailTemplate;

final class ContractRetentionEmail
{
  public static function subject(int $caseId): string
  {
    return 'Tarea comercial: Retención de contrato · Tarea #' . $caseId;
  }

  /** Correo del cliente: sin las instrucciones internas, el canon ni el contexto de la gestión. */
  public static function clientSubject(int $caseId): string
  {
    return 'Tu tarea comercial #' . $caseId . ' · SKC SuCasa Inmobiliaria';
  }

  public static function clientContent(int $caseId, array $case): string
  {
    $name = trim((string) ($case['solicitante'] ?? ''));
    $contract = trim((string) ($case['contrato'] ?? ''));
    $property = trim((string) ($case['inmueble'] ?? ''));
    $consultant = trim((string) ($case['nombre_empleado'] ?? $case['empleado'] ?? ''));
    $contact = array_filter([
      trim((string) ($case['correo_empleado'] ?? '')) !== '' ? 'Correo: ' . trim((string) $case['correo_empleado']) : '',
      trim((string) ($case['celular_empleado'] ?? '')) !== '' ? 'Celular: ' . trim((string) $case['celular_empleado']) : '',
    ]);
    $html = '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Hola' . ($name !== '' ? ' <strong>' . EmailTemplate::e($name) . '</strong>' : '') . ', en SKC SuCasa Inmobiliaria creamos la tarea comercial <strong>#' . $caseId . '</strong> para acompañarte en la gestión de tu contrato'
      . ($contract !== '' ? ' <strong>' . EmailTemplate::e($contract) . '</strong>' : '') . ($property !== '' ? ' (inmueble ' . EmailTemplate::e($property) . ')' : '') . '.</p>';
    if ($consultant !== '') {
      $html .= '<p style="margin:0 0 16px;line-height:1.6;">Su consultor/a asignado es <strong>' . EmailTemplate::e($consultant) . '</strong>' . ($contact !== [] ? ' · ' . EmailTemplate::e(implode(' · ', $contact)) : '') . ', y se comunicará contigo.</p>';
    }
    $html .= '<p style="margin:0;line-height:1.6;">Desde el botón puedes ver tu tarea y escribirnos.</p>';
    $html .= '<p style="margin:20px 0 0;font-weight:700;color:#061d49;">Construimos confianza, protegemos su patrimonio.</p>';
    return $html;
  }

  public static function content(int $caseId, array $case): string
  {
    $html = '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;">Se ha creado una <strong>tarea comercial de retención</strong> en SKC SuCasa Inmobiliaria para acompañar al arrendatario y buscar alternativas que permitan conservarlo como cliente.</p>';
    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dbe4f0;background:#f8fafc;border-radius:8px;font-size:14px;line-height:1.5;table-layout:fixed;">';
    foreach ([
      'Tarea' => '#' . $caseId,
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
    $html .= '<p style="margin:0 0 16px;line-height:1.6;">Contactar al arrendatario, conocer sus necesidades y ofrecer inmuebles similares o acordes con su presupuesto. Registrar en la tarea las opciones propuestas, los acuerdos y el resultado de la gestión.</p>';
    $description = trim((string) ($case['descripcion'] ?? ''));
    if ($description !== '') {
      $html .= '<div style="padding:16px;border-left:4px solid #f59120;background:#fff8ef;color:#334155;line-height:1.65;overflow-wrap:anywhere;">';
      $html .= '<p style="margin:0 0 10px;font-weight:700;color:#061d49;">Contexto de la tarea</p>';
      // Keep paragraph/list boundaries from the sanitized case description.
      $html .= wp_kses_post($description) . '</div>';
    }
    $html .= '<p style="margin:20px 0 0;color:#64748b;font-size:14px;line-height:1.6;">Abre la tarea en el panel comercial para consultar su información, revisar el acta cuando esté disponible y registrar el seguimiento comercial.</p>';
    return $html;
  }
}
