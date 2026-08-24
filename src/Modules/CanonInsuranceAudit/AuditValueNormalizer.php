<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

final class AuditValueNormalizer
{
  public static function text(mixed $value): string
  {
    if ($value === null) {
      return '';
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    $text = str_replace(["\0", "\xC2\xA0"], ['', ' '], (string) $value);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  public static function key(mixed $value): string
  {
    $text = mb_strtolower(self::text($value), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = is_string($ascii) ? $ascii : $text;
    return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
  }

  public static function requestNumber(mixed $value): string
  {
    if (is_float($value) || is_int($value)) {
      $value = number_format((float) $value, 0, '.', '');
    }
    $text = mb_strtoupper(self::text($value), 'UTF-8');
    if (preg_match('/^([0-9]+)\.0+$/', $text, $match) === 1) {
      $text = $match[1];
    }
    return preg_replace('/[^A-Z0-9]+/', '', $text) ?? '';
  }

  public static function money(mixed $value): ?float
  {
    if ($value === null || self::text($value) === '') {
      return null;
    }
    if (is_int($value) || is_float($value)) {
      return round((float) $value, 2);
    }

    $text = self::text($value);
    $negative = str_contains($text, '(') && str_contains($text, ')');
    $text = preg_replace('/[^0-9,\.\-]/', '', $text) ?? '';
    if ($text === '' || $text === '-') {
      return null;
    }

    $lastComma = strrpos($text, ',');
    $lastDot = strrpos($text, '.');
    if ($lastComma !== false && $lastDot !== false) {
      $decimal = $lastComma > $lastDot ? ',' : '.';
      $thousands = $decimal === ',' ? '.' : ',';
      $text = str_replace($thousands, '', $text);
      $text = str_replace($decimal, '.', $text);
    } elseif ($lastComma !== false) {
      $text = self::normalizeSingleSeparator($text, ',');
    } elseif ($lastDot !== false) {
      $text = self::normalizeSingleSeparator($text, '.');
    }

    if (!is_numeric($text)) {
      return null;
    }
    $number = (float) $text;
    if ($negative && $number > 0) {
      $number *= -1;
    }
    return round($number, 2);
  }

  private static function normalizeSingleSeparator(string $value, string $separator): string
  {
    $parts = explode($separator, $value);
    if (count($parts) > 2) {
      return implode('', $parts);
    }
    $fractionLength = strlen((string) ($parts[1] ?? ''));
    if ($fractionLength === 0) {
      return (string) $parts[0];
    }
    if ($fractionLength <= 2) {
      return (string) $parts[0] . '.' . (string) $parts[1];
    }
    return implode('', $parts);
  }
}
