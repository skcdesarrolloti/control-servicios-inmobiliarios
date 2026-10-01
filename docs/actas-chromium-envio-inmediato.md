# Actas de recibo a satisfacción: diseño y envío inmediato

La vista pública y el PDF usan `CompletionDocument`. El diseño sigue la referencia del acta de recibo suministrada: documento blanco, logo institucional, tabla de datos, secciones numeradas y certificado de firma con pista de auditoría. La representación por nombre usa Caveat, distribuida bajo SIL OFL (licencia en `public/assets/fonts/caveat-OFL.txt`). No atribuye firmas al ejecutor ni al coordinador: el certificado muestra la firma registrada del destinatario y el cierre identifica a quien elaboró el acta.

## PDF

- `CompletionPdf` usa `HtmlPdfRenderer` y la configuración existente `SCM_GOTENBERG_URL`, `SCM_GOTENBERG_USERNAME` y `SCM_GOTENBERG_PASSWORD` (o Chromium local).
- El renderer recibe las mismas hojas de estilo que la vista pública. Las fotografías se verifican contra su SHA-256 y se incluyen como imágenes embebidas; la fuente de la firma también se incluye en el HTML enviado a Chromium.
- Si la conversión falla, la transacción de firma/cierre se revierte. No se guarda una versión con diseño alternativo.
- Los PDFs firmados originales se siguen validando con hash/HMAC y se devuelven sin regenerar, tanto al destinatario como al funcionario. El diseño nuevo aplica a las nuevas firmas.
- Si se usa la app Node incluida en `pdf-renderer-node`, volver a desplegarla para aplicar el límite de HTML de 32 MiB, necesario para las fotografías embebidas de actas grandes. `PDF_MAX_HTML_MB` permite configurar entre 8 y 48 MiB. Conserva las credenciales existentes. Gotenberg debe aceptar un tamaño equivalente.

## Mensajes

- Invitación, código y copia firmada se registran en `shared-notifications` y se intenta procesar **solo ese mensaje durante la petición**. No esperan el siguiente ciclo del cron.
- `TargetedNotificationStorage` limita el worker al ID y proyecto del mensaje. Se usan los proveedores, bloqueos, deduplicación, intentos y reintentos existentes del worker compartido.
- Si el proveedor devuelve un fallo temporal, el mensaje queda pendiente con el tiempo de reintento del worker. Si falla el registro, la interfaz informa el fallo; no confirma un envío inexistente.
- `sent` significa que el proveedor aceptó el envío, no que el destinatario abrió el correo o lo recibió en su bandeja principal.
- Los destinatarios siguen siendo el firmante seleccionado y sus contactos registrados. No se agregan destinatarios internos.
- Se conservan las plantillas de WhatsApp `scm_acta_solicitud_firma_v1`, `scm_acta_firma_otp_v1` y `scm_acta_firmada_v1` y sus variables.

## Verificación

`php tests/ticket-completion-check.php --database --pdf-fixture` verifica el flujo con tablas temporales y transporte simulado. `php tests/ticket-completion-delivery-check.php` verifica la cola y el worker con proveedores inertes, incluido el procesamiento inmediato aislado y el reintento tras fallo. Ninguna prueba envía mensajes externos ni modifica casos reales.
