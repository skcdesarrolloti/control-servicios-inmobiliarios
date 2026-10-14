<?php
declare(strict_types=1);
namespace SCM\Support;

final class QuotationFinancingFilter
{
  /** Reuse the listing's exact ticket/quotation relation, selecting the latest quotation. */
  public static function condition(string $existsExpression): string
  {
    if (!str_starts_with($existsExpression, 'EXISTS (SELECT 1 FROM ')) return '(NULL) = ?';
    $query = substr($existsExpression, strlen('EXISTS ('), -1);
    $query = preg_replace('/^SELECT 1 FROM /', "SELECT LOWER(TRIM(COALESCE(cot_exists.`financiacion`, ''))) FROM ", $query);
    return '(' . $query . ' ORDER BY cot_exists.`_ID` DESC LIMIT 1) = ?';
  }
}
