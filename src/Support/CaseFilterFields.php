<?php
declare(strict_types=1);
namespace SCM\Support;

final class CaseFilterFields
{
  public static function financing(string $prefix, array $params): string
  {
    $name = htmlspecialchars($prefix . 'financiacion', ENT_QUOTES, 'UTF-8');
    $html = '<div class="scm-field"><label for="' . $name . '">Financiamiento de cotización</label><select class="select select-bordered select-sm scm-select" id="' . $name . '" name="' . $name . '">';
    foreach ([''=>'Todos','Si'=>'Sí','No'=>'No','pending'=>'Sin definir'] as $value=>$label) {
      $html .= '<option value="' . $value . '"' . (($params['fFinanciacion'] ?? '') === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div>';
  }

  public static function administrativeStatus(string $prefix, array $params, array $values): string
  {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $name = $e($prefix . 'estado_admin');
    $html = '<div class="scm-field"><label for="' . $name . '">Estado administrativo</label><select class="select select-bordered select-sm scm-select" id="' . $name . '" name="' . $name . '"><option value="">Todos</option>';
    foreach ($values as $value) $html .= '<option value="' . $e((string)$value) . '"' . (($params['fEstadoAdmin'] ?? '') === $value ? ' selected' : '') . '>' . $e((string)$value) . '</option>';
    return $html . '</select></div>';
  }
}
