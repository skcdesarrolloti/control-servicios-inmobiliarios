<?php
declare(strict_types=1);

namespace SCM\Support;

/** Local PDF conversion for hosts without Chromium or a private Gotenberg service. */
final class HtmlPdfFallback
{
  public static function render(string $content, string $title): ?string
  {
    if (!class_exists(\DOMDocument::class)) {
      return null;
    }
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
      $loaded = $document->loadHTML('<?xml encoding="utf-8"?><main>' . $content . '</main>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
    if (!$loaded) {
      return null;
    }

    $pdf = new SimplePdf();
    $pdf->actaDesign('SKC SuCasa Inmobiliaria');
    $pdf->layout(42, 52, 55);
    $pdf->footerLabel('SKC SuCasa Inmobiliaria - Cotización de mantenimiento');
    $pdf->title($title);
    $main = $document->getElementsByTagName('main')->item(0);
    if (!$main) {
      return null;
    }
    foreach ($main->childNodes as $child) {
      self::drawNode($pdf, $child);
    }
    $bytes = $pdf->bytes();
    return str_starts_with($bytes, '%PDF-') ? $bytes : null;
  }

  private static function drawNode(SimplePdf $pdf, \DOMNode $node): void
  {
    if (!$node instanceof \DOMElement) {
      return;
    }
    $tag = strtolower($node->tagName);
    $text = self::text($node);
    if (in_array($tag, ['h1', 'h2', 'h3'], true)) {
      if ($text !== '') $pdf->heading($text);
      return;
    }
    if ($tag === 'h4') {
      if ($text !== '') $pdf->line($text, 9, 'F2');
      return;
    }
    if ($tag === 'p' || $tag === 'li') {
      if ($text !== '') $pdf->paragraph($text, 8);
      return;
    }
    if ($tag === 'table') {
      self::drawTable($pdf, $node);
      return;
    }
    if ($tag === 'img') {
      self::drawImage($pdf, $node);
      return;
    }
    if ($tag === 'script' || $tag === 'style' || $tag === 'button') {
      return;
    }
    if ($tag === 'div' && $text !== '' && !self::hasBlockChildren($node)) {
      $pdf->line($text, 8);
      return;
    }
    foreach ($node->childNodes as $child) {
      self::drawNode($pdf, $child);
    }
  }

  private static function drawTable(SimplePdf $pdf, \DOMElement $table): void
  {
    $xpath = new \DOMXPath($table->ownerDocument);
    $rows = [];
    $headers = [];
    foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
      $cells = [];
      foreach ($tr->childNodes as $child) {
        if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['th', 'td'], true)) {
          $cells[] = self::text($child);
        }
      }
      if ($cells === []) continue;
      $headerParent = $xpath->query('ancestor::thead', $tr);
      if ($headers === [] && $headerParent !== false && $headerParent->length > 0) {
        $headers = $cells;
      } else {
        $rows[] = $cells;
      }
    }
    if ($rows === [] && $headers === []) return;
    if ($headers === []) {
      $headers = array_fill(0, max(array_map('count', $rows)), '');
    }
    $pdf->table($headers, $rows, [], 8);
  }

  private static function drawImage(SimplePdf $pdf, \DOMElement $image): void
  {
    $url = html_entity_decode(trim($image->getAttribute('src')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);
    parse_str($query, $params);
    $name = basename((string) ($params['n'] ?? $path));
    $local = StoredFileService::fromRuntime()->pathFor($name);
    if ($local !== null && $pdf->image($local, 390, 260)) {
      return;
    }
    $alt = self::text($image->getAttribute('alt'));
    if ($alt !== '') $pdf->line('Soporte: ' . $alt, 8);
  }

  private static function hasBlockChildren(\DOMElement $element): bool
  {
    foreach ($element->getElementsByTagName('*') as $child) {
      if (in_array(strtolower($child->tagName), ['article', 'section', 'div', 'h1', 'h2', 'h3', 'h4', 'p', 'li', 'table', 'img'], true)) {
        return true;
      }
    }
    return false;
  }

  private static function text(\DOMNode|string $value): string
  {
    $text = $value instanceof \DOMNode ? $value->textContent : $value;
    return trim(preg_replace('/\s+/u', ' ', $text) ?: '');
  }
}
