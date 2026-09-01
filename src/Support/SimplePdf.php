<?php

namespace SCM\Support;

final class SimplePdf
{
  private const PAGE_W = 595.28;
  private const PAGE_H = 841.89;

  /** @var string[] */
  private array $pages = [];
  private string $content = '';
  private float $margin = 46.0;
  private float $topMargin = 46.0;
  private float $bottomMargin = 46.0;
  private float $contentWidth = 505.0;
  private float $y = 46.0;
  private ?string $backgroundImagePath = null;
  /** @var array<string,array{path:string,width:int,height:int}> */
  private array $images = [];
  private string $footerLabel = 'SKC SuCasa Inmobiliaria - Cotización de mantenimiento';

  public function __construct()
  {
    $this->content = '';
    $this->y = $this->topMargin;
  }

  public function layout(float $margin = 46.0, float $topMargin = 46.0, float $bottomMargin = 46.0): void
  {
    $this->margin = max(24.0, $margin);
    $this->topMargin = max(24.0, $topMargin);
    $this->bottomMargin = max(24.0, $bottomMargin);
    $this->contentWidth = max(260.0, self::PAGE_W - ($this->margin * 2));
    $this->y = $this->topMargin;
  }

  public function backgroundImage(string $path): void
  {
    $this->backgroundImagePath = is_file($path) ? $path : null;
  }

  public function footerLabel(string $label): void
  {
    $this->footerLabel = trim($label);
  }

  public function save(string $path): void
  {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new \RuntimeException('No se pudo crear el directorio del PDF.');
    }
    if (file_put_contents($path, $this->bytes()) === false) {
      throw new \RuntimeException('No se pudo guardar el PDF.');
    }
  }

  public function bytes(): string
  {
    $this->finishPage();

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

    $next = 5;
    $backgroundObjectId = null;
    $backgroundDraw = null;
    if ($this->backgroundImagePath !== null && is_file($this->backgroundImagePath)) {
      $imageInfo = @getimagesize($this->backgroundImagePath);
      $imageData = @file_get_contents($this->backgroundImagePath);
      if (is_array($imageInfo) && is_string($imageData) && $imageData !== '') {
        $imageWidth = max(1, (int) $imageInfo[0]);
        $imageHeight = max(1, (int) $imageInfo[1]);
        $channels = (int) ($imageInfo['channels'] ?? 3);
        $colorSpace = $channels === 4 ? '/DeviceCMYK' : '/DeviceRGB';
        $backgroundObjectId = $next++;
        $objects[$backgroundObjectId] = '<< /Type /XObject /Subtype /Image /Width ' . $imageWidth
          . ' /Height ' . $imageHeight . ' /ColorSpace ' . $colorSpace
          . " /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($imageData)
          . " >>\nstream\n" . $imageData . "\nendstream";

        $scale = max(self::PAGE_W / $imageWidth, self::PAGE_H / $imageHeight);
        $drawW = $imageWidth * $scale;
        $drawH = $imageHeight * $scale;
        $drawX = (self::PAGE_W - $drawW) / 2;
        $drawY = (self::PAGE_H - $drawH) / 2;
        $backgroundDraw = [$drawW, $drawH, $drawX, $drawY];
      }
    }

    $imageObjectIds = [];
    foreach ($this->images as $resource => $image) {
      $data = @file_get_contents($image['path']);
      if (!is_string($data) || $data === '') { continue; }
      $imageObjectIds[$resource] = $next;
      $objects[$next++] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width']
        . ' /Height ' . $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
        . strlen($data) . ">>\nstream\n" . $data . "\nendstream";
    }

    $kids = [];
    foreach ($this->pages as $pageContent) {
      $contentId = $next++;
      $pageId = $next++;
      if ($backgroundObjectId !== null) {
        [$drawW, $drawH, $drawX, $drawY] = $backgroundDraw;
        $pageContent = 'q ' . $this->n($drawW) . ' 0 0 ' . $this->n($drawH) . ' ' . $this->n($drawX) . ' ' . $this->n($drawY) . " cm /ImBg Do Q\n" . $pageContent;
      }
      $objects[$contentId] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "\nendstream";
      $resources = [];
      if ($backgroundObjectId !== null) { $resources[] = '/ImBg ' . $backgroundObjectId . ' 0 R'; }
      foreach ($imageObjectIds as $resource => $objectId) { $resources[] = '/' . $resource . ' ' . $objectId . ' 0 R'; }
      $xObject = $resources ? ' /XObject << ' . implode(' ', $resources) . ' >>' : '';
      $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . '] '
        . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xObject . ' >> /Contents ' . $contentId . ' 0 R >>';
      $kids[] = $pageId . ' 0 R';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $body) {
      $offsets[$id] = strlen($pdf);
      $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) {
      $pdf .= sprintf("%010d 00000 n \n", (int) ($offsets[$i] ?? 0));
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
  }

  /** Embed a local, already validated JPEG without a remote fetch or image decoder. */
  public function image(string $path, float $maximumWidth = 320, float $maximumHeight = 220): bool
  {
    $info = @getimagesize($path);
    if (!is_array($info) || $info['mime'] !== 'image/jpeg' || (int) $info[0] < 1 || (int) $info[1] < 1) { return false; }
    $ratio = min($maximumWidth / (int) $info[0], $maximumHeight / (int) $info[1], 1.0);
    $width = max(1.0, (int) $info[0] * $ratio); $height = max(1.0, (int) $info[1] * $ratio);
    $this->ensureSpace($height + 14);
    $resource = 'Im' . (count($this->images) + 1);
    $this->images[$resource] = ['path' => $path, 'width' => (int) $info[0], 'height' => (int) $info[1]];
    $this->content .= 'q ' . $this->n($width) . ' 0 0 ' . $this->n($height) . ' ' . $this->n($this->margin)
      . ' ' . $this->n(self::PAGE_H - $this->y - $height) . ' cm /' . $resource . " Do Q\n";
    $this->y += $height + 14;
    return true;
  }

  /** Validated normalized strokes, 1000 x 350; no image decoder or remote resource. */
  public function drawnSignature(array $strokes): void
  {
    $this->ensureSpace(126);
    $this->content .= "q 0.06 0.15 0.29 RG 1.4 w 1 J 1 j\n";
    foreach ($strokes as $stroke) {
      foreach ($stroke as $index => $point) {
        $this->content .= $this->n($this->margin + (float) $point[0] * .30) . ' ' . $this->n(self::PAGE_H - $this->y - (float) $point[1] * .30) . ($index === 0 ? " m\n" : " l\n");
      }
      $this->content .= "S\n";
    }
    $this->content .= "Q\n";
    $this->y += 116;
  }

  public function logo(): void
  {
    $this->fill(245, 145, 32);
    $this->rect(250, $this->y + 3, 14, 14);
    $this->fill(16, 73, 119);
    $this->text(270, $this->y + 3, 'SuCasa', 15, 'F2');
    $this->text(270, $this->y + 18, 'INMOBILIARIA', 7, 'F2');
    $this->fill(64, 64, 65);
    $this->y += 54;
  }

  public function title(string $text): void
  {
    $this->ensureSpace(42);
    $this->fill(6, 29, 73);
    foreach ($this->wrap($text, $this->contentWidth, 17) as $line) {
      $this->text($this->margin, $this->y, $line, 17, 'F2');
      $this->y += 24;
    }
    $this->fill(245, 145, 32);
    $this->rect($this->margin, $this->y + 2, 82, 2.5);
    $this->y += 24;
  }

  public function heading(string $text): void
  {
    $this->ensureSpace(72);
    $this->fill(6, 29, 73);
    $this->rect($this->margin, $this->y, $this->contentWidth, 28);
    $this->fill(245, 145, 32);
    $this->rect($this->margin, $this->y, 5, 28);
    $this->fill(255, 255, 255);
    $this->text($this->margin + 14, $this->y + 18, $text, 11, 'F2');
    $this->fill(13, 33, 58);
    $this->y += 36;
  }

  /**
   * @param string[] $headers
   * @param array<int,array<int,string|int|float>> $rows
   * @param float[] $widthRatios
   * @param int[] $rightAlignColumns
   */
  public function table(array $headers, array $rows, array $widthRatios = [], int $fontSize = 8, array $rightAlignColumns = []): void
  {
    $columnCount = count($headers);
    if ($columnCount === 0) {
      return;
    }

    if (count($widthRatios) !== $columnCount || array_sum($widthRatios) <= 0) {
      $widthRatios = array_fill(0, $columnCount, 1.0);
    }
    $ratioTotal = (float) array_sum($widthRatios);
    $widths = array_map(fn(float $ratio): float => $this->contentWidth * ($ratio / $ratioTotal), $widthRatios);
    $headerCells = array_map(static fn($value): string => (string) $value, $headers);
    $this->drawTableRow($headerCells, $widths, max(7, $fontSize - 1), true, false, $rightAlignColumns);

    foreach ($rows as $index => $row) {
      $cells = array_values(array_map(static fn($value): string => (string) $value, $row));
      $cells = array_pad(array_slice($cells, 0, $columnCount), $columnCount, '');
      $height = $this->tableRowHeight($cells, $widths, $fontSize);
      if (!$this->hasSpace($height)) {
        $this->startNewPage();
        $this->drawTableRow($headerCells, $widths, max(7, $fontSize - 1), true, false, $rightAlignColumns);
      }
      $this->drawTableRow($cells, $widths, $fontSize, false, $index % 2 === 1, $rightAlignColumns);
    }
    $this->y += 9;
  }

  public function callout(string $label, string $text, int $fontSize = 8): void
  {
    $label = trim($label);
    $lines = $this->wrap($text, $this->contentWidth - 24, $fontSize);
    $height = max(44, 25 + count($lines) * ($fontSize + 5));
    $this->ensureSpace($height);
    $this->fill(255, 247, 237);
    $this->rect($this->margin, $this->y, $this->contentWidth, $height - 6);
    $this->fill(245, 145, 32);
    $this->rect($this->margin, $this->y, 5, $height - 6);
    $this->fill(6, 29, 73);
    $this->text($this->margin + 14, $this->y + 9, strtoupper($label), 7, 'F2');
    $lineY = $this->y + 24;
    $this->fill(25, 43, 69);
    foreach ($lines as $line) {
      $this->text($this->margin + 14, $lineY, $line, $fontSize, 'F1');
      $lineY += $fontSize + 5;
    }
    $this->y += $height;
  }

  public function line(string $text, int $size = 8, string $font = 'F1'): void
  {
    $this->ensureSpace(max(72, $size + 8));
    $this->fill(13, 33, 58);
    $this->text($this->margin, $this->y, $text, $size, $font);
    $this->y += $size + 8;
  }

  public function paragraph(string $text, int $size = 8): void
  {
    $lines = $this->wrap($text, $this->contentWidth, $size);
    $this->ensureSpace(max(18, count($lines) * ($size + 6)));
    $this->fill(25, 43, 69);
    foreach ($lines as $line) {
      $this->text($this->margin, $this->y, $line, $size, 'F1');
      $this->y += $size + 6;
    }
    $this->y += 8;
  }

  /** @param string[] $items */
  public function bullets(array $items, int $size = 8): void
  {
    foreach ($items as $item) {
      $lines = $this->wrap($item, $this->contentWidth - 17, $size);
      $this->ensureSpace(max(16, count($lines) * ($size + 5)));
      $this->fill(245, 145, 32);
      $this->text($this->margin + 2, $this->y, '*', $size, 'F2');
      $this->fill(25, 43, 69);
      foreach ($lines as $idx => $line) {
        $this->text($this->margin + 14, $this->y, $line, $size, 'F1');
        $this->y += $size + 5;
        if ($idx === 0) {
          continue;
        }
      }
    }
    $this->y += 8;
  }

  public function linkText(string $label, string $url): void
  {
    $lines = $this->wrap($label . ': ' . $url, $this->contentWidth, 8);
    $this->ensureSpace(max(20, count($lines) * 13));
    $this->fill(0, 87, 164);
    foreach ($lines as $line) {
      $this->text($this->margin, $this->y, $line, 8, 'F1');
      $this->y += 13;
    }
    $this->fill(0, 0, 0);
    $this->y += 5;
  }

  public function spacer(float $height): void
  {
    $this->y += $height;
  }

  public function pageBreak(): void
  {
    $this->startNewPage();
  }

  public function reserveSpace(float $height): void
  {
    $this->ensureSpace($height);
  }

  public function signatureBlock(string $label, string $name, string $details): void
  {
    $detailLines = trim($details) !== '' ? $this->wrap($details, $this->contentWidth, 8) : [];
    $this->ensureSpace(56 + count($detailLines) * 12);
    $this->fill(245, 145, 32);
    $this->rect($this->margin, $this->y, 70, 3);
    $this->y += 15;
    $this->fill(61, 76, 105);
    foreach ($this->wrap($label, $this->contentWidth, 8) as $line) {
      $this->text($this->margin, $this->y, $line, 8, 'F2');
      $this->y += 11;
    }
    $this->y += 2;
    $this->fill(6, 29, 73);
    foreach ($this->wrap($name, $this->contentWidth, 11) as $line) {
      $this->text($this->margin, $this->y, $line, 11, 'F2');
      $this->y += 14;
    }
    if ($detailLines !== []) {
      $this->fill(55, 70, 95);
      foreach ($detailLines as $line) {
        $this->text($this->margin, $this->y, $line, 8, 'F1');
        $this->y += 12;
      }
    }
    $this->y += 10;
  }

  private function ensureSpace(float $height): void
  {
    if ($this->hasSpace($height)) {
      return;
    }
    $this->startNewPage();
  }

  private function hasSpace(float $height): bool
  {
    return $this->y + $height <= self::PAGE_H - $this->bottomMargin;
  }

  private function startNewPage(): void
  {
    $this->finishPage();
    $this->content = '';
    $this->y = $this->topMargin;
  }

  private function finishPage(): void
  {
    if ($this->content === '' && !empty($this->pages)) {
      return;
    }
    if ($this->content === '') {
      $this->content = "BT /F1 1 Tf 0 0 Td () Tj ET\n";
    }
    $this->fill(100, 116, 139);
    if ($this->footerLabel !== '') {
      $this->text($this->margin, self::PAGE_H - $this->bottomMargin + 24, $this->footerLabel, 7, 'F1');
    }
    $pageLabel = 'Página ' . (string) (count($this->pages) + 1);
    $this->textRight($this->margin + $this->contentWidth, self::PAGE_H - $this->bottomMargin + 24, $pageLabel, 7, 'F1');
    $this->pages[] = $this->content;
  }

  /** @param string[] $cells @param float[] $widths @param int[] $rightAlignColumns */
  private function drawTableRow(array $cells, array $widths, int $fontSize, bool $header, bool $alternate, array $rightAlignColumns): void
  {
    $height = $this->tableRowHeight($cells, $widths, $fontSize);
    $this->ensureSpace($height);
    $x = $this->margin;
    foreach ($cells as $index => $cell) {
      $width = $widths[$index] ?? 0.0;
      if ($header) {
        $this->fill(6, 29, 73);
      } elseif ($alternate) {
        $this->fill(241, 245, 249);
      } else {
        $this->fill(248, 250, 252);
      }
      $this->rect($x, $this->y, max(0, $width - 1), $height - 1);
      $lines = $this->wrap((string) $cell, max(24, $width - 12), $fontSize);
      $lineY = $this->y + $fontSize + 5;
      $this->fill($header ? 255 : 15, $header ? 255 : 23, $header ? 255 : 42);
      foreach ($lines as $line) {
        if (in_array($index, $rightAlignColumns, true)) {
          $this->textRight($x + $width - 6, $lineY, $line, $fontSize, $header ? 'F2' : 'F1');
        } else {
          $this->text($x + 6, $lineY, $line, $fontSize, $header ? 'F2' : 'F1');
        }
        $lineY += $fontSize + 4;
      }
      $x += $width;
    }
    $this->fill(13, 33, 58);
    $this->y += $height;
  }

  /** @param string[] $cells @param float[] $widths */
  private function tableRowHeight(array $cells, array $widths, int $fontSize): float
  {
    $maxLines = 1;
    foreach ($cells as $index => $cell) {
      $maxLines = max($maxLines, count($this->wrap((string) $cell, max(24, ($widths[$index] ?? 0) - 12), $fontSize)));
    }
    return max(22, 12 + $maxLines * ($fontSize + 4));
  }

  /** @return string[] */
  private function wrap(string $text, float $width, int $size): array
  {
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
      return [''];
    }
    $maxChars = max(8, (int) floor($width / max(1, $size * 0.56)));
    $words = explode(' ', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
      $wordLength = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
      if ($wordLength > $maxChars) {
        if ($line !== '') {
          $lines[] = $line;
          $line = '';
        }
        while ($wordLength > $maxChars) {
          $lines[] = function_exists('mb_substr')
            ? mb_substr($word, 0, $maxChars, 'UTF-8')
            : substr($word, 0, $maxChars);
          $word = function_exists('mb_substr')
            ? mb_substr($word, $maxChars, null, 'UTF-8')
            : substr($word, $maxChars);
          $wordLength = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
        }
        $line = $word;
        continue;
      }
      $candidate = $line === '' ? $word : ($line . ' ' . $word);
      $length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
      if ($length > $maxChars && $line !== '') {
        $lines[] = $line;
        $line = $word;
      } else {
        $line = $candidate;
      }
    }
    if ($line !== '') {
      $lines[] = $line;
    }
    return $lines;
  }

  private function text(float $x, float $topY, string $text, int $size, string $font): void
  {
    $pdfY = self::PAGE_H - $topY;
    $this->content .= 'BT /' . $font . ' ' . $size . ' Tf ' . $this->n($x) . ' ' . $this->n($pdfY) . ' Td (' . $this->pdfString($text) . ") Tj ET\n";
  }

  private function textRight(float $rightX, float $topY, string $text, int $size, string $font): void
  {
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $estimatedWidth = $length * $size * 0.52;
    $this->text(max($this->margin, $rightX - $estimatedWidth), $topY, $text, $size, $font);
  }

  private function rect(float $x, float $topY, float $w, float $h): void
  {
    $pdfY = self::PAGE_H - $topY - $h;
    $this->content .= $this->n($x) . ' ' . $this->n($pdfY) . ' ' . $this->n($w) . ' ' . $this->n($h) . " re f\n";
  }

  private function fill(int $r, int $g, int $b): void
  {
    $this->content .= $this->n($r / 255) . ' ' . $this->n($g / 255) . ' ' . $this->n($b / 255) . " rg\n";
  }

  private function pdfString(string $text): string
  {
    $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if ($encoded === false) {
      $encoded = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
  }

  private function n(float $value): string
  {
    return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
  }
}
