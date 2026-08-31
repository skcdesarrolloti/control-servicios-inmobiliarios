<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

final class CompletionPolicy
{
  public const WAITING = 'En espera de firma';
  public const ROLES = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'copropiedad' => 'Copropiedad'];
  public const CONSENT = 'Confirmo que soy la persona designada para firmar, revisé el acta y recibí a satisfacción las soluciones descritas. Acepto firmarla electrónicamente con mi nombre y cerrar este ticket.';

  public static function text(mixed $value, string $label, int $max = 6000, bool $required = true): string
  {
    if (!is_scalar($value) && $value !== null) {
      throw new \DomainException('El campo ' . $label . ' no es válido.');
    }
    $value = trim(strip_tags((string) $value));
    if (($required && $value === '') || mb_strlen($value) > $max) {
      throw new \DomainException('Revisa ' . $label . ': es obligatorio y admite hasta ' . $max . ' caracteres.');
    }
    return $value;
  }

  /** Accepts decimal-dot input and Colombian grouped amounts without corrupting 0.10. */
  public static function number(mixed $value): float
  {
    if (!is_scalar($value)) {
      throw new \DomainException('El valor numérico no es válido.');
    }
    $value = trim((string) $value);
    if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/D', $value)) {
      $value = str_replace('.', '', $value);
    }
    $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
      throw new \DomainException('Ingresa un valor numérico positivo válido.');
    }
    $number = (float) $value;
    if (!is_finite($number) || $number > 999999999) {
      throw new \DomainException('El valor está fuera del rango permitido.');
    }
    return $number;
  }

  public static function fee(array $config): ?int
  {
    try {
      $salary = self::number($config['salario'] ?? '0');
      $days = self::number($config['dias_trabajo'] ?? '0');
      $raw = trim((string) ($config['porcentaje_smlmv_co_pre'] ?? ''));
      if ($raw === '' || self::number($raw) === 0.0) {
        $raw = (string) ($config['porcentaje_smlmv'] ?? '0');
      }
      $pct = self::number($raw);
      $pct = $pct >= 2 ? $pct / 100 : $pct;
      return $salary > 0 && $days > 0 && $pct > 0 ? (int) round($salary / $days * $pct) : null;
    } catch (\DomainException) {
      return null;
    }
  }

  public static function items(mixed $raw): array
  {
    if (!is_array($raw) || count($raw) < 1 || count($raw) > 30) {
      throw new \DomainException('Registra entre 1 y 30 daños con su solución.');
    }
    $items = [];
    foreach ($raw as $row) {
      if (!is_array($row)) {
        throw new \DomainException('El detalle del daño no es válido.');
      }
      $items[] = [
        'damage' => self::text($row['damage'] ?? '', 'daño encontrado', 3000),
        'solution' => self::text($row['solution'] ?? '', 'solución realizada', 3000),
      ];
    }
    return $items;
  }

  public static function signature(array $input, string $expectedName): array
  {
    if (($input['accepted'] ?? '') !== '1') {
      throw new \DomainException('Debes leer y aceptar expresamente el acta para firmarla.');
    }
    $document = self::text($input['document'] ?? '', 'documento de identidad', 40);
    if (!preg_match('/^[\p{L}\p{N} .-]{4,40}$/uD', $document)) {
      throw new \DomainException('Revisa el documento de identidad.');
    }
    $name = self::text($input['signature_name'] ?? '', 'nombre de la firma', 160);
    if (mb_strtolower($name) !== mb_strtolower($expectedName)) {
      throw new \DomainException('Escribe el nombre del firmante exactamente como aparece en el acta.');
    }
    // Explicit typed electronic signature; never infer consent from opening the link.
    return ['name' => $name, 'document' => $document, 'method' => 'typed-name-and-explicit-consent', 'consent_version' => '1', 'consent_text' => self::CONSENT];
  }
}
