<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

final class PublicServicesSchedule
{
  /** Legacy month: first occurrence on/after delivery, never anchored to today's year. */
  public static function initial(int $deliveryTimestamp, int $month): int
  {
    if ($month < 1 || $month > 12) {
      return $deliveryTimestamp;
    }
    $date = (new \DateTimeImmutable('@' . $deliveryTimestamp))->setTimezone(new \DateTimeZone('America/Bogota'));
    $year = (int) $date->format('Y') + ($month < (int) $date->format('n') ? 1 : 0);
    $target = $date->setDate($year, $month, 1);
    return $target->setDate($year, $month, min((int) $date->format('j'), (int) $target->format('t')))->getTimestamp();
  }

  /** Three calendar months from the actual review, clamping month-end dates. */
  public static function next(int $timestamp): int
  {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('America/Bogota'));
    $target = $date->modify('first day of this month')->modify('+3 months');
    return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min((int) $date->format('j'), (int) $target->format('t')))->getTimestamp();
  }
}
