<?php

/**
 * Equivalentes PHP puro de las funciones de WordPress más usadas en el proyecto.
 * Se incluye una sola vez desde bootstrap.php.
 */

if (!function_exists('esc_html')) {
  function esc_html(string $str): string
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('esc_attr')) {
  function esc_attr(string $str): string
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('esc_url')) {
  function esc_url(string $url): string
  {
    $url = filter_var(trim($url), FILTER_SANITIZE_URL);
    return $url ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
  }
}

if (!function_exists('esc_url_raw')) {
  function esc_url_raw(string $url): string
  {
    return filter_var(trim($url), FILTER_SANITIZE_URL) ?: '';
  }
}

if (!defined('SCM_DEFAULT_PORTAL_LOGO_URL')) {
  define('SCM_DEFAULT_PORTAL_LOGO_URL', 'https://sucasainmobiliaria.com.co/wp-content/uploads/2023/07/SUCASA_PNG_CALIDAD-NORMAL_5.png');
}

if (!defined('SCM_DEFAULT_PORTAL_FAVICON_URL')) {
  define('SCM_DEFAULT_PORTAL_FAVICON_URL', 'https://sucasainmobiliaria.com.co/wp-content/uploads/2023/07/SUCASA_PNG_CALIDAD-NORMAL_5-150x150.png');
}

if (!function_exists('system_image')) {
  function system_image(string $function, string $fallback = ''): string
  {
    static $cache = [];

    $function = trim($function);
    if ($function === '') {
      return $fallback;
    }

    if ($fallback === '') {
      if ($function === 'portal_logo_url') {
        $fallback = SCM_DEFAULT_PORTAL_LOGO_URL;
      } elseif ($function === 'portal_favicon_url') {
        $fallback = SCM_DEFAULT_PORTAL_FAVICON_URL;
      }
    }

    if (array_key_exists($function, $cache)) {
      return $cache[$function];
    }

    try {
      $db = \SCM\Core\App::db();
      $table = str_replace('`', '', $db->table('jet_cct_confi_sistema'));
      $row = $db->getRow(
        "SELECT COALESCE(NULLIF(`valor`, ''), NULLIF(`imagen`, '')) AS image_url
         FROM `{$table}`
         WHERE `funcion` = ?
         LIMIT 1",
        [$function]
      );
      $url = trim((string) ($row['image_url'] ?? ''));

      return $cache[$function] = $url !== '' ? $url : $fallback;
    } catch (\Throwable $e) {
      return $cache[$function] = $fallback;
    }
  }
}

if (!function_exists('esc_js')) {
  function esc_js(string $str): string
  {
    return addslashes($str);
  }
}

if (!function_exists('esc_textarea')) {
  function esc_textarea(string $str): string
  {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('wp_json_encode')) {
  function wp_json_encode($data)
  {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
  }
}

if (!function_exists('sanitize_text_field')) {
  function sanitize_text_field(string $str): string
  {
    return trim(strip_tags(stripslashes($str)));
  }
}

if (!function_exists('sanitize_textarea_field')) {
  function sanitize_textarea_field(string $str): string
  {
    return trim(stripslashes($str));
  }
}

if (!function_exists('wp_strip_all_tags')) {
  function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
  {
    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? '';
    $text = strip_tags($text);
    if ($remove_breaks) {
      $text = preg_replace('/[\r\n\t ]+/', ' ', $text) ?? '';
    }
    return trim($text);
  }
}

if (!function_exists('wp_kses_post')) {
  function wp_kses_post(string $content): string
  {
    // Lista corta de etiquetas HTML permitidas en contenido tipo "post".
    $allowed = '<a><abbr><b><blockquote><br><code><dd><del><div><dl><dt><em><h1><h2><h3><h4><h5><h6><hr><i><img><li><ol><p><pre><q><s><span><strong><sub><sup><table><tbody><td><th><thead><tr><u><ul>';
    $content = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $content) ?? '';
    return strip_tags($content, $allowed);
  }
}

if (!function_exists('sanitize_key')) {
  function sanitize_key(string $str): string
  {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($str));
  }
}

if (!function_exists('wp_unslash')) {
  function wp_unslash($value)
  {
    if (is_array($value)) {
      return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
  }
}

if (!function_exists('wp_date')) {
  function wp_date(string $format, int $ts = 0): string
  {
    return date($format, $ts ?: time());
  }
}

if (!function_exists('wp_redirect')) {
  function wp_redirect(string $url, int $status = 302): void
  {
    header('Location: ' . $url, true, $status);
    exit;
  }
}

if (!function_exists('wp_die')) {
  function wp_die(string $message = ''): void
  {
    http_response_code(403);
    exit(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
  }
}

if (!function_exists('wp_send_json_success')) {
  function wp_send_json_success($data = null): void
  {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
  }
}

if (!function_exists('wp_send_json_error')) {
  function wp_send_json_error($data = null): void
  {
    header('Content-Type: application/json; charset=UTF-8');
    $message = is_string($data) ? $data : ($data['message'] ?? 'Error');
    echo json_encode(['success' => false, 'data' => ['message' => $message]]);
    exit;
  }
}

if (!function_exists('wp_create_nonce')) {
  function wp_create_nonce(string $action = ''): string
  {
    return \SCM\Core\App::csrf()->token($action);
  }
}

if (!function_exists('wp_verify_nonce')) {
  /**
   * WP-style nonce verification.
   * Usa singleUse=false para permitir múltiples peticiones AJAX con el mismo nonce.
   */
  function wp_verify_nonce(string $nonce, string $action = ''): bool
  {
    return \SCM\Core\App::csrf()->verify($action, $nonce, false);
  }
}

if (!function_exists('wp_nonce_field')) {
  function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $echo = true): string
  {
    $nonce = \SCM\Core\App::csrf()->token($action);
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($nonce) . '">';
    if ($echo) {
      echo $field;
    }
    return $field;
  }
}

if (!function_exists('wp_nonce_url')) {
  function wp_nonce_url(string $url, string $action = '', string $name = '_wpnonce'): string
  {
    $nonce = \SCM\Core\App::csrf()->token($action);
    return add_query_arg([$name => $nonce], $url);
  }
}

if (!function_exists('check_ajax_referer')) {
  function check_ajax_referer(string $action = '', string $query_arg = ''): void
  {
    $nonce = '';
    if ($query_arg !== '' && isset($_POST[$query_arg])) {
      $nonce = (string) $_POST[$query_arg];
    } elseif ($query_arg !== '' && isset($_GET[$query_arg])) {
      $nonce = (string) $_GET[$query_arg];
    } elseif (isset($_REQUEST['_wpnonce'])) {
      $nonce = (string) $_REQUEST['_wpnonce'];
    }
    // singleUse=false: el nonce de la página sirve para múltiples peticiones AJAX
    if (!\SCM\Core\App::csrf()->verify($action, $nonce, false)) {
      wp_send_json_error('Verificación de seguridad fallida.');
    }
  }
}

if (!function_exists('get_option')) {
  function get_option(string $key, $default = false)
  {
    return \SCM\Core\App::settings()->get($key, $default);
  }
}

if (!function_exists('update_option')) {
  function update_option(string $key, $value): bool
  {
    if (!\SCM\Core\Auth::isLoggedIn()) {
      return false;
    }
    \SCM\Core\App::settings()->set($key, $value, \SCM\Core\Auth::userId());
    return true;
  }
}

if (!function_exists('current_user_can')) {
  function current_user_can(string $cap = ''): bool
  {
    return \SCM\Core\Auth::isLoggedIn();
  }
}

if (!function_exists('is_user_logged_in')) {
  function is_user_logged_in(): bool
  {
    return \SCM\Core\Auth::isLoggedIn();
  }
}

if (!function_exists('get_current_user_id')) {
  function get_current_user_id(): int
  {
    return \SCM\Core\Auth::userId();
  }
}

if (!function_exists('wp_get_current_user')) {
  function wp_get_current_user(): object
  {
    $name = \SCM\Core\Auth::user();
    return (object) [
      'ID'           => \SCM\Core\Auth::userId(),
      'display_name' => $name,
      'user_login'   => $name,
    ];
  }
}

if (!function_exists('shortcode_atts')) {
  function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
  {
    $result = [];
    foreach ($pairs as $name => $default) {
      $result[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $result;
  }
}

if (!function_exists('apply_filters')) {
  function apply_filters(string $tag, $value, ...$args)
  {
    return $value;
  }
}

if (!function_exists('admin_url')) {
  function admin_url(string $path = ''): string
  {
    $base = defined('SCM_BASE_URL') ? SCM_BASE_URL : '';
    return $base . '/' . ltrim($path, '/');
  }
}

if (!function_exists('plugins_url')) {
  function plugins_url(string $path = '', string $plugin = ''): string
  {
    $base = defined('SCM_BASE_URL') ? SCM_BASE_URL : '';
    return $base . '/' . ltrim($path, '/');
  }
}

if (!function_exists('plugin_dir_url')) {
  function plugin_dir_url(string $file = ''): string
  {
    return (defined('SCM_BASE_URL') ? SCM_BASE_URL : '') . '/';
  }
}

if (!function_exists('plugin_dir_path')) {
  function plugin_dir_path(string $file = ''): string
  {
    return (defined('SCM_BASE_PATH') ? SCM_BASE_PATH : dirname(__DIR__, 2)) . DIRECTORY_SEPARATOR;
  }
}

if (!function_exists('home_url')) {
  function home_url(string $path = ''): string
  {
    return (defined('SCM_BASE_URL') ? SCM_BASE_URL : '') . '/' . ltrim($path, '/');
  }
}

if (!function_exists('add_query_arg')) {
  function add_query_arg(array $args, string $url = ''): string
  {
    if ($url === '') {
      $url = (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '');
    }
    $parts = parse_url($url);
    $existing = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $existing);
    }
    $merged = array_merge($existing, $args);
    $query = http_build_query($merged);
    $base  = ($parts['scheme'] ?? '') !== '' ? ($parts['scheme'] . '://' . ($parts['host'] ?? '')) : '';
    $base .= $parts['path'] ?? '';
    return $base . ($query !== '' ? '?' . $query : '');
  }
}

if (!function_exists('remove_query_arg')) {
  function remove_query_arg($keys, string $url = ''): string
  {
    if ($url === '') {
      $url = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '') : '';
    }
    $parts = parse_url($url);
    $existing = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $existing);
    }
    foreach ((array) $keys as $key) {
      unset($existing[$key]);
    }
    $query = http_build_query($existing);
    $base  = ($parts['scheme'] ?? '') !== '' ? ($parts['scheme'] . '://' . ($parts['host'] ?? '')) : '';
    $base .= $parts['path'] ?? '';
    return $base . ($query !== '' ? '?' . $query : '');
  }
}

if (!function_exists('wp_get_referer')) {
  function wp_get_referer(): string
  {
    return isset($_SERVER['HTTP_REFERER']) ? filter_var($_SERVER['HTTP_REFERER'], FILTER_SANITIZE_URL) : '';
  }
}

if (!function_exists('selected')) {
  function selected($selected, $current = true, bool $echo = true): string
  {
    $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
    if ($echo) {
      echo $result;
    }
    return $result;
  }
}

if (!function_exists('checked')) {
  function checked($checked, $current = true, bool $echo = true): string
  {
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) {
      echo $result;
    }
    return $result;
  }
}

if (!function_exists('__')) {
  function __(string $text, string $domain = 'default'): string
  {
    return $text;
  }
}

if (!function_exists('_e')) {
  function _e(string $text, string $domain = 'default'): void
  {
    echo $text;
  }
}
