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
      <style>
        * {
          box-sizing: border-box;
        }

        body {
          margin: 0;
          font-family: 'Poppins', sans-serif;
          color: #1b2d43;
          background:
            radial-gradient(circle at 0% 0%, rgba(245, 145, 1, .14), transparent 36%),
            radial-gradient(circle at 100% 0%, rgba(47, 111, 167, .16), transparent 30%),
            #eef4fa;
        }

        .shell {
          max-width: 1040px;
          margin: 0 auto;
          padding: 24px 16px 40px;
        }

        .hero {
          background: linear-gradient(140deg, #1f4c78, #2f6fa7);
          color: #fff;
          border-radius: 16px;
          padding: 20px 22px;
          box-shadow: 0 20px 45px rgba(18, 47, 78, .28);
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 18px;
        }

        .hero-copy {
          flex: 1 1 auto;
          min-width: 0;
        }

        .hero-logo {
          width: 154px;
          min-height: 48px;
          border-radius: 8px;
          background: #fff;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 7px 10px;
          margin-bottom: 12px;
          box-shadow: 0 12px 26px rgba(10, 30, 50, .20);
        }

        .hero-logo img {
          display: block;
          width: auto;
          max-width: 100%;
          max-height: 34px;
          object-fit: contain;
        }

        .hero-castor {
          width: 136px;
          height: 136px;
          flex: 0 0 136px;
          border-radius: 16px;
          object-fit: cover;
          background: rgba(255, 255, 255, .14);
          border: 1px solid rgba(255, 255, 255, .2);
          box-shadow: 0 14px 30px rgba(12, 32, 52, .35);
        }

        .hero h1 {
          margin: 0;
          font-size: 30px;
          line-height: 1.1;
        }

        .hero p {
          margin: 8px 0 0;
          color: #d9e8f7;
          font-size: 14px;
        }

        .card {
          margin-top: 16px;
          background: #fff;
          border-radius: 14px;
          border: 1px solid #d8e3ef;
          box-shadow: 0 10px 26px rgba(19, 42, 66, .08);
          overflow: hidden;
        }

        .card-header {
          padding: 16px 18px;
          border-bottom: 1px solid #e6edf5;
        }

        .card-header h2 {
          margin: 0;
          font-size: 18px;
        }

        .card-header-row {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          flex-wrap: wrap;
        }

        .card-body {
          padding: 16px 18px 20px;
        }

        .inline-form {
          display: flex;
          flex-wrap: wrap;
          gap: 10px;
          align-items: end;
        }

        .field {
          flex: 1 1 320px;
        }

        .field label {
          display: block;
          font-size: 12px;
          font-weight: 600;
          color: #506178;
          margin-bottom: 6px;
        }

        .field input,
        .field select,
        .field textarea {
          width: 100%;
          border: 1px solid #cfd9e6;
          border-radius: 10px;
          padding: 10px 12px;
          font-size: 14px;
          font-family: inherit;
          color: #1b2d43;
          background: #fff;
        }

        .field textarea {
          min-height: 140px;
          resize: vertical;
        }

        .btn {
          border: 0;
          border-radius: 10px;
          cursor: pointer;
          font-family: inherit;
          font-weight: 600;
          font-size: 14px;
          padding: 10px 16px;
          transition: transform .15s ease, box-shadow .15s ease;
        }

        .btn:hover {
          transform: translateY(-1px);
        }

        .btn-primary {
          color: #fff;
          background: linear-gradient(120deg, #f18f01, #f5a73b);
          box-shadow: 0 10px 20px rgba(241, 145, 1, .26);
          font-size: 15px;
          padding: 12px 28px;
          letter-spacing: .01em;
        }

        .btn-secondary {
          color: #22496f;
          background: #edf3f9;
          border: 1px solid #d2dfec;
          font-size: 14px;
        }

        .btn-secondary:hover {
          background: #dce8f4;
        }

        .success-card {
          max-width: 760px;
          margin-left: auto;
          margin-right: auto;
        }

        .success-title {
          margin: 0 0 8px;
          font-size: 26px;
          line-height: 1.18;
          color: #173755;
        }

        .success-text {
          margin: 0;
          color: #36516d;
          line-height: 1.6;
          font-size: 15px;
        }

        .success-actions {
          margin-top: 18px;
          display: flex;
          gap: 10px;
          flex-wrap: wrap;
        }

        .btn-link {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          text-decoration: none;
        }

        .tema-hint {
          margin-top: 6px;
          font-size: 12px;
          color: #6e8aaa;
        }

        .tema-help-box {
          margin-top: 10px;
          padding: 12px 16px;
          background: #f0f7ff;
          border: 1px solid #bdd6f0;
          border-left: 4px solid #2f6fa7;
          border-radius: 8px;
          font-size: 13px;
          color: #1b3a5c;
          line-height: 1.55;
        }

        .tema-help-box strong {
          display: block;
          font-size: 13.5px;
          margin-bottom: 4px;
          color: #1b2d43;
        }

        .tema-help-box.is-placeholder {
          color: #7a93ad;
          font-style: italic;
          border-left-color: #b8cfe4;
        }

        .status {
          margin-top: 10px;
          font-size: 13px;
          padding: 9px 11px;
          border-radius: 9px;
          display: none;
        }

        .status.ok {
          display: block;
          background: #ebf8f0;
          border: 1px solid #9fd7b7;
          color: #1f6a43;
        }

        .status.error {
          display: block;
          background: #fff3f3;
          border: 1px solid #f3bbbb;
          color: #9b2d2d;
        }

        .request-panel {
          margin-bottom: 18px;
          padding: 14px 16px;
          border-radius: 12px;
          border: 1px solid #dbe6f1;
          background: linear-gradient(180deg, #f9fbfe, #f3f8fd);
        }

        .request-panel h3 {
          margin: 0 0 6px;
          font-size: 15px;
          color: #173755;
        }

        .request-panel p {
          margin: 0 0 10px;
          font-size: 13px;
          color: #53708c;
        }

        .request-list {
          display: grid;
          gap: 10px;
        }

        .request-item {
          border: 1px solid #d6e2ef;
          border-radius: 10px;
          padding: 12px 14px;
          background: #fff;
        }

        .request-item-head {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          flex-wrap: wrap;
          margin-bottom: 8px;
        }

        .request-badge {
          display: inline-flex;
          align-items: center;
          padding: 5px 10px;
          border-radius: 999px;
          background: #edf4fb;
          color: #1f4c78;
          font-size: 12px;
          font-weight: 600;
        }

        .request-meta {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 8px 14px;
          font-size: 12.5px;
          color: #35516d;
        }

        .request-meta strong {
          color: #173755;
        }

        .request-actions {
          margin-top: 10px;
        }

        .result-wrap {
          overflow: auto;
          border: 1px solid #dee7f1;
          border-radius: 10px;
        }

        table {
          width: 100%;
          border-collapse: collapse;
          min-width: 820px;
        }

        th,
        td {
          text-align: left;
          padding: 10px 12px;
          font-size: 13px;
          border-bottom: 1px solid #edf2f8;
          vertical-align: top;
        }

        th {
          background: #f6f9fc;
          color: #465c74;
          font-size: 12px;
          text-transform: uppercase;
          letter-spacing: .3px;
        }

        tr.active {
          background: #f0f7ff;
        }

        .hint {
          color: #5d728a;
          font-size: 12px;
          margin-top: 8px;
        }

        .grid {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 12px;
        }

        .grid-1 {
          grid-column: 1 / -1;
        }

        .is-hidden {
          display: none;
        }

        @media (max-width: 900px) {
          .grid {
            grid-template-columns: 1fr;
          }

          .hero h1 {
            font-size: 24px;
          }

          .hero {
            flex-direction: column;
            align-items: flex-start;
          }

          .hero-castor {
            width: 108px;
            height: 108px;
          }
        }
      </style>
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
        <script>
          (function() {
            var app = document.getElementById('public-ticket-app');
            if (!app) return;

            var runtime = {};
            try {
              runtime = JSON.parse(app.getAttribute('data-runtime') || '{}');
            } catch (e) {
              runtime = {};
            }

            var lookupForm = document.getElementById('lookup-form');
            var createForm = document.getElementById('create-form');
            var lookupInput = document.getElementById('lookup_value');
            var lookupStatus = document.getElementById('lookup-status');
            var createStatus = document.getElementById('create-status');
            var head = document.getElementById('lookup-head');
            var body = document.getElementById('lookup-body');
            var selectionHint = document.getElementById('selection-hint');
            var step1Card = document.getElementById('step-1');
            var step2Card = document.getElementById('step-2');
            var step3Card = document.getElementById('step-3');
            var requestStatus = document.getElementById('request-status');
            var requestResults = document.getElementById('request-results');
            var backStep1Btn = document.getElementById('back-step-1');
            var backStep2Btn = document.getElementById('back-step-2');
            var defaultSelectionHint = selectionHint ? selectionHint.textContent : '';

            var selectedId = '';
            var selectedRow = null;
            var rows = [];
            var lookupMessage = '';

            function setStatus(el, kind, message) {
              if (!el) return;
              el.className = 'status';
              if (!message) {
                el.style.display = 'none';
                el.innerHTML = '';
                return;
              }
              el.style.display = 'block';
              el.classList.add(kind === 'ok' ? 'ok' : 'error');
              el.innerHTML = message;
            }

            function esc(value) {
              return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            }

            function post(action, data) {
              var fd = new FormData();
              fd.append('action', action);
              fd.append('actor', runtime.actor || '');
              fd.append('nonce', runtime.nonce || '');
              Object.keys(data || {}).forEach(function(key) {
                fd.append(key, data[key] == null ? '' : String(data[key]));
              });
              return fetch(runtime.apiUrl || '', {
                  method: 'POST',
                  body: fd,
                  credentials: 'same-origin'
                })
                .then(function(r) {
                  return r.json();
                });
            }

            function setStepVisible(stepEl, visible) {
              if (!stepEl) return;
              stepEl.classList.toggle('is-hidden', !visible);
            }

            function showStepFlow(step2Visible, step3Visible) {
              var activeStep = step3Visible ? 3 : (step2Visible ? 2 : 1);
              setStepVisible(step1Card, activeStep === 1);
              setStepVisible(step2Card, activeStep === 2);
              setStepVisible(step3Card, activeStep === 3);
            }

            function renderLookupPlaceholder(message) {
              if (!head || !body) return;
              head.innerHTML = '';
              body.innerHTML = '<tr><td style="padding:16px;color:#5a6f86;">' + esc(message) + '</td></tr>';
            }

            function renderRequestPlaceholder(message) {
              if (!requestResults) return;
              requestResults.innerHTML = '<div class="hint">' + esc(message) + '</div>';
            }

            function renderRequestResults(items) {
              if (!requestResults) return;
              if (!Array.isArray(items) || !items.length) {
                renderRequestPlaceholder('No encontramos solicitudes registradas para esta seleccion.');
                return;
              }

              var html = '';
              items.forEach(function(item) {
                html += '<article class="request-item">';
                html += '<div class="request-item-head">';
                html += '<strong>Solicitud #' + esc(item.ticket_id || '-') + '</strong>';
                html += '<span class="request-badge">' + esc(item.estado || 'Sin estado') + '</span>';
                html += '</div>';
                html += '<div class="request-meta">';
                html += '<div><strong>Asunto:</strong> ' + esc(item.asunto || '-') + '</div>';
                html += '<div><strong>Tema:</strong> ' + esc(item.tema_ayuda || '-') + '</div>';
                html += '<div><strong>Fecha:</strong> ' + esc(item.fecha || '-') + '</div>';
                html += '<div><strong>Departamento:</strong> ' + esc(item.departamento || '-') + '</div>';
                if (item.contrato) {
                  html += '<div><strong>Contrato:</strong> ' + esc(item.contrato) + '</div>';
                }
                if (item.inmueble) {
                  html += '<div><strong>Inmueble:</strong> ' + esc(item.inmueble) + '</div>';
                }
                if (item.direccion) {
                  html += '<div><strong>Direccion:</strong> ' + esc(item.direccion) + '</div>';
                }
                html += '</div>';
                if (item.ticket_url) {
                  html += '<div class="request-actions"><a class="btn btn-secondary btn-link" href="' + esc(item.ticket_url) + '" target="_blank" rel="noopener noreferrer">Ver solicitud</a></div>';
                }
                html += '</article>';
              });

              requestResults.innerHTML = html;
            }

            function fetchRequestResults() {
              if (!selectedId || !selectedRow) {
                renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
                setStatus(requestStatus, 'ok', '');
                return;
              }

              renderRequestPlaceholder('Consultando solicitudes...');
              setStatus(requestStatus, 'ok', 'Consultando solicitudes...');
              post('public_ticket_requests', {
                  selected_id: selectedId,
                  lookup_value: (lookupInput.value || '').trim()
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudieron consultar las solicitudes.');
                  }

                  var items = Array.isArray(json.data.rows) ? json.data.rows : [];
                  renderRequestResults(items);
                  if (!items.length) {
                    setStatus(requestStatus, 'ok', 'No tienes solicitudes registradas para esta seleccion.');
                  } else {
                    setStatus(requestStatus, 'ok', 'Se encontraron ' + items.length + ' solicitud(es) registradas.');
                  }
                })
                .catch(function(err) {
                  renderRequestPlaceholder('No fue posible consultar tus solicitudes en este momento.');
                  setStatus(requestStatus, 'error', err.message || 'Error consultando solicitudes.');
                });
            }

            function renderLookupTable() {
              if (!head || !body) return;
              var isClient = runtime.actor === 'cliente';

              if (!rows.length) {
                renderLookupPlaceholder(lookupMessage || 'No se encontraron resultados para la consulta.');
                selectedId = '';
                selectedRow = null;
                showStepFlow(false, false);
                if (selectionHint) {
                  selectionHint.textContent = defaultSelectionHint;
                }
                return;
              }

              showStepFlow(true, false);

              if (isClient) {
                head.innerHTML = '<tr><th></th><th>Cliente</th><th>ID cliente</th><th>Correo</th><th>Celular</th><th>Tipo</th></tr>';
              } else {
                head.innerHTML = '<tr><th></th><th>Contrato</th><th>Inmueble</th><th>Direccion</th><th>Propietario</th><th>Arrendatario</th><th>Responsable</th></tr>';
              }

              var html = '';
              rows.forEach(function(row) {
                var checked = String(selectedId) === String(row.id) ? ' checked' : '';
                if (isClient) {
                  html += '<tr data-row-id="' + esc(row.id) + '">';
                  html += '<td><input type="radio" name="selected_row" value="' + esc(row.id) + '"' + checked + '></td>';
                  html += '<td>' + esc(row.nombre || row.display || '') + '</td>';
                  html += '<td>' + esc(row.cliente_id || '') + '</td>';
                  html += '<td>' + esc(row.correo || '') + '</td>';
                  html += '<td>' + esc(row.celular || '') + '</td>';
                  html += '<td>' + esc(row.tipo_cliente || '') + '</td>';
                  html += '</tr>';
                } else {
                  html += '<tr data-row-id="' + esc(row.id) + '">';
                  html += '<td><input type="radio" name="selected_row" value="' + esc(row.id) + '"' + checked + '></td>';
                  html += '<td>' + esc(row.contrato || row.id || '') + '</td>';
                  html += '<td>' + esc(row.inmueble || '') + '</td>';
                  html += '<td>' + esc(row.direccion || '') + '</td>';
                  html += '<td>' + esc(row.propietario || '') + '</td>';
                  html += '<td>' + esc(row.arrendatario || '') + '</td>';
                  html += '<td>' + esc(row.responsable_nombre || row.responsable_id || 'Sin asignar') + '</td>';
                  html += '</tr>';
                }
              });
              body.innerHTML = html;

              body.querySelectorAll('input[name="selected_row"]').forEach(function(input) {
                input.addEventListener('change', function() {
                  selectRow(this.value);
                });
              });

              body.querySelectorAll('tr[data-row-id]').forEach(function(tr) {
                tr.addEventListener('click', function(e) {
                  if (e.target && e.target.tagName === 'INPUT') return;
                  var rowId = this.getAttribute('data-row-id') || '';
                  var radio = this.querySelector('input[name="selected_row"]');
                  if (radio) {
                    radio.checked = true;
                  }
                  selectRow(rowId);
                });
              });
            }

            function selectRow(id) {
              selectedId = String(id || '');
              selectedRow = rows.find(function(item) {
                return String(item.id) === selectedId;
              }) || null;

              body.querySelectorAll('tr[data-row-id]').forEach(function(tr) {
                tr.classList.toggle('active', String(tr.getAttribute('data-row-id') || '') === selectedId);
              });

              if (!selectedRow) {
                if (selectionHint) {
                  selectionHint.textContent = defaultSelectionHint;
                }
                renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
                setStatus(requestStatus, 'ok', '');
                showStepFlow(true, false);
                return;
              }

              var name = selectedRow.contact_name || selectedRow.nombre || '';
              var email = selectedRow.contact_email || selectedRow.correo || '';
              var phone = selectedRow.contact_phone || selectedRow.celular || '';
              var indicativo = selectedRow.contact_indicativo || selectedRow.indicativo || '+57';
              var responsable = selectedRow.responsable_nombre || selectedRow.responsable_id || 'Sin asignar';

              document.getElementById('solicitante').value = name;
              document.getElementById('correo_solicitante').value = email;
              document.getElementById('celular_solicitante').value = phone;
              document.getElementById('indicativo').value = indicativo;

              if (selectionHint) {
                selectionHint.textContent = 'Seleccionado: ' + (selectedRow.display || selectedRow.contrato || selectedRow.nombre || selectedRow.id) + ' | Responsable: ' + responsable;
              }
              showStepFlow(true, true);
              fetchRequestResults();
            }

            function resetLookup() {
              rows = [];
              lookupMessage = '';
              selectedRow = null;
              selectedId = '';
              lookupInput.value = '';
              renderLookupPlaceholder('Realiza primero la consulta para ver resultados.');
              setStatus(lookupStatus, 'ok', '');
              setStatus(createStatus, 'ok', '');
              if (selectionHint) {
                selectionHint.textContent = defaultSelectionHint;
              }
              renderRequestPlaceholder('Selecciona primero un contrato o cliente para consultar tus solicitudes recientes.');
              setStatus(requestStatus, 'ok', '');
              showStepFlow(false, false);
              resetCreateForm();
            }

            function resetCreateForm() {
              createForm.reset();
              document.getElementById('indicativo').value = '+57';
              setStatus(createStatus, 'ok', '');
            }

            lookupForm.addEventListener('submit', function(e) {
              e.preventDefault();
              var lookupValue = (lookupInput.value || '').trim();
              if (!lookupValue) {
                setStatus(lookupStatus, 'error', 'Debes ingresar un valor para consultar.');
                return;
              }

              rows = [];
              selectedRow = null;
              selectedId = '';
              showStepFlow(false, false);
              renderLookupPlaceholder('Consultando...');
              setStatus(lookupStatus, 'ok', 'Consultando...');
              post('public_ticket_lookup', {
                  lookup_value: lookupValue
                })
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo consultar.');
                  }

                  rows = Array.isArray(json.data.rows) ? json.data.rows : [];
                  lookupMessage = (json.data && json.data.message) ? String(json.data.message) : '';
                  selectedId = '';
                  renderLookupTable();

                  if (!rows.length) {
                    setStatus(lookupStatus, 'error', lookupMessage || 'No se encontraron resultados.');
                  } else {
                    setStatus(lookupStatus, 'ok', 'Se encontraron ' + rows.length + ' resultado(s).');
                    if (selectionHint) {
                      selectionHint.textContent = defaultSelectionHint;
                    }
                  }
                })
                .catch(function(err) {
                  setStatus(lookupStatus, 'error', err.message || 'Error consultando datos.');
                  renderLookupPlaceholder('No fue posible completar la consulta.');
                  showStepFlow(false, false);
                });
            });

            createForm.addEventListener('submit', function(e) {
              e.preventDefault();

              if (!selectedRow || !selectedId) {
                setStatus(createStatus, 'error', 'Debes seleccionar un registro antes de crear el PQR.');
                return;
              }

              var asunto = (document.getElementById('asunto').value || '').trim();
              var descripcion = (document.getElementById('descripcion').value || '').trim();
              var correo = (document.getElementById('correo_solicitante').value || '').trim();
              var celular = (document.getElementById('celular_solicitante').value || '').trim();
              var solicitante = (document.getElementById('solicitante').value || '').trim();
              if (!asunto || !descripcion || !correo || !celular || !solicitante) {
                setStatus(createStatus, 'error', 'No pudimos radicar tu solicitud. Por favor revisa los campos obligatorios e intenta nuevamente.');
                return;
              }

              var payload = {
                selected_id: selectedId,
                actor_id: selectedRow.actor_id || '',
                lookup_value: (lookupInput.value || '').trim(),
                solicitante: solicitante,
                correo_solicitante: correo,
                celular_solicitante: celular,
                indicativo: (document.getElementById('indicativo').value || '').trim(),
                asunto: asunto,
                tema_ayuda: document.getElementById('tema_ayuda').value || '',
                descripcion: descripcion
              };

              setStatus(createStatus, 'ok', 'Creando PQR...');
              post('public_ticket_create', payload)
                .then(function(json) {
                  if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo crear el PQR.');
                  }

                  var data = json.data || {};
                  var redirectUrl = new URL(runtime.portalUrl || window.location.pathname || '/', window.location.origin);
                  redirectUrl.searchParams.set('radicado', '1');
                  if (data.ticket_id) {
                    redirectUrl.searchParams.set('ref', String(data.ticket_id).trim());
                  }
                  window.location.href = redirectUrl.toString();
                })
                .catch(function(err) {
                  setStatus(createStatus, 'error', 'No pudimos radicar tu solicitud. Por favor revisa los campos obligatorios e intenta nuevamente.');
                });
            });

            document.getElementById('lookup-clear').addEventListener('click', resetLookup);
            if (backStep1Btn) {
              backStep1Btn.addEventListener('click', function() {
                showStepFlow(false, false);
                setStatus(createStatus, 'ok', '');
              });
            }
            if (backStep2Btn) {
              backStep2Btn.addEventListener('click', function() {
                showStepFlow(true, false);
                setStatus(createStatus, 'ok', '');
              });
            }
            document.getElementById('create-clear').addEventListener('click', function() {
              if (!window.confirm('¿Estás seguro de que deseas borrar los datos del formulario?')) return;
              resetCreateForm();
            });

            // ── Dynamic topic helper ──────────────────────────────────────
            var TOPIC_DATA = {
              'Avaluo': {
                title: 'Avaluo',
                help: 'Indica el inmueble, la finalidad del avaluo y si cuentas con documentos como certificado de tradicion, impuesto predial o escritura.',
                asunto: 'Solicitud de avaluo',
                placeholder: 'Indica el inmueble, finalidad del avaluo, fecha requerida y documentos disponibles.'
              },
              'Actualizacion': {
                title: 'Actualizacion',
                help: 'Usa esta opcion para actualizar precio, disponibilidad, documentos, fotos, datos del propietario o condiciones comerciales del inmueble.',
                asunto: 'Actualizacion de informacion del inmueble',
                placeholder: 'Describe que informacion debe actualizarse y sobre que inmueble.'
              },
              'Captacion': {
                title: 'Captacion',
                help: 'Registra un inmueble nuevo para ofrecerlo en arriendo, venta o administracion.',
                asunto: 'Captacion de inmueble',
                placeholder: 'Indica direccion, tipo de inmueble, valor esperado, disponibilidad y datos relevantes.'
              },
              'Recaptacion': {
                title: 'Recaptacion',
                help: 'Reactivar un inmueble que ya estuvo vinculado anteriormente con la empresa.',
                asunto: 'Recaptacion de inmueble',
                placeholder: 'Indica cual inmueble deseas reactivar y si cambiaron precio, condiciones o disponibilidad.'
              },
              'Arriendo': {
                title: 'Arriendo',
                help: 'Solicitudes relacionadas con inmuebles en arriendo, canones, disponibilidad o proceso de arrendamiento.',
                asunto: 'Solicitud relacionada con arriendo',
                placeholder: 'Describe tu solicitud sobre el proceso de arriendo.'
              },
              'Venta': {
                title: 'Venta',
                help: 'Solicitudes relacionadas con venta de inmuebles, precio, promocion, negociacion o proceso comercial.',
                asunto: 'Solicitud relacionada con venta',
                placeholder: 'Describe tu solicitud sobre el proceso de venta.'
              },
              'Arriendo o venta': {
                title: 'Arriendo o venta',
                help: 'Para inmuebles que puedan ofrecerse tanto en arriendo como en venta.',
                asunto: 'Solicitud de arriendo o venta',
                placeholder: 'Indica si deseas publicar, cambiar condiciones o recibir asesoria sobre arriendo y venta.'
              },
              'Entrega de inmuebles': {
                title: 'Entrega de inmuebles',
                help: 'Coordinar o reportar la entrega de un inmueble al finalizar contrato, negociacion u ocupacion.',
                asunto: 'Entrega de inmuebles',
                placeholder: 'Indica fecha estimada de entrega, inmueble relacionado y observaciones.'
              },
              'Revision preventiva': {
                title: 'Revision preventiva',
                help: 'Solicita una revision anticipada del estado fisico, juridico o documental del inmueble.',
                asunto: 'Revision preventiva del inmueble',
                placeholder: 'Describe que deseas revisar y el motivo de la revision.'
              },
              'Recibo de inmuebles': {
                title: 'Recibo de inmuebles',
                help: 'Coordinar el recibo formal de un inmueble por parte de la empresa o propietario.',
                asunto: 'Recibo de inmuebles',
                placeholder: 'Indica fecha, inmueble, estado general y observaciones para el recibo.'
              },
              'Reparaciones necesarias': {
                title: 'Reparaciones necesarias',
                help: 'Reporta daños o reparaciones indispensables para conservar el inmueble o permitir su uso adecuado.',
                asunto: 'Reporte de reparacion necesaria',
                placeholder: 'Describe el daño, ubicacion dentro del inmueble, fecha en que se detecto y adjunta fotos si es posible.'
              },
              'Reparaciones locativas': {
                title: 'Reparaciones locativas',
                help: 'Reparaciones menores asociadas al uso ordinario, mantenimiento o desgaste normal.',
                asunto: 'Reporte de reparacion locativa',
                placeholder: 'Describe la reparacion menor requerida y el lugar exacto dentro del inmueble.'
              },
              'Mejoras utiles': {
                title: 'Mejoras utiles',
                help: 'Mejoras que aumentan la funcionalidad, valor o aprovechamiento del inmueble.',
                asunto: 'Solicitud de mejora util',
                placeholder: 'Describe la mejora propuesta, su finalidad y si requiere autorizacion previa.'
              },
              'Reparaciones voluntarias': {
                title: 'Reparaciones voluntarias',
                help: 'Intervenciones no indispensables, normalmente esteticas o de conveniencia.',
                asunto: 'Solicitud de reparacion voluntaria',
                placeholder: 'Describe la reparacion voluntaria, motivo y quien asumiria el costo.'
              },
              'Contable y tributaria': {
                title: 'Contable y tributaria',
                help: 'Consulta pagos, estados de cuenta, impuestos, retenciones, facturacion o informacion contable.',
                asunto: 'Consulta contable o tributaria',
                placeholder: 'Indica el periodo, contrato, inmueble o documento sobre el cual necesitas informacion.'
              },
              'Certificaciones tributarias': {
                title: 'Certificaciones tributarias',
                help: 'Solicita certificados de retencion, pagos, ingresos u otros soportes tributarios.',
                asunto: 'Solicitud de certificacion tributaria',
                placeholder: 'Indica el certificado requerido, año gravable y datos del propietario.'
              },
              'Procesos juridicos': {
                title: 'Procesos juridicos',
                help: 'Cobros, incumplimientos, restituciones, reclamaciones o procesos legales.',
                asunto: 'Solicitud relacionada con proceso juridico',
                placeholder: 'Describe el caso, contrato relacionado, fechas importantes y documentos de soporte.'
              },
              'Solicitud contractual': {
                title: 'Solicitud contractual',
                help: 'Contratos, otrosies, prorrogas, terminaciones, cesiones o modificaciones contractuales.',
                asunto: 'Solicitud contractual',
                placeholder: 'Indica que documento o modificacion necesitas y el contrato relacionado.'
              },
              'Solicitud de servicios publicos': {
                title: 'Solicitud de servicios publicos',
                help: 'Gestiones sobre energia, agua, gas, internet u otros servicios publicos.',
                asunto: 'Solicitud sobre servicios publicos',
                placeholder: 'Indica el servicio, numero de cuenta o factura, inmueble y situacion presentada.'
              },
              'Reparaciones antes de la entrega': {
                title: 'Reparaciones antes de la entrega',
                help: 'Reparaciones que deben realizarse antes de entregar el inmueble.',
                asunto: 'Reparaciones antes de la entrega',
                placeholder: 'Describe las reparaciones pendientes antes de la entrega del inmueble.'
              },
              'Reparaciones antes del recibo': {
                title: 'Reparaciones antes del recibo',
                help: 'Reparaciones o pendientes que deben revisarse antes de recibir formalmente el inmueble.',
                asunto: 'Reparaciones antes del recibo',
                placeholder: 'Describe los pendientes que deben resolverse antes del recibo formal.'
              }
            };

            var tipoSelect = document.getElementById('tema_ayuda');
            var helpBox = document.getElementById('tema-help-box');
            var asuntoInput = document.getElementById('asunto');
            var descTextarea = document.getElementById('descripcion');

            function applyTopicData(val) {
              var td = val ? TOPIC_DATA[val] : null;
              if (!td) {
                if (helpBox) {
                  helpBox.className = 'tema-help-box is-placeholder';
                  helpBox.innerHTML = 'Selecciona un tema para ver una guía sobre cómo diligenciar tu solicitud.';
                }
                return;
              }
              if (helpBox) {
                helpBox.className = 'tema-help-box';
                helpBox.innerHTML = '<strong>' + td.title + '</strong>' + td.help;
              }
              if (asuntoInput && asuntoInput.value.trim() === '') {
                asuntoInput.value = td.asunto;
              }
              if (descTextarea && !descTextarea.value.trim()) {
                descTextarea.placeholder = td.placeholder;
              }
            }

            if (tipoSelect) {
              tipoSelect.addEventListener('change', function() {
                applyTopicData(this.value);
              });
              if (tipoSelect.value) {
                applyTopicData(tipoSelect.value);
              } else if (helpBox) {
                helpBox.className = 'tema-help-box is-placeholder';
              }
            }

            showStepFlow(false, false);
            renderLookupPlaceholder('Realiza primero la consulta para ver resultados.');
          })();
        </script>
      <?php endif; ?>
      <script>
        (function() {
          if (window.self === window.top) return;
          var actor = <?php echo json_encode($actor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

          function sendHeight() {
            var body = document.body;
            var doc = document.documentElement;
            if (!body || !doc) return;
            var height = Math.max(
              body.scrollHeight,
              body.offsetHeight,
              doc.clientHeight,
              doc.scrollHeight,
              doc.offsetHeight
            );
            window.parent.postMessage({
              type: 'scm-public-portal-height',
              actor: actor,
              height: height
            }, '*');
          }

          window.addEventListener('load', sendHeight);
          window.addEventListener('resize', sendHeight);
          if (window.MutationObserver) {
            var observer = new MutationObserver(function() {
              sendHeight();
            });
            observer.observe(document.body, {
              attributes: true,
              childList: true,
              subtree: true
            });
          }
          setTimeout(sendHeight, 60);
          setTimeout(sendHeight, 320);
          setTimeout(sendHeight, 900);
        })();
      </script>
    </body>

    </html>
<?php
  }
}
