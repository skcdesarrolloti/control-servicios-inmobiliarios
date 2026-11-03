<?php

declare(strict_types=1);

namespace SCM\Support;

/**
 * Lector de la primera hoja de un .xlsx en PHP puro (sin la extensión zip ni librerías): descomprime con gzinflate y
 * lee sharedStrings y la hoja. Es la misma lectura que usa la importación de Notificaciones administrativas.
 */
final class XlsxReader
{
  private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

  /** @return list<list<string>> filas de la primera hoja, cada celda como texto recortado */
  public static function rows(string $path, int $maxRows = 20000): array
  {
    $entries = self::zipEntries($path);
    $shared = self::sharedStrings($entries['xl/sharedStrings.xml'] ?? '');
    $sheet = simplexml_load_string((string) ($entries[self::firstSheet($entries)] ?? ''));
    if (!$sheet instanceof \SimpleXMLElement) throw new \RuntimeException('No se pudo leer la primera hoja del XLSX.');
    $data = $sheet->sheetData ?? $sheet->children(self::MAIN_NS)->sheetData;
    $rows = [];
    foreach (($data->row ?? []) as $xmlRow) {
      $cells = [];
      foreach ($xmlRow->c as $cell) {
        $index = self::columnIndex((string) ($cell['r'] ?? ''));
        $cells[$index >= 0 ? $index : count($cells)] = self::cellValue($cell, $shared);
      }
      if ($cells === []) continue;
      $row = [];
      for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) $row[] = trim((string) ($cells[$i] ?? ''));
      $rows[] = $row;
      if (count($rows) >= $maxRows) break;
    }
    return $rows;
  }

  /** Ruta de la primera hoja según workbook.xml (por defecto, sheet1.xml). @param array<string,string> $entries */
  private static function firstSheet(array $entries): string
  {
    $workbook = simplexml_load_string((string) ($entries['xl/workbook.xml'] ?? ''));
    $rels = simplexml_load_string((string) ($entries['xl/_rels/workbook.xml.rels'] ?? ''));
    if ($workbook instanceof \SimpleXMLElement && $rels instanceof \SimpleXMLElement && isset($workbook->sheets->sheet[0])) {
      $id = (string) $workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
      foreach ($rels->Relationship as $rel) {
        if ((string) $rel['Id'] !== $id) continue;
        $target = ltrim((string) $rel['Target'], '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
      }
    }
    return 'xl/worksheets/sheet1.xml';
  }

  /** @return array<string,string> nombre => contenido (stored o deflate) */
  private static function zipEntries(string $path): array
  {
    $data = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($data) || $data === '') throw new \RuntimeException('No se pudo leer el XLSX.');
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) throw new \RuntimeException('El archivo no es un XLSX válido (no tiene estructura ZIP).');
    $total = (int) (unpack('v', substr($data, $eocd + 10, 2))[1] ?? 0);
    $pos = (int) (unpack('V', substr($data, $eocd + 16, 4))[1] ?? 0);
    $entries = [];
    for ($i = 0; $i < $total && $pos + 46 <= strlen($data) && substr($data, $pos, 4) === "PK\x01\x02"; $i++) {
      $method = (int) unpack('v', substr($data, $pos + 10, 2))[1];
      $size = (int) unpack('V', substr($data, $pos + 20, 4))[1];
      $nameLength = (int) unpack('v', substr($data, $pos + 28, 2))[1];
      $extraLength = (int) unpack('v', substr($data, $pos + 30, 2))[1];
      $commentLength = (int) unpack('v', substr($data, $pos + 32, 2))[1];
      $offset = (int) unpack('V', substr($data, $pos + 42, 4))[1];
      $name = str_replace('\\', '/', substr($data, $pos + 46, $nameLength));
      $pos += 46 + $nameLength + $extraLength + $commentLength;
      if ($name === '' || substr($data, $offset, 4) !== "PK\x03\x04") continue;
      $start = $offset + 30 + (int) unpack('v', substr($data, $offset + 26, 2))[1] + (int) unpack('v', substr($data, $offset + 28, 2))[1];
      $raw = substr($data, $start, $size);
      if ($method === 0) $entries[$name] = $raw;
      elseif ($method === 8 && is_string($inflated = @gzinflate($raw))) $entries[$name] = $inflated;
    }
    return $entries;
  }

  /** @return list<string> */
  private static function sharedStrings(string $xml): array
  {
    $doc = trim($xml) !== '' ? simplexml_load_string($xml) : false;
    if (!$doc instanceof \SimpleXMLElement) return [];
    $out = [];
    foreach (($doc->si ?? $doc->children(self::MAIN_NS)->si) as $si) {
      $text = isset($si->t) ? (string) $si->t : '';
      foreach ($si->r as $run) $text .= (string) $run->t;
      $out[] = trim($text);
    }
    return $out;
  }

  /** @param list<string> $shared */
  private static function cellValue(\SimpleXMLElement $cell, array $shared): string
  {
    $type = (string) ($cell['t'] ?? '');
    if ($type === 's') return (string) ($shared[(int) $cell->v] ?? '');
    if ($type === 'inlineStr') return trim((string) ($cell->is->t ?? ''));
    return trim((string) ($cell->v ?? ''));
  }

  /** "C12" → 2. */
  private static function columnIndex(string $ref): int
  {
    if (preg_match('/^([A-Z]+)\d*$/i', $ref, $match) !== 1) return -1;
    $index = 0;
    foreach (str_split(strtoupper($match[1])) as $char) $index = $index * 26 + (ord($char) - 64);
    return $index - 1;
  }
}
