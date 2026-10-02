<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

final class PublicServicesSchedule
{
  /** Three calendar months from the actual review, clamping month-end dates. */
  public static function next(int $timestamp): int
  {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Bogota'));
    $target = $date->modify('first day of this month')->modify('+3 months');
    return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min((int) $date->format('j'), (int) $target->format('t')))->getTimestamp();
  }
}
