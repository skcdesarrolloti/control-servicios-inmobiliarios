<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

use SCM\Support\HtmlPdfRenderer;

/** Constancias en PDF del caso completo o de una comunicación, con fechas, destinatarios y estados. */
final class LegalCasePdf
{
  public const DELIVERY_NOTICE = 'El estado "Aceptado por el servidor" indica que el servidor de correo o Meta recibieron el mensaje. El sistema no registra entrega, apertura, lectura ni rebote, y esta constancia no los certifica.';
  private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  private const EVENT_TITLES = [
    'caso_creado' => 'Caso creado', 'caso_editado' => 'Caso editado', 'estado_cambiado' => 'Cambio de estado', 'parte_agregada' => 'Parte agregada', 'parte_retirada' => 'Parte retirada',
    'nota' => 'Nota interna', 'respuesta_registrada' => 'Respuesta recibida', 'documento' => 'Documentos cargados', 'comunicacion_enviada' => 'Comunicación enviada', 'pdf_exportado' => 'Constancia exportada', 'recordatorio_fecha_limite' => 'Recordatorio de fecha límite',
  ];

  /** @param array<string,mixed> $detail @param list<array<string,mixed>> $communications */
  public static function caseBytes(array $detail, array $communications, string $generatedBy): string
  {
    return self::pdf(self::caseHtml($detail, $communications, $generatedBy), 'Constancia del caso ' . $detail['case']['code']);
  }

  /** @param array<string,mixed> $communication */
  public static function communicationBytes(array $communication, string $generatedBy): string
  {
    return self::pdf(self::communicationHtml($communication, $generatedBy), 'Constancia de comunicación ' . $communication['case_code']);
  }

  private static function pdf(string $html, string $title): string
  {
    $root = dirname(__DIR__, 3);
    $bytes = HtmlPdfRenderer::render($html, $title, ['stylesheets' => [$root . '/public/assets/css/legal-case-document.css'], 'external_fonts' => false, 'body_class' => 'lcd-page', 'wrapper_class' => 'lcd-root', 'container_class' => 'lcd-container', 'allow_fallback' => false]);
    if ($bytes === null) throw new \DomainException('No se pudo generar el PDF. Verifica el servicio de PDF e intenta nuevamente.');
    return $bytes;
  }

  /** @param array<string,mixed> $detail @param list<array<string,mixed>> $communications */
  public static function caseHtml(array $detail, array $communications, string $generatedBy): string
  {
    $case = $detail['case'];
    $html = self::head('Constancia del caso', $case['code'] . ' · ' . $case['name']);
    $html .= self::facts([
      'Tipo' => $case['type_name'], 'Estado' => $case['status_label'], 'Responsable' => $case['responsible_name'], 'Contrato' => $case['contract_code'] ?: 'Sin contrato',
      'Inmueble' => trim($case['property'] . ' · ' . $case['address'], ' ·') ?: 'Sin inmueble', 'Apertura' => $case['opened_date'], 'Fecha límite' => $case['due_date'] ?: 'Sin fecha', 'Próxima acción' => $case['next_action'] ?: 'Sin definir',
    ]);
    if ($case['description'] !== '') $html .= '<section class="lcd-section"><h2>Descripción</h2><p class="lcd-pre">' . self::e($case['description']) . '</p></section>';
    $rows = '';
    foreach ($detail['parties'] as $party) $rows .= '<tr><td>' . self::e($party['name']) . ($party['company'] !== '' ? '<br><small>' . self::e($party['company']) . '</small>' : '') . '</td><td>' . self::e($party['role_label']) . '</td><td>' . self::e($party['document']) . '</td><td>' . self::e($party['email']) . '</td><td>' . self::e($party['phone']) . '</td></tr>';
    if ($rows !== '') $html .= '<section class="lcd-section"><h2>Partes involucradas</h2><table><thead><tr><th>Nombre</th><th>Rol</th><th>Documento</th><th>Correo</th><th>Celular</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    $html .= '<section class="lcd-section"><h2>Línea de tiempo</h2>';
    foreach ($detail['events'] as $event) {
      $html .= '<div class="lcd-event"><strong>' . self::e(self::EVENT_TITLES[$event['action']] ?? (string) $event['action']) . '</strong><small>' . self::e(self::dateTime((int) $event['created_at'])) . ' · ' . self::e($event['actor_name']) . '</small>' . self::eventBody($event) . '</div>';
    }
    $html .= '</section>';
    if ($detail['documents'] !== []) {
      $rows = '';
      foreach ($detail['documents'] as $document) $rows .= '<tr><td>' . self::e($document['name']) . '</td><td>' . self::e(self::dateTime((int) $document['created_at'])) . '</td><td>' . self::e($document['uploaded_by']) . '</td><td class="lcd-hash">' . self::e($document['sha256']) . '</td></tr>';
      $html .= '<section class="lcd-section"><h2>Documentos</h2><table><thead><tr><th>Archivo</th><th>Fecha</th><th>Cargado por</th><th>SHA-256</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }
    foreach ($communications as $communication) $html .= self::communicationBlock($communication);
    return self::wrap($html, $generatedBy, $communications !== []);
  }

  /** @param array<string,mixed> $communication */
  public static function communicationHtml(array $communication, string $generatedBy): string
  {
    return self::wrap(self::head('Constancia de comunicación', $communication['case_code'] . ' · ' . $communication['subject']) . self::communicationBlock($communication), $generatedBy, true);
  }

  /** @param array<string,mixed> $communication */
  private static function communicationBlock(array $communication): string
  {
    $html = '<section class="lcd-section lcd-communication"><h2>' . self::e($communication['subject']) . '</h2>' . self::facts([
      'Enviada por' => $communication['sender_name'], 'Fecha y hora' => self::dateTime((int) $communication['created_at']), 'Canales' => implode(' y ', $communication['channels']), 'Respuestas a' => $communication['reply_to'] ?: 'No aplica', 'Plantilla WhatsApp' => $communication['whatsapp_template'] ?: 'No aplica',
    ]);
    $rows = '';
    $contents = [];
    foreach ($communication['recipients'] as $person) {
      $accepted = (int) $person['accepted_at'] > 0 ? self::dateTime((int) $person['accepted_at']) : '';
      $rows .= '<tr><td>' . self::e($person['name']) . ($person['kind'] === 'cc' ? ' <small>(copia)</small>' : '') . '</td><td>' . self::e($person['destination']) . '</td><td>' . self::e($person['channel_label']) . '</td><td>' . self::e($person['status_label']) . ($person['error'] !== '' ? '<br><small>' . self::e($person['error']) . '</small>' : '') . '</td><td>' . self::e($accepted) . '</td><td class="lcd-hash">' . self::e($person['provider_id']) . '</td></tr>';
      $contents[$person['html']]['names'][$person['name']] = true;
      $contents[$person['html']]['subject'] = $person['subject'];
    }
    $html .= '<h3>Destinatarios y estado</h3><table><thead><tr><th>Destinatario</th><th>Dirección</th><th>Canal</th><th>Estado</th><th>Aceptado</th><th>ID del proveedor</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    if ($communication['attachments'] !== []) {
      $rows = '';
      foreach ($communication['attachments'] as $file) $rows .= '<tr><td>' . self::e($file['name']) . '</td><td class="lcd-hash">' . self::e($file['sha256']) . '</td></tr>';
      $html .= '<h3>Adjuntos</h3><table><thead><tr><th>Archivo</th><th>SHA-256</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }
    foreach ($contents as $letter => $content) {
      $html .= '<h3>Contenido enviado a ' . self::e(implode(', ', array_keys($content['names']))) . '</h3><div class="lcd-letter"><p><strong>' . self::e($content['subject']) . '</strong></p>' . $letter . '</div>';
    }
    return $html . '</section>';
  }

  /** @param array<string,mixed> $event */
  private static function eventBody(array $event): string
  {
    $d = $event['details'];
    $text = match ((string) $event['action']) {
      'caso_creado' => 'Responsable: ' . ($d['responsable'] ?? ''),
      'caso_editado' => implode('; ', array_map(static fn(array $change): string => $change['campo'] . ': ' . ($change['antes'] ?: 'vacío') . ' → ' . ($change['despues'] ?: 'vacío'), $d['cambios'] ?? [])),
      'estado_cambiado' => ($d['antes'] ?? '') . ' → ' . ($d['despues'] ?? '') . (($d['nota'] ?? '') !== '' ? "\n" . $d['nota'] : ''),
      'parte_agregada', 'parte_retirada' => ($d['nombre'] ?? '') . ' · ' . ($d['rol'] ?? ''),
      'nota' => (string) ($d['texto'] ?? ''),
      'respuesta_registrada' => 'De ' . ($d['remitente'] ?? '') . ' · ' . ($d['canal'] ?? '') . ' · recibida el ' . ($d['fecha_recepcion'] ?? '') . (($d['asunto'] ?? '') !== '' ? "\nAsunto: " . $d['asunto'] : '') . "\n" . ($d['texto'] ?? ''),
      'documento' => implode(', ', $d['archivos'] ?? []),
      'comunicacion_enviada' => ($d['asunto'] ?? '') . ' · ' . implode(' y ', $d['canales'] ?? []) . ' · Para: ' . implode(', ', $d['destinatarios'] ?? []),
      'pdf_exportado' => (string) ($d['documento'] ?? ''),
      'recordatorio_fecha_limite' => 'Fecha límite: ' . ($d['fecha_limite'] ?? ''),
      default => '',
    };
    return $text === '' ? '' : '<p class="lcd-pre">' . self::e($text) . '</p>';
  }

  private static function head(string $kind, string $title): string
  {
    return '<header class="lcd-head"><p>SKC SuCasa Inmobiliaria · ' . self::e($kind) . '</p><h1>' . self::e($title) . '</h1></header>';
  }

  /** @param array<string,string> $facts */
  private static function facts(array $facts): string
  {
    $html = '<dl class="lcd-facts">';
    foreach ($facts as $label => $value) $html .= '<div><dt>' . self::e($label) . '</dt><dd>' . self::e((string) $value) . '</dd></div>';
    return $html . '</dl>';
  }

  private static function wrap(string $html, string $generatedBy, bool $withNotice): string
  {
    return '<article class="lcd-document">' . $html . ($withNotice ? '<p class="lcd-notice">' . self::e(self::DELIVERY_NOTICE) . '</p>' : '')
      . '<footer class="lcd-footer">Generado por ' . self::e($generatedBy) . ' el ' . self::e(self::dateTime(time())) . '</footer></article>';
  }

  private static function dateTime(int $timestamp): string
  {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Bogota'));
    return $date->format('j') . ' ' . self::MONTHS[(int) $date->format('n') - 1] . ' ' . $date->format('Y, g:i a');
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
}
