<?php

declare(strict_types=1);

namespace SCM\Modules\TicketCompletion;

final class CompletionPolicy
{
  public const WAITING = 'En espera de firma';
  public const ROLES = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'copropiedad' => 'Copropiedad'];
  public const EXECUTORS = ['inmobiliaria' => 'Inmobiliaria'] + self::ROLES;
  public const EXECUTION_STATES = [
    'inmobiliaria' => 'En ejecucion por inmobiliaria',
    'propietario' => 'En ejecucion por propietario',
    'arrendatario' => 'En ejecucion por arrendatario',
    'copropiedad' => 'En ejecucion por copropiedad',
  ];
  public const CONSENT = 'Confirmo que soy la persona designada para firmar, revisé el acta y recibí a satisfacción las soluciones descritas. Acepto firmarla electrónicamente con mi nombre y cerrar este ticket.';
  public const DRAWN_CONSENT = 'Confirmo que soy la persona designada, revisé los daños, soluciones y observaciones del acta y los recibo a satisfacción. Acepto firmar electrónicamente con mi trazo, nombre, documento y código de verificación, y autorizar el cierre de este ticket.';

  public static function phone(string $phone, string $indicator = ''): string
  {
    if (!preg_match('/^\+?[\d\s().-]+$/D', trim($phone))) { return ''; }
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    $country = preg_replace('/\D/', '', $indicator) ?? '';
    if (strlen($digits) === 10 && !str_starts_with(trim($phone), '+')) {
      $digits = ($country ?: '57') . $digits;
    }
    return preg_match('/^[1-9]\d{9,14}$/D', $digits) ? '+' . $digits : '';
  }

  public static function executionState(string $executor): string
  {
    if (!isset(self::EXECUTION_STATES[$executor])) {
      throw new \DomainException('Selecciona quién realizó la solución.');
    }
    return self::EXECUTION_STATES[$executor];
  }

  /** Numeric strokes only: no uploaded SVG, image URL or executable content. */
  public static function strokes(mixed $raw): array
  {
    if (!is_string($raw) || strlen($raw) > 45000) { throw new \DomainException('La firma excede el tamaño permitido. Bórrala e inténtalo de nuevo.'); }
    try { $strokes = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new \DomainException('Dibuja tu firma en el recuadro.'); }
    if (!is_array($strokes) || !array_is_list($strokes) || count($strokes) < 1 || count($strokes) > 80) { throw new \DomainException('Dibuja tu firma en el recuadro.'); }
    $count = 0; $length = 0.0; $clean = [];
    foreach ($strokes as $stroke) {
      if (!is_array($stroke) || !array_is_list($stroke) || count($stroke) < 2) { throw new \DomainException('La firma debe contener trazos, no solo puntos.'); }
      $line = []; $previous = null;
      foreach ($stroke as $point) {
        if (!is_array($point) || array_keys($point) !== [0, 1] || !is_numeric($point[0]) || !is_numeric($point[1])) { throw new \DomainException('Trazo de firma no válido.'); }
        $x = (float) $point[0]; $y = (float) $point[1];
        if (!is_finite($x) || !is_finite($y) || $x < 0 || $x > 1000 || $y < 0 || $y > 350 || ++$count > 1500) { throw new \DomainException('Trazo de firma fuera de rango.'); }
        $point = [round($x, 1), round($y, 1)];
        if ($previous) { $length += hypot($point[0] - $previous[0], $point[1] - $previous[1]); }
        $line[] = $point; $previous = $point;
      }
      $clean[] = $line;
    }
    if ($count < 8 || $length < 80) { throw new \DomainException('La firma está vacía o es demasiado corta. Dibuja tu firma completa.'); }
    return $clean;
  }

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
    $photoCount = 0;
    $photoBytes = 0;
    foreach ($raw as $row) {
      if (!is_array($row)) {
        throw new \DomainException('El detalle del daño no es válido.');
      }
      $photos = [];
      $rawPhotos = $row['photos'] ?? [];
      if (!is_array($rawPhotos)) { throw new \DomainException('Las evidencias fotográficas no son válidas.'); }
      foreach ($rawPhotos as $photo) {
        if (!is_array($photo) || count($photos) >= 4 || !preg_match('/^[a-f0-9]{24}_[0-9]+\.jpg$/D', (string) ($photo['name'] ?? ''))
          || ($photo['mime'] ?? '') !== 'image/jpeg' || !preg_match('/^[a-f0-9]{64}$/D', (string) ($photo['sha256'] ?? ''))
          || (int) ($photo['width'] ?? 0) < 1 || (int) ($photo['width'] ?? 0) > 1600
          || (int) ($photo['height'] ?? 0) < 1 || (int) ($photo['height'] ?? 0) > 1600
          || (int) ($photo['bytes'] ?? 0) < 100 || (int) ($photo['bytes'] ?? 0) > 1500000) {
          throw new \DomainException('Una evidencia fotográfica no es válida. Vuelve a seleccionar las fotos.');
        }
        $photos[] = [
          'name' => (string) $photo['name'], 'mime' => 'image/jpeg',
          'width' => (int) $photo['width'], 'height' => (int) $photo['height'], 'bytes' => (int) $photo['bytes'],
          'sha256' => (string) $photo['sha256'],
        ];
        $photoCount++;
        if ($photoCount > 12) { throw new \DomainException('El acta admite máximo 12 fotos en total.'); }
        $photoBytes += (int) $photo['bytes'];
        if ($photoBytes > 8000000) { throw new \DomainException('Las fotos del acta superan 8 MB después de comprimir.'); }
      }
      $items[] = [
        'damage' => self::text($row['damage'] ?? '', 'daño encontrado', 3000),
        'solution' => self::text($row['solution'] ?? '', 'solución realizada', 3000),
        'photos' => $photos,
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
