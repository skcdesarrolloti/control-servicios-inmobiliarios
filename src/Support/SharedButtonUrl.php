<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Botón de las plantillas de WhatsApp que este panel comparte con control-servicios-comerciales
 * (pqr_trasladada_funcionario_v1, scm_cita_funcionario_v1, scm_cita_solicitante_v1).
 *
 * En Meta su URL fija es solo el dominio, `https://sucasainmobiliaria.com.co/{{1}}`, y cada panel completa el resto con su
 * propia dirección: aquí `control-servicios-inmobiliarios/public/?scm_case=...` y en el comercial `dashboard-comercial/public/...`.
 * Copia de control-servicios-comerciales/src/Support/SharedButtonUrl.php: mantener ambas iguales.
 */
final class SharedButtonUrl
{
  public const ROOT = 'https://sucasainmobiliaria.com.co/';

  /** Lo que va en {{1}}: la dirección completa sin el dominio fijo de la plantilla. */
  public static function suffix(string $url): string
  {
    if (str_starts_with($url, self::ROOT)) {
      return substr($url, strlen(self::ROOT));
    }
    // Fuera del dominio de producción (pruebas, local): ruta y consulta, sin la barra inicial.
    $parts = parse_url($url);
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
  }

  /** Enlace del caso para el botón: el del funcionario (inicia su sesión) o, sin funcionario, el público de esa audiencia. */
  public static function caseSuffix(int $caseId, string $employeeId = '', string $audience = 'publico'): string
  {
    if ($caseId < 1) {
      // Meta no acepta el botón vacío: sin caso, el panel.
      return self::suffix(rtrim((string) SCM_BASE_URL, '/') . '/');
    }
    return self::suffix($employeeId !== '' ? PublicCaseAccess::staffUrl($caseId, $employeeId) : PublicCaseAccess::url($caseId, $audience));
  }
}
