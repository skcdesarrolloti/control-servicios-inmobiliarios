<?php

declare(strict_types=1);

namespace SCM\Modules\LegalCases;

/** Lista blanca para el cuerpo de las comunicaciones: lo que no esté permitido se quita, y solo los enlaces conservan un atributo. */
final class LegalHtmlSanitizer
{
  private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a'];
  private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'meta', 'link', 'title', 'form', 'input', 'button', 'select', 'textarea'];

  public static function clean(string $html): string
  {
    // Quill entrega espacios duros; como texto normal el correo ajusta bien las líneas.
    $html = str_ireplace(['&nbsp;', "\xC2\xA0"], ' ', $html);
    if (trim($html) === '') return '';
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementsByTagName('div')->item(0);
    if (!$root instanceof \DOMElement) return '';
    self::cleanChildren($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= (string) $document->saveHTML($child);
    return trim($out);
  }

  public static function isEmpty(string $html): bool
  {
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '';
  }

  private static function cleanChildren(\DOMNode $parent): void
  {
    foreach (iterator_to_array($parent->childNodes) as $node) {
      if ($node instanceof \DOMText) continue;
      if (!$node instanceof \DOMElement) {
        $parent->removeChild($node);
        continue;
      }
      $tag = strtolower($node->tagName);
      if (in_array($tag, self::DROPPED, true)) {
        $parent->removeChild($node);
        continue;
      }
      self::cleanChildren($node);
      $href = $tag === 'a' ? self::safeHref($node->getAttribute('href')) : '';
      if (!in_array($tag, self::ALLOWED, true) || ($tag === 'a' && $href === '')) {
        while ($node->firstChild !== null) $parent->insertBefore($node->firstChild, $node);
        $parent->removeChild($node);
        continue;
      }
      foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttribute($attribute->nodeName);
      if ($tag === 'a') {
        $node->setAttribute('href', $href);
        $node->setAttribute('rel', 'noopener noreferrer');
        $node->setAttribute('target', '_blank');
      }
    }
  }

  private static function safeHref(string $href): string
  {
    $href = trim($href);
    return preg_match('/^(https?:\/\/|mailto:)[^\s"\'<>]+$/i', $href) === 1 ? $href : '';
  }
}
