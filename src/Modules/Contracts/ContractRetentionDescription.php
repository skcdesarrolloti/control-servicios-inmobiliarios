<?php
declare(strict_types=1);
namespace SCM\Modules\Contracts;

/** Brief commercial handoff; full response stays in the source case and PDF. */
final class ContractRetentionDescription
{
  public static function build(array $context): string
  {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $source = trim((string) ($context['source'] ?? 'solicitud contractual'));
    $origin = 'Origen: ' . $source;
    if (!empty($context['source_ticket'])) $origin .= ' · Ticket #' . (string) $context['source_ticket'];
    $html = '<p><strong>Gestión comercial de retención</strong></p>';
    $html .= '<p>Contactar al cliente para evaluar la renovación del contrato y registrar el resultado. Si no continúa, coordinar la búsqueda comercial para el inmueble.</p>';
    $html .= '<p>' . $escape($origin) . '</p><ul>';
    foreach (['contract' => 'Contrato', 'tenant' => 'Arrendatario', 'property' => 'Inmueble', 'address' => 'Dirección', 'end_date' => 'Fecha fin', 'status' => 'Clasificación de la solicitud'] as $key => $label) {
      $value = trim((string) ($context[$key] ?? ''));
      if ($value !== '') $html .= '<li><strong>' . $escape($label) . ':</strong> ' . $escape($value) . '</li>';
    }
    $html .= '</ul>';
    $url = trim((string) ($context['document_url'] ?? ''));
    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
      $title = trim((string) ($context['document_title'] ?? '')) ?: 'Respuesta contractual';
      $html .= '<p>Respuesta completa: <a href="' . $escape($url) . '">' . $escape($title) . '</a>. El documento también está disponible en los archivos del ticket.</p>';
    } elseif (!empty($context['source_ticket'])) {
      $html .= '<p>Consultar la respuesta completa y sus documentos en el caso de origen.</p>';
    }
    return $html;
  }
}
