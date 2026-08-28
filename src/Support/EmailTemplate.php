<?php

namespace SCM\Support;

final class EmailTemplate
{
  /** @param array<string,mixed> $vars */
  public static function render(string $title, string $contentHtml, array $vars = []): string
  {
    $ticketUrl = trim((string)($vars['ticket_url'] ?? ''));
    $quoteUrl = trim((string)($vars['cotizacion_url'] ?? ''));
    $bannerUrl = trim((string)($vars['banner_url'] ?? ''));
    if ($bannerUrl === '' && function_exists('system_image')) {
      $bannerUrl = (string)system_image('banner_sitio_web', 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/banner-sitio-web.png');
    }
    if ($bannerUrl === '') {
      $bannerUrl = 'https://sucasainmobiliaria.com.co/wp-content/uploads/2026/06/banner-sitio-web.png';
    }
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
      . '<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;">'
      . '<tr><td align="center" style="padding:28px 12px;">'
      . '<table role="presentation" width="700" cellpadding="0" cellspacing="0" border="0" style="max-width:700px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 14px 30px rgba(15,23,42,.08);">'
      . '<tr><td style="padding:0;"><img src="' . self::e($bannerUrl) . '" alt="Su Casa Inmobiliaria" style="display:block;width:100%;height:auto;border:0;"></td></tr>'
      . '<tr><td style="background:#f59120;height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>'
      . '<tr><td style="padding:36px 34px;text-align:left;color:#334155;">'
      . '<h3 style="color:#061d49;font-size:22px;margin:0 0 22px;text-align:center;">' . self::e($title) . '</h3>'
      . $contentHtml
      . ($buttons !== '' ? '<div style="margin-top:20px;">' . $buttons . '</div>' : '')
      . '</td></tr>'
      . '<tr><td style="background:#0f172a;text-align:center;font-size:14px;padding:22px 20px;color:#cbd5e1;">'
      . '<p style="margin:0 0 6px;color:#ffffff;font-weight:700;">Una empresa para lograr sus sue&ntilde;os.</p>'
      . '<p style="margin:0;color:#94a3b8;">&copy; ' . date('Y') . ' Su Casa Inmobiliaria</p>'
      . '</td></tr>'
      . '</table></td></tr></table></body></html>';
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
