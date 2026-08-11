<?php


namespace SCM\Support;

final class DateFormatter
{
  public function formatDate(int $ts): string
  {
    if ($ts <= 0) {
      return '';
    }

    return date('d/m/Y', $ts);
  }

  public function formatDateTime(int $ts): string
  {
    if ($ts <= 0) {
      return '';
    }

    return date('d/m/Y H:i', $ts);
  }
}
