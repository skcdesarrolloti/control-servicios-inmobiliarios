<?php

declare(strict_types=1);

namespace SCM\Modules\CanonInsuranceAudit;

use SCM\Support\LegacyXlsReader;
use Shuchkin\SimpleXLSX;

final class SpreadsheetReader
{
  public const MAX_ROWS = 5000;

  /** @return array<int,array<int,mixed>> */
  public function read(string $path, string $originalName): array
  {
    if ($path === '' || !is_readable($path)) {
      throw new \RuntimeException('El archivo temporal no se puede leer.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'xls') {
      $book = new LegacyXlsReader($path);
      if (!$book->success()) {
        throw new \RuntimeException('No se pudo leer el archivo XLS: ' . (string) $book->error());
      }
      $rows = $book->rows(0, self::MAX_ROWS + 1);
    } elseif ($extension === 'xlsx') {
      $book = SimpleXLSX::parseFile($path);
      if (!$book instanceof SimpleXLSX) {
        throw new \RuntimeException('No se pudo leer el archivo XLSX: ' . (string) SimpleXLSX::parseError());
      }
      $rows = $book->rows(0, self::MAX_ROWS + 1);
    } else {
      throw new \RuntimeException('Formato no soportado. Sube un archivo .xls o .xlsx.');
    }

    if (count($rows) > self::MAX_ROWS) {
      throw new \RuntimeException('El archivo supera el limite de ' . self::MAX_ROWS . ' filas.');
    }

    return array_map(static function (array $row): array {
      return array_values($row);
    }, $rows);
  }
}
