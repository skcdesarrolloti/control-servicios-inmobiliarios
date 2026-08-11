<?php

namespace SCM\Support;

final class EmailTemplate
{
  /** @param array<string,string> $vars */
  public static function render(string $title, string $contentHtml, array $vars = []): string
  {
    $ticketUrl = trim((string)($vars['ticket_url'] ?? ''));
    $quoteUrl = trim((string)($vars['cotizacion_url'] ?? ''));
    $extraButtons = $vars['buttons'] ?? [];

    $buttons = '';
    if ($ticketUrl !== '') {
      $buttons .= self::button($ticketUrl, 'Ver ticket');
    }
    if ($quoteUrl !== '') {
      $buttons .= self::button($quoteUrl, 'Ver cotizacion');
    }
    if (is_array($extraButtons) && !empty($extraButtons)) {
      $buttons .= self::buttons($extraButtons);
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . self::e($title) . '</title></head>'
      . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">'
      . '<table align="center" width="100%" cellpadding="0" cellspacing="0" style="max-width:760px;border:3px solid #ebecec;background:#fff;margin:20px auto;">'
      . '<tr><td style="background:#f59120;height:14px;font-size:0;line-height:0;">&nbsp;</td></tr>'
      . '<tr><td style="padding:24px;text-align:center;color:#061d49;">'
      . '<h3 style="font-size:18px;margin:0 0 18px;">' . self::e($title) . '</h3>'
      . $contentHtml
      . ($buttons !== '' ? '<div style="margin-top:20px;">' . $buttons . '</div>' : '')
      . '</td></tr>'
      . '<tr><td style="background:#f59120;text-align:center;font-weight:600;font-size:16px;padding:16px;color:white;">Una empresa para lograr sus sue&ntilde;os.</td></tr>'
      . '</table></body></html>';
  }

  /** @param array<string,string> $vars */
  public static function renderNamed(string $name, array $vars): string
  {
    $resources = defined('SCM_RESOURCES_PATH') ? SCM_RESOURCES_PATH : dirname(__DIR__, 2) . '/resources';
    $path = $resources . '/emails/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name) . '.php';
    if (!is_readable($path)) {
      return self::render((string)($vars['titulo'] ?? 'SUCASA INMOBILIARIA'), (string)($vars['contenido'] ?? ''), $vars);
    }

    $html = (string)file_get_contents($path);
    $replacements = [];
    foreach ($vars as $key => $value) {
      $replacements['{' . $key . '}'] = (string)$value;
    }

    $html = strtr($html, $replacements);
    return preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $html) ?? $html;
  }

  public static function buttons(array $buttons): string
  {
    $html = '';
    foreach ($buttons as $button) {
      $url = (string)($button['url'] ?? '');
      $label = (string)($button['label'] ?? '');
      if ($url !== '' && $label !== '') {
        $html .= self::button($url, $label);
      }
    }
    return $html;
  }

  private static function button(string $url, string $label): string
  {
    return '<a href="' . self::e($url) . '" style="background:#404041;padding:12px 18px;border-radius:5px;text-decoration:none;color:white;font-weight:700;display:inline-block;margin:6px;">' . self::e($label) . '</a>';
  }

  public static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
