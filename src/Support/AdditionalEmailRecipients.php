<?php
declare(strict_types=1);

namespace SCM\Support;

/** Validates optional, per-message copies before any business record is saved. */
final class AdditionalEmailRecipients
{
  public static function parse($raw): array
  {
    $values = is_array($raw) ? $raw : [$raw];
    $emails = [];
    foreach ($values as $value) {
      if (!is_string($value)) throw new \InvalidArgumentException('Los correos adicionales no tienen un formato válido.');
      if (strlen($value) > 6000) throw new \InvalidArgumentException('Puedes agregar hasta 20 correos adicionales.');
      foreach (preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Revisa los correos adicionales: hay una dirección inválida.');
        $email = strtolower($email);
        $emails[$email] = $email;
        if (count($emails) > 20) throw new \InvalidArgumentException('Puedes agregar hasta 20 correos adicionales.');
      }
    }
    return array_values($emails);
  }
}
