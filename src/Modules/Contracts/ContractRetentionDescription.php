<?php
declare(strict_types=1);
namespace SCM\Modules\Contracts;

/** Brief commercial handoff; full response stays in the source case and PDF. */
final class ContractRetentionDescription
{
  /** Contractual rent takes precedence over advertised property prices. */
  public static function propertyContext(array $contract, array $property, array $ticket = []): array
  {
    $first = static function(array $rows, array $keys): string {
      foreach ($rows as $row) foreach ($keys as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') return $value;
      }
      return '';
    };
    $canon = ContractRenewalService::canon($first([$contract, $ticket], ['valor_canon']));
    return [
      'property_type' => $first([$property, $contract, $ticket], ['tipo_inmueble']),
      'property_use' => $first([$contract], ['destinacion_inmueble']) ?: $first([$property, $ticket], ['destinacion']),
      'monthly_rent' => $canon === null ? '' : '$' . number_format($canon, 0, ',', '.') . ' COP',
      'bedrooms' => $first([$property, $contract, $ticket], ['habitaciones']),
      'bathrooms' => $first([$property, $contract, $ticket], ['banos']),
      'area' => $first([$property, $contract, $ticket], ['area_construida']),
      'neighborhood' => $first([$property, $contract, $ticket], ['barrio']),
    ];
  }

  public static function build(array $context): string
  {
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $source = trim((string) ($context['source'] ?? 'solicitud contractual'));
    $origin = 'Origen: ' . $source;
    if (!empty($context['source_ticket'])) $origin .= ' · Caso #' . (string) $context['source_ticket'];
    $html = '<p><strong>Gestión comercial de retención</strong></p>';
    $html .= '<p>Se crea este caso para retener al cliente en SKC SuCasa Inmobiliaria.</p>';
    $html .= '<p>El cliente actualmente ocupa el inmueble con las siguientes características:</p><ul>';
    foreach (['property_type' => 'Tipo de inmueble', 'property_use' => 'Destinación', 'monthly_rent' => 'Canon mensual', 'bedrooms' => 'Habitaciones', 'bathrooms' => 'Baños', 'area' => 'Área construida (m²)', 'neighborhood' => 'Barrio'] as $key => $label) {
      $value = trim((string) ($context[$key] ?? ''));
      $html .= '<li><strong>' . $escape($label) . ':</strong> ' . $escape($value !== '' ? $value : 'Sin registrar') . '</li>';
    }
    $html .= '</ul><p>Por favor, atender al arrendatario y buscar opciones de inmuebles similares o que se ajusten a sus necesidades y presupuesto, para evitar su salida de nuestra empresa. Contactarlo y registrar las opciones ofrecidas y el resultado de la gestión.</p>';
    $html .= '<p>' . $escape($origin) . '</p><ul>';
    foreach (['contract' => 'Contrato', 'tenant' => 'Arrendatario', 'property' => 'Inmueble', 'address' => 'Dirección', 'end_date' => 'Fecha fin', 'status' => 'Clasificación de la solicitud'] as $key => $label) {
      $value = trim((string) ($context[$key] ?? ''));
      if ($value !== '') $html .= '<li><strong>' . $escape($label) . ':</strong> ' . $escape($value) . '</li>';
    }
    $html .= '</ul>';
    $url = trim((string) ($context['document_url'] ?? ''));
    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
      $title = trim((string) ($context['document_title'] ?? '')) ?: 'Respuesta contractual';
      $html .= '<p>Respuesta completa: <a href="' . $escape($url) . '">' . $escape($title) . '</a>. El documento también está disponible en los archivos del caso.</p>';
    } elseif (!empty($context['source_ticket'])) {
      $html .= '<p>Consultar la respuesta completa y sus documentos en el caso de origen.</p>';
    }
    return $html;
  }
}
