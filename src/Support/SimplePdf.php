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

  public function save(string $path): void
  {
    $this->finishPage();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new \RuntimeException('No se pudo crear el directorio del PDF.');
    }

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
        $imageWidth = max(1, (int) ($imageInfo[0] ?? 1));
        $imageHeight = max(1, (int) ($imageInfo[1] ?? 1));
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

    $kids = [];
    foreach ($this->pages as $pageContent) {
      $contentId = $next++;
      $pageId = $next++;
      if ($backgroundObjectId !== null && is_array($backgroundDraw)) {
        [$drawW, $drawH, $drawX, $drawY] = $backgroundDraw;
        $pageContent = 'q ' . $this->n($drawW) . ' 0 0 ' . $this->n($drawH) . ' ' . $this->n($drawX) . ' ' . $this->n($drawY) . " cm /ImBg Do Q\n" . $pageContent;
      }
      $objects[$contentId] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "\nendstream";
      $xObject = $backgroundObjectId !== null ? ' /XObject << /ImBg ' . $backgroundObjectId . ' 0 R >>' : '';
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

    if (file_put_contents($path, $pdf) === false) {
      throw new \RuntimeException('No se pudo guardar el PDF.');
    }
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
    $this->ensureSpace(24);
    $this->fill(6, 29, 73);
    $this->text($this->margin, $this->y, $text, 12, 'F2');
    $this->fill(0, 0, 0);
    $this->y += 22;
  }

  public function line(string $text, int $size = 8, string $font = 'F1'): void
  {
    $this->ensureSpace($size + 8);
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
    $this->ensureSpace(20);
    $this->fill(0, 87, 164);
    $this->text($this->margin, $this->y, $label . ': ' . $url, 8, 'F1');
    $this->fill(0, 0, 0);
    $this->y += 18;
  }

  public function spacer(float $height): void
  {
    $this->y += $height;
  }

  public function signatureBlock(string $label, string $name, string $details): void
  {
    $this->ensureSpace(54);
    $this->fill(245, 145, 32);
    $this->rect($this->margin, $this->y + 1, 52, 2);
    $this->y += 12;
    $this->fill(100, 116, 139);
    $this->text($this->margin, $this->y, strtoupper($label), 7, 'F2');
    $this->y += 13;
    $this->fill(6, 29, 73);
    $this->text($this->margin, $this->y, $name, 10, 'F2');
    $this->y += 15;
    if (trim($details) !== '') {
      $this->fill(71, 85, 105);
      foreach ($this->wrap($details, $this->contentWidth, 7) as $line) {
        $this->text($this->margin, $this->y, $line, 7, 'F1');
        $this->y += 11;
      }
    }
    $this->y += 8;
  }

  private function ensureSpace(float $height): void
  {
    if ($this->y + $height <= self::PAGE_H - $this->bottomMargin) {
      return;
    }
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
    $this->pages[] = $this->content;
  }

  /** @return string[] */
  private function wrap(string $text, float $width, int $size): array
  {
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
      return [''];
    }
    $maxChars = max(18, (int) floor($width / max(1, $size * 0.48)));
    $words = explode(' ', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
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
