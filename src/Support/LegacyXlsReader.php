<?php

declare(strict_types=1);

namespace SCM\Support;

use Shuchkin\SimpleXLS;

/**
 * Corrige registros LABEL BIFF8 que SimpleXLS interpreta incluyendo el byte
 * de opciones como texto. Algunos extractos de aseguradoras usan LABEL en
 * lugar de SST y, sin esta correccion, pierden el ultimo caracter de cada
 * celda y conservan un NUL al inicio.
 */
final class LegacyXlsReader extends SimpleXLS
{
  public function __construct(string $filename, bool $isData = false, bool $debug = false)
  {
    parent::__construct($filename, $isData, $debug);
    if ($this->success()) {
      $this->repairBiff8InlineLabels();
    }
  }

  private function repairBiff8InlineLabels(): void
  {
    $dataLength = strlen((string) $this->data);
    foreach ((array) $this->boundsheets as $sheetIndex => $sheet) {
      $offset = (int) ($sheet['offset'] ?? -1);
      if ($offset < 0 || $offset + 8 > $dataLength) {
        continue;
      }

      $version = $this->uint16($offset + 4);
      if ($version !== self::BIFF8) {
        continue;
      }

      $position = $offset + $this->uint16($offset + 2) + 4;
      while ($position + 4 <= $dataLength) {
        $code = $this->uint16($position);
        $recordLength = $this->uint16($position + 2);
        $payload = $position + 4;
        if ($code === self::TYPE_EOF || $payload + $recordLength > $dataLength) {
          break;
        }

        if ($code === self::TYPE_LABEL && $recordLength >= 9) {
          $row = $this->uint16($payload);
          $column = $this->uint16($payload + 2);
          $characterCount = $this->uint16($payload + 6);
          $flags = ord((string) $this->data[$payload + 8]);
          $cursor = $payload + 9;

          if (($flags & 0x08) !== 0 && $cursor + 2 <= $payload + $recordLength) {
            $cursor += 2;
          }
          if (($flags & 0x04) !== 0 && $cursor + 4 <= $payload + $recordLength) {
            $cursor += 4;
          }

          $isUtf16 = ($flags & 0x01) !== 0;
          $byteLength = $isUtf16 ? $characterCount * 2 : $characterCount;
          $available = max(0, ($payload + $recordLength) - $cursor);
          $raw = substr((string) $this->data, $cursor, min($byteLength, $available));
          $value = $isUtf16
            ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
            : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');

          $this->sheets[(int) $sheetIndex]['cells'][$row][$column] = $value;
          if (isset($this->sheets[(int) $sheetIndex]['cellsInfo'][$row][$column])) {
            $this->sheets[(int) $sheetIndex]['cellsInfo'][$row][$column]['raw'] = $value;
          }
        }

        $position = $payload + $recordLength;
      }
    }
  }

  private function uint16(int $position): int
  {
    return ord((string) $this->data[$position])
      | (ord((string) $this->data[$position + 1]) << 8);
  }
}
