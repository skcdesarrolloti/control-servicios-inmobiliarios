<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/app.php';

use SCM\App\SuCasaControlServiciosInmobiliarios;
use SCM\Core\App;
use SCM\Core\Auth;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' https: data:; script-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$quoteId = (int) ($_GET['numero'] ?? $_GET['id_cotizacion'] ?? $_GET['id'] ?? 0);
$expires = (int) ($_GET['expires'] ?? 0);
$signature = is_string($_GET['sig'] ?? null) ? trim($_GET['sig']) : '';
$responseNotice = '';
$result = [
  'title' => 'Cotización de mantenimiento',
  'content' => '<article class="scm-cotizacion-native-doc"><section class="scm-cotizacion-native-section"><h2>No disponible</h2><p>No fue posible cargar la cotización.</p></section></article>',
  'status' => 500,
];

try {
  $hasPanelSession = Auth::isLoggedIn();
  $hasValidPublicSignature = SuCasaControlServiciosInmobiliarios::maintenanceQuotePublicSignatureValid($quoteId, $expires, $signature);
  if (!$hasPanelSession && !$hasValidPublicSignature) {
    $result = [
      'title' => 'Enlace no válido',
      'content' => '<article class="scm-cotizacion-native-doc"><section class="scm-cotizacion-native-section"><h2>Enlace no válido o vencido</h2><p role="alert">Solicita a la inmobiliaria un nuevo enlace para consultar esta cotización de mantenimiento.</p></section></article>',
      'status' => 403,
    ];
    http_response_code(403);
  } else {
    $app = new SuCasaControlServiciosInmobiliarios(App::db());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['scm_public_quote_response'] ?? '') === '1') {
      if (!$hasValidPublicSignature) {
        $responseNotice = '<div class="scm-public-quote-alert is-error">El enlace no es válido o está vencido. Solicita un nuevo enlace para responder.</div>';
      } else {
        $saved = $app->public_respond_cotizacion_mantenimiento(
          $quoteId,
          (string) ($_POST['estado'] ?? ''),
          (string) ($_POST['observacion'] ?? ''),
          (string) ($_POST['motivo'] ?? ''),
          (string) ($_POST['financiacion'] ?? ''),
          (string) ($_POST['plazo_financiacion'] ?? '')
        );
        $ok = (string) ($saved['ok'] ?? '0') === '1';
        if ($ok) {
          $estadoRespuesta = trim((string) ($_POST['estado'] ?? ''));
          $noticeTitle = $estadoRespuesta === 'Desaprobada' ? 'Cotización desaprobada' : 'Cotización aprobada';
          $noticeText = $estadoRespuesta === 'Desaprobada'
            ? 'Tu respuesta fue registrada correctamente. El equipo de SKC SuCasa Inmobiliaria revisará la observación y continuará el proceso.'
            : 'Tu aprobación fue registrada correctamente. El equipo de SKC SuCasa Inmobiliaria continuará con el proceso de mantenimiento.';
          $responseNotice = '<section class="scm-public-quote-confirmation" role="status">'
            . '<div><span>Respuesta registrada</span><strong>' . $escape($noticeTitle) . '</strong><p>' . $escape($noticeText) . '</p></div>'
            . '<a href="https://sucasainmobiliaria.com.co/" target="_blank" rel="noopener noreferrer">Ver página web</a>'
            . '</section>';
        } else {
          $responseNotice = '<div class="scm-public-quote-alert is-error">' . $escape((string) ($saved['message'] ?? 'No se pudo guardar la respuesta.')) . '</div>';
        }
      }
    }
    $result = $app->render_public_cotizacion_mantenimiento($quoteId);
    http_response_code((int) ($result['status'] ?? 200));
  }
} catch (Throwable $error) {
  http_response_code(500);
  error_log('[cotizacion-mantenimiento-publica] Error cotización #' . $quoteId . ': ' . $error->getMessage());
}

session_write_close();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $escape((string) ($result['title'] ?? 'Cotización de mantenimiento')) ?> · SuCasa</title>
  <link rel="icon" href="<?= $escape(system_image('portal_favicon_url', SCM_DEFAULT_PORTAL_FAVICON_URL)) ?>" sizes="32x32">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/admin/04-dashboard-pending.css?v=<?= $escape(SCM_VERSION) ?>">
  <link rel="stylesheet" href="assets/css/quote-print.css?v=<?= $escape(SCM_VERSION) ?>" media="print">
  <style>
    body{margin:0;background:#eef4fb;color:#0b1f3a;font-family:Poppins,Arial,sans-serif}
    #scm-app{max-width:1120px;margin:0 auto;padding:24px 14px 40px}
    .scm-public-quote-head{display:flex;align-items:center;gap:14px;margin:0 0 18px;padding:14px 18px;background:#fff;border:1px solid #d8e6f5;border-radius:18px;box-shadow:0 14px 35px rgba(15,23,42,.08)}
    .scm-public-quote-logo{display:flex;align-items:center;justify-content:center;width:190px;max-width:45vw;min-height:58px;background:#0b254f;border-radius:14px;padding:8px 14px}
    .scm-public-quote-logo img{display:block;max-width:100%;max-height:48px;object-fit:contain}
    .scm-public-quote-head p{margin:0;color:#64748b;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
    .scm-public-quote-head strong{display:block;font-size:22px;color:#0b1f3a}
    .scm-public-quote-response-guide{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 18px;padding:16px 18px;background:#fff7ed;border:1px solid #fed7aa;border-left:5px solid #ff8a00;border-radius:16px}
    .scm-public-quote-response-guide strong{display:block;font-size:16px;color:#0b254f}
    .scm-public-quote-response-guide p{margin:5px 0 0;font-size:13px;line-height:1.6;color:#475569}
    .scm-public-quote-response-guide a{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;background:#0b254f;color:#fff;border-radius:12px;font-weight:700;font-size:14px;text-decoration:none}
    .scm-public-quote-response-guide a:focus-visible{outline:3px solid #ff8a00;outline-offset:3px}
    #responder-cotizacion{scroll-margin-top:18px}
    @media(max-width:720px){.scm-public-quote-response-guide{align-items:stretch;flex-direction:column}.scm-public-quote-response-guide a{align-self:flex-start}}
    @media print{.scm-public-quote-response-guide{display:none!important}}
    .scm-public-quote-alert{margin:0 0 16px;padding:13px 16px;border-radius:14px;font-weight:700;border:1px solid}
    .scm-public-quote-alert.is-success{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
    .scm-public-quote-alert.is-error{background:#fff1f2;color:#be123c;border-color:#fecdd3}
    .scm-public-quote-confirmation{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:0 0 16px;padding:16px 18px;border-radius:18px;border:1px solid #a7f3d0;background:#ecfdf5;color:#064e3b;box-shadow:0 14px 32px rgba(16,185,129,.12)}
    .scm-public-quote-confirmation span{display:block;margin:0 0 3px;color:#047857;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
    .scm-public-quote-confirmation strong{display:block;color:#052e16;font-size:20px;font-weight:900}
    .scm-public-quote-confirmation p{margin:4px 0 0;color:#065f46;font-weight:600;line-height:1.45}
    .scm-public-quote-confirmation a{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;border-radius:13px;background:#ff8a00;color:#fff;text-decoration:none;font-weight:900;padding:12px 18px;box-shadow:0 10px 20px rgba(255,138,0,.22)}
    .scm-public-quote-response-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
    .scm-public-quote-response-form [hidden]{display:none!important}
    .scm-public-quote-response-form label{display:flex;flex-direction:column;gap:6px;color:#475569;font-size:12px;font-weight:800}
    .scm-public-quote-response-form label.is-wide{grid-column:1/-1}
    .scm-public-quote-response-form input,.scm-public-quote-response-form select,.scm-public-quote-response-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:12px;padding:11px 12px;font:500 14px Poppins,Arial,sans-serif;color:#0f172a;background:#fff}
    .scm-public-quote-response-form input[readonly]{background:#f1f5f9;color:#475569}
    .scm-public-quote-response-form .scm-public-quote-response-error{color:#b91c1c;font-weight:700}
    .scm-public-quote-response-form button{grid-column:1/-1;justify-self:start;border:0;border-radius:13px;background:#ff8a00;color:#fff;font:800 14px Poppins,Arial,sans-serif;padding:12px 18px;cursor:pointer}
    .scm-public-quote-financing{grid-column:1/-1;min-width:0;display:flex;flex-direction:column;gap:14px;padding:16px;border:1px solid #bfdbfe;border-radius:16px;background:#f8fbff}
    .scm-public-quote-financing-head span{display:block;color:#ff8a00;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
    .scm-public-quote-financing-head strong{display:block;margin-top:2px;color:#0b254f;font-size:18px;font-weight:800}
    .scm-public-quote-financing-head p{margin:4px 0 0;color:#475569;font-size:13px;line-height:1.55}
    .scm-public-quote-financing-options{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:8px}
    .scm-public-quote-response-form .scm-public-quote-financing-options button{grid-column:auto;justify-self:stretch;display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#0b254f;font:600 12px Poppins,Arial,sans-serif;text-align:left;box-shadow:none}
    .scm-public-quote-financing-options button strong{font-size:14px;font-weight:800}
    .scm-public-quote-financing-options button span{color:#475569}
    .scm-public-quote-response-form .scm-public-quote-financing-options button[aria-pressed="true"]{border-color:#0b254f;background:#0b254f;color:#fff}
    .scm-public-quote-financing-options button[aria-pressed="true"] span{color:#fde68a}
    .scm-public-quote-financing-options button:focus-visible{outline:3px solid #ff8a00;outline-offset:2px}
    .scm-public-quote-financing-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
    .scm-public-quote-financing-summary div{padding:10px 12px;border-radius:12px;background:#fff;border:1px solid #e2e8f0}
    .scm-public-quote-financing-summary div.is-main{background:#0b254f;border-color:#0b254f}
    .scm-public-quote-financing-summary span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
    .scm-public-quote-financing-summary strong{display:block;margin-top:2px;color:#0b254f;font-size:17px;font-weight:800}
    .scm-public-quote-financing-summary .is-main span{color:#fde68a}
    .scm-public-quote-financing-summary .is-main strong{color:#fff}
    .scm-public-quote-financing-table{overflow-x:auto;margin-top:10px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
    .scm-public-quote-financing-table table{width:100%;border-collapse:collapse;font-size:12px;color:#0f172a}
    .scm-public-quote-financing-table th,.scm-public-quote-financing-table td{padding:7px 10px;border-bottom:1px solid #eef2f7;text-align:right;white-space:nowrap}
    .scm-public-quote-financing-table th:first-child,.scm-public-quote-financing-table td:first-child{text-align:left}
    .scm-public-quote-financing-table thead th{background:#f1f5f9;color:#475569;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
    .scm-public-quote-financing-table tfoot td{font-weight:800;background:#f8fafc}
    .scm-public-quote-financing-hint{margin:0;color:#475569;font-size:13px;font-weight:600}
    .scm-public-quote-financing-note{margin:0;color:#64748b;font-size:11px;font-weight:500;line-height:1.55}
    @media (max-width:720px){.scm-public-quote-financing-summary{grid-template-columns:1fr}}
    @media (max-width:720px){.scm-public-quote-response-form{grid-template-columns:1fr}.scm-public-quote-head{align-items:flex-start;flex-direction:column}.scm-public-quote-logo{max-width:100%;width:170px}.scm-public-quote-confirmation{align-items:flex-start;flex-direction:column}.scm-public-quote-confirmation a{width:100%;box-sizing:border-box}}
    @media print{body{background:#fff}#scm-app{max-width:none;padding:0}.scm-public-quote-head,.scm-public-quote-alert,.scm-public-quote-confirmation,.scm-public-quote-response{display:none!important}#scm-app>.scm-cotizacion-native-doc{width:800px;max-width:none;box-sizing:border-box;margin:0 auto;zoom:.9}}
  </style>
</head>
<body>
  <main id="scm-app">
    <header class="scm-public-quote-head">
      <span class="scm-public-quote-logo"><img src="<?= $escape(system_image('portal_logo_url', SCM_DEFAULT_PORTAL_LOGO_URL)) ?>" alt="SKC SuCasa Inmobiliaria"></span>
      <div>
        <p>SKC SuCasa Inmobiliaria</p>
        <strong><?= $escape((string) ($result['title'] ?? 'Cotización de mantenimiento')) ?></strong>
      </div>
    </header>
    <?= $responseNotice ?>
    <?= (string) ($result['content'] ?? '') ?>
  </main>
  <script>
    (function () {
      var forms = document.querySelectorAll("[data-public-quote-response-form]");
      if (!forms.length) return;
      forms.forEach(function (form) {
        var state = form.querySelector("[data-public-quote-response-state]");
        var rejectWrap = form.querySelector("[data-public-quote-response-reject]");
        var approveWrap = form.querySelector("[data-public-quote-response-approve]");
        var rejectSelect = rejectWrap ? rejectWrap.querySelector("select") : null;
        var approveSelect = approveWrap ? approveWrap.querySelector("select") : null;
        var termWrap = form.querySelector("[data-public-quote-response-term]");
        var termInput = termWrap ? termWrap.querySelector("input") : null;
        function sync() {
          var value = state ? state.value : "";
          var isApproved = value === "Aprobada";
          var isRejected = value === "Desaprobada";
          if (rejectWrap) {
            rejectWrap.hidden = !isRejected;
            rejectWrap.style.display = isRejected ? "" : "none";
          }
          if (approveWrap) {
            approveWrap.hidden = !isApproved;
            approveWrap.style.display = isApproved ? "" : "none";
          }
          if (rejectSelect) {
            rejectSelect.disabled = !isRejected;
            rejectSelect.required = isRejected;
            if (!isRejected) rejectSelect.value = "";
          }
          if (approveSelect) {
            approveSelect.disabled = !isApproved;
            if (!isApproved) approveSelect.value = "";
          }
          // Con financiación Sí se pregunta a cuántos meses (hasta el máximo del propietario).
          var financed = isApproved && approveSelect && approveSelect.value === "Si";
          if (termWrap) {
            termWrap.hidden = !financed;
            termWrap.style.display = financed ? "" : "none";
          }
          if (termInput) {
            termInput.disabled = !financed;
            termInput.required = financed;
            if (!financed) termInput.value = "";
          }
          if (financing) financing.hidden = !financed;
          renderPlan();
        }
        // Simulación: la cuota de cada plazo y el plan de pagos de los meses elegidos (planes calculados en el servidor).
        var financing = form.querySelector("[data-public-quote-financing]");
        var planBox = financing ? financing.querySelector("[data-public-quote-financing-plan]") : null;
        var financingData = null;
        try {
          var dataNode = financing ? financing.querySelector("[data-public-quote-financing-data]") : null;
          financingData = dataNode ? JSON.parse(dataNode.textContent || "{}") : null;
        } catch (error) {
          financingData = null;
        }
        var money = new Intl.NumberFormat("es-CO", { style: "currency", currency: "COP", maximumFractionDigits: 0 });
        function renderPlan() {
          if (!financing || !planBox || !financingData) return;
          var term = termInput ? String(Number(termInput.value) || "") : "";
          var plan = financingData.plans ? financingData.plans[term] : null;
          financing.querySelectorAll("[data-public-quote-financing-term]").forEach(function (button) {
            button.setAttribute("aria-pressed", button.getAttribute("data-public-quote-financing-term") === term ? "true" : "false");
          });
          if (!plan) {
            planBox.innerHTML = '<p class="scm-public-quote-financing-hint">' + (term !== "" ? "No hay simulación para " + term + " meses: elige uno de los plazos de arriba." : "Elige un plazo o escribe los meses para ver tu plan de pagos.") + "</p>";
            return;
          }
          var rows = plan.rows.map(function (row, index) {
            return "<tr><td>" + (index + 1) + "</td><td>" + money.format(row[0]) + "</td><td>" + money.format(row[1]) + "</td><td>" + money.format(row[2]) + "</td><td>" + money.format(row[3]) + "</td></tr>";
          }).join("");
          planBox.innerHTML =
            '<div class="scm-public-quote-financing-summary">' +
            '<div class="is-main"><span>Cuota mensual estimada</span><strong>' + money.format(plan.payment) + "</strong></div>" +
            "<div><span>Total intereses</span><strong>" + money.format(plan.interest) + "</strong></div>" +
            "<div><span>Total a pagar en " + term + (term === "1" ? " mes" : " meses") + "</span><strong>" + money.format(plan.total) + "</strong></div></div>" +
            '<div class="scm-public-quote-financing-table"><table><thead><tr><th>Cuota</th><th>Valor cuota</th><th>Intereses</th><th>Abono a capital</th><th>Saldo</th></tr></thead><tbody>' + rows + "</tbody>" +
            '<tfoot><tr><td>Total</td><td>' + money.format(plan.total) + "</td><td>" + money.format(plan.interest) + "</td><td>" + money.format(financingData.amount) + "</td><td></td></tr></tfoot></table></div>";
        }
        if (financing) {
          financing.addEventListener("click", function (event) {
            var button = event.target.closest("[data-public-quote-financing-term]");
            if (!button || !termInput || termInput.disabled) return;
            termInput.value = button.getAttribute("data-public-quote-financing-term");
            checkTerm();
            renderPlan();
          });
        }
        // Si escriben más meses que el máximo se avisa al lado del campo y no se deja enviar.
        var termError = termWrap ? termWrap.querySelector("[data-public-quote-response-term-error]") : null;
        function checkTerm() {
          if (!termInput) return true;
          var max = Number(termInput.getAttribute("max")) || 0;
          var term = Number(termInput.value) || 0;
          var message = !termInput.disabled && max > 0 && term > max ? "El plazo máximo para financiar esta cotización es de " + max + " meses." : "";
          termInput.setCustomValidity(message);
          if (termError) {
            termError.textContent = message;
            termError.hidden = message === "";
          }
          return message === "";
        }
        if (termInput) termInput.addEventListener("input", function () { checkTerm(); renderPlan(); });
        if (state) state.addEventListener("change", sync);
        if (approveSelect) approveSelect.addEventListener("change", sync);
        form.addEventListener("submit", function (event) {
          sync();
          if (rejectSelect && !rejectSelect.disabled && !rejectSelect.value) {
            event.preventDefault();
            rejectSelect.focus();
          }
          if (!checkTerm()) {
            event.preventDefault();
            termInput.focus();
          }
        });
        sync();
      });
    })();
  </script>
</body>
</html>
