<?php

namespace SCM\Views;

final class PublicTicketPortalView
{
  /**
   * @param array<string,mixed> $config
   * @param array<string,mixed> $options
   */
  public function render(array $config, array $options, string $nonce, string $apiUrl, string $baseUrl = ''): void
  {
    $actor = (string) ($config['actor'] ?? '');
    $label = (string) ($config['label'] ?? ucfirst($actor));
    $lookupLabel = (string) ($config['lookup_label'] ?? 'Consulta');
    $lookupPlaceholder = (string) ($config['lookup_placeholder'] ?? '');
    $requiresContract = !empty($config['requires_contract']);
    $mascotImage = trim((string) ($config['mascot_image'] ?? ''));
    $isEmbedded = isset($_GET['embedded']) && (string) $_GET['embedded'] === '1';
    $title = 'Centro de solicitudes – ' . $label;
    $subtitle = $requiresContract
      ? 'Consulta tus contratos, reporta novedades y radica solicitudes relacionadas con tus inmuebles de forma rápida y segura.'
      : 'Consulta tu registro, reporta novedades y radica solicitudes de forma rápida y segura.';

    $temaOpts = is_array($options['tema_ayuda'] ?? null) ? $options['tema_ayuda'] : (is_array($options['tipo_pqrs'] ?? null) ? $options['tipo_pqrs'] : []);

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $portalPath = (string) strtok($requestUri !== '' ? $requestUri : $scriptName, '?');
    if ($portalPath === '') {
      $portalPath = '/';
    }

    $homeUrl = 'https://sucasainmobiliaria.com.co/';
    if ($baseUrl !== '') {
      $parts = parse_url($baseUrl);
      if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
        $homeUrl = $parts['scheme'] . '://' . $parts['host'] . '/';
      }
    }

    $showSuccess = isset($_GET['radicado']) && (string) $_GET['radicado'] === '1';
    $refRaw = trim((string) ($_GET['ref'] ?? ''));
    $successRef = preg_replace('/[^0-9A-Za-z_-]/', '', $refRaw);
    if (!is_string($successRef)) {
      $successRef = '';
    }

    $runtime = [
      'actor' => $actor,
      'apiUrl' => $apiUrl,
      'nonce' => $nonce,
      'requiresContract' => $requiresContract,
      'lookupLabel' => $lookupLabel,
      'portalUrl' => $portalPath,
    ];
    $runtimeJson = htmlspecialchars((string) json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?php echo esc_html($title); ?></title>
      <link rel="icon" href="<?php echo \esc_url(\system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)); ?>" sizes="32x32">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
      <link rel="stylesheet" href="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/css/public-ticket-portal.css?v=' . SCM_VERSION); ?>">
    </head>

    <body>
      <div class="shell" id="public-ticket-app" data-runtime="<?php echo $runtimeJson; ?>">
        <section class="hero">
          <div class="hero-copy">
            <span class="hero-logo">
              <img src="<?php echo \esc_url(\system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)); ?>" alt="Su Casa Inmobiliaria">
            </span>
            <h1><?php echo esc_html($title); ?></h1>
            <p><?php echo esc_html($subtitle); ?></p>
          </div>
          <?php if ($mascotImage !== ''): ?>
            <img src="<?php echo esc_url($mascotImage); ?>" alt="Castor asesor SK&C" class="hero-castor" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
        </section>
        <?php if ($showSuccess): ?>
          <section class="card success-card">
            <div class="card-body" style="padding:28px 24px;">
              <h2 class="success-title">Solicitud radicada exitosamente</h2>
              <p class="success-text">Tu solicitud fue registrada correctamente. Nuestro equipo revisar&#225; la informaci&#243;n y la dirigir&#225; al &#225;rea correspondiente. Recibir&#225;s notificaci&#243;n al correo registrado. (Ref. #<?php echo esc_html($successRef !== '' ? $successRef : '-'); ?>)</p>
              <div class="success-actions">
                <a class="btn btn-primary btn-link" href="<?php echo esc_url($portalPath); ?>">Agregar otra solicitud</a>
                <a class="btn btn-secondary btn-link" href="<?php echo esc_url($homeUrl); ?>">Ir a la p&#225;gina de la inmobiliaria</a>
              </div>
            </div>
          </section>
        <?php else: ?>

          <section class="card" id="step-1">
            <div class="card-header">
              <h2>Paso 1: Consulta</h2>
            </div>
            <div class="card-body">
              <form id="lookup-form" class="inline-form">
                <div class="field">
                  <label for="lookup_value"><?php echo esc_html($lookupLabel); ?></label>
                  <input type="text" id="lookup_value" name="lookup_value" placeholder="<?php echo esc_attr($lookupPlaceholder); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary">Consultar</button>
                <button type="button" id="lookup-clear" class="btn btn-secondary">Limpiar</button>
              </form>
              <div id="lookup-status" class="status"></div>
            </div>
          </section>

          <section class="card is-hidden" id="step-2">
            <div class="card-header">
              <div class="card-header-row">
                <h2><?php echo $requiresContract ? 'Paso 2: Selecciona el contrato' : 'Paso 2: Selecciona el cliente'; ?></h2>
                <button type="button" id="back-step-1" class="btn btn-secondary">Volver a consulta</button>
              </div>
            </div>
            <div class="card-body">
              <div class="result-wrap">
                <table>
                  <thead id="lookup-head"></thead>
                  <tbody id="lookup-body">
                    <tr>
                      <td style="padding:16px;color:#5a6f86;">Realiza primero la consulta para ver resultados.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="hint" id="selection-hint"><?php echo $requiresContract ? 'Debes seleccionar un contrato para continuar.' : 'Debes seleccionar un cliente para continuar.'; ?></div>
            </div>
          </section>

          <section class="card is-hidden" id="step-3">
            <div class="card-header">
              <div class="card-header-row">
                <h2>Cu&#233;ntanos qu&#233; necesitas</h2>
                <button type="button" id="back-step-2" class="btn btn-secondary">Cambiar selecci&#243;n</button>
              </div>
              <p style="margin:6px 0 0;font-size:13px;color:#5a7492;">Selecciona el tema de tu solicitud y describe brevemente lo que necesitas. Nuestro equipo la revisar&#225; y la direccionar&#225; al &#225;rea correspondiente.</p>
            </div>
            <div class="card-body">
              <div class="request-panel">
                <h3>Tus solicitudes recientes</h3>
                <p>Cuando selecciones tu contrato o registro, te mostraremos las solicitudes que ya tienes radicadas para que puedas consultarlas antes de crear una nueva.</p>
                <div id="request-status" class="status"></div>
                <div id="request-results" class="request-list">
                  <div class="hint">Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.</div>
                </div>
              </div>
              <form id="create-form">
                <div class="grid">
                  <div class="field">
                    <label for="solicitante">Nombre del solicitante</label>
                    <input type="text" id="solicitante" name="solicitante" required>
                  </div>
                  <div class="field">
                    <label for="correo_solicitante">Correo electr&#243;nico de contacto</label>
                    <input type="email" id="correo_solicitante" name="correo_solicitante" required>
                  </div>
                  <div class="field">
                    <label for="celular_solicitante">Celular de contacto</label>
                    <input type="text" id="celular_solicitante" name="celular_solicitante" required>
                  </div>
                  <div class="field">
                    <label for="indicativo">Indicativo</label>
                    <input type="text" id="indicativo" name="indicativo" value="+57">
                  </div>
                  <div class="field grid-1">
                    <label for="asunto">Asunto</label>
                    <input type="text" id="asunto" name="asunto" required>
                  </div>
                  <div class="field">
                    <label for="tema_ayuda">Tema de la solicitud</label>
                    <select id="tema_ayuda" name="tema_ayuda" required>
                      <option value="">&#8212; Selecciona un tema &#8212;</option>
                      <?php foreach ($temaOpts as $opt): ?>
                        <option value="<?php echo esc_attr((string) $opt); ?>" <?php selected((string) $opt, (string) ($config['theme_default'] ?? '')); ?>><?php echo esc_html((string) $opt); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="tema-hint">Selecciona el tema que mejor describa tu solicitud. Esto nos permitir&#225; dirigirla al &#225;rea correspondiente y atenderla m&#225;s r&#225;pido.</div>
                    <div id="tema-help-box" class="tema-help-box">Selecciona un tema para ver una gu&#237;a sobre c&#243;mo diligenciar tu solicitud.</div>
                  </div>
                  <div class="field grid-1">
                    <label for="descripcion">Describe tu solicitud</label>
                    <textarea id="descripcion" name="descripcion" required placeholder="Describe tu solicitud con el mayor detalle posible."></textarea>
                  </div>
                </div>
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                  <button type="submit" class="btn btn-primary">Radicar solicitud</button>
                  <button type="button" id="create-clear" class="btn btn-secondary">Borrar datos</button>
                </div>
              </form>
              <div id="create-status" class="status"></div>
            </div>
          </section>
        <?php endif; ?>
      </div>

      <?php if (!$showSuccess): ?>
        <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/public-ticket-portal.js?v=' . SCM_VERSION); ?>" defer></script>
      <?php endif; ?>
      <script src="<?php echo esc_url(rtrim((string) SCM_BASE_URL, '/') . '/assets/js/public-ticket-frame.js?v=' . SCM_VERSION); ?>" defer></script>
    </body>

    </html>
<?php
  }
}
