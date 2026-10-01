# Actas de recibo a satisfacción: diseño y envío inmediato

La vista pública y el PDF usan `CompletionDocument`. El diseño sigue la referencia del acta de recibo suministrada: documento blanco, logo institucional, tabla de datos, secciones numeradas y certificado de firma con pista de auditoría. El texto del documento usa Noto Sans y la representación por nombre usa Caveat, ambas distribuidas bajo SIL OFL (licencias en `public/assets/fonts/`). Las dos fuentes se incluyen dentro del HTML y del PDF: el Chromium alojado no necesita tener Arial instalada ni descargar fuentes para el acta. No atribuye firmas al ejecutor ni al coordinador: el certificado muestra la firma registrada del destinatario y el cierre identifica a quien elaboró el acta.

## PDF

- `CompletionPdf` usa `HtmlPdfRenderer` y la configuración existente `SCM_GOTENBERG_URL`, `SCM_GOTENBERG_USERNAME` y `SCM_GOTENBERG_PASSWORD` (o Chromium local).
- El renderer recibe las mismas hojas de estilo que la vista pública. Las fotografías se verifican contra su SHA-256 y se incluyen como imágenes embebidas; las fuentes del cuerpo y de la firma también se incluyen en el HTML enviado a Chromium. Esto evita letras en cuadrados en servidores sin fuentes del sistema. Este ajuste de fuentes se despliega con el panel PHP, sin cambios adicionales en la app Node.
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

`php tests/ticket-completion-font-check.php` comprueba con Chromium local, sin solicitudes de fuentes externas, que el PDF incluye Noto Sans y Caveat y no usa Arial del sistema.

## Reparación explícita de un PDF firmado defectuoso

`bin/repair-ticket-completion-pdf.php` permite regenerar únicamente la presentación, usando el contenido y la firma guardados. Es una herramienta CLI; no expone una ruta pública. Exige ID y SHA-256 exacto del archivo defectuoso, valida los hashes/HMAC existentes y genera una copia para revisión antes de aplicar.

```powershell
php bin/repair-ticket-completion-pdf.php --id=9 --expected-sha256=SHA256_DEL_ARCHIVO --output=acta-revision.pdf
```

Después de verificar la copia, se puede ejecutar con otra ruta de salida y `--apply --reason="Corrección de fuentes"`. La tabla `scm_ticket_completion_pdf_repairs` conserva los bytes, hash y HMAC del original, la huella de la firma y del contenido, el nuevo hash, motivo, versión y fecha de reparación. El archivo disponible para descarga se actualiza con un nuevo hash/HMAC en la misma transacción. La firma, aceptación, fecha de firma, tokens, mensajes, ticket y cargos no se modifican; no se envían notificaciones. Si el respaldo falla o el registro cambió después de preparar la copia, no se aplica.

`php tests/ticket-completion-pdf-repair-check.php` comprueba con tablas temporales el respaldo exacto, la conservación del contenido y firma, el rechazo de hashes/candidatos obsoletos y el rollback si no se puede guardar la auditoría.

## Edición y eliminación de actas

- Desde el caso (Acta de solución y firma) o la gestión de cotización (Acta de cotización), el historial ofrece **Editar acta** mientras esté pendiente. También existe la acción en Actividades administrativas → Actas de satisfacción. Las firmadas no se editan.
- La edición conserva el flujo y la cotización del acta original. El permiso se resuelve a partir de ese origen guardado, no del flujo que envía el navegador. Al guardar se invalidan enlace/códigos anteriores y se intenta reenviar la invitación.
- La eliminación autorizada retira el registro nativo y el legacy asociado, y limpia el ID legacy exacto de tickets/cotizaciones, conservando otros IDs. Cuando no quedan actas, retira las marcas de acta/finalización relacionadas. La aprobación y las órdenes de la cotización no cambian.
- El estado del caso se restaura solo si se elimina su acta activa y, para una firmada, el ticket todavía referencia ese soporte. Borrar una acta retirada no restablece el caso ni afecta una acta posterior. Un ID previo que ya no existe no se restaura.
- Las fotos se eliminan solo si ninguna otra acta o revisión correctiva las referencia. Los historiales de creación, firma y eliminación permanecen como trazabilidad.
