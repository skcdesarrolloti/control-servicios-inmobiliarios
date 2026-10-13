# Respuesta de no prórroga por WhatsApp

Plantilla: `scm_no_prorroga_contrato_respuesta`, idioma `es_CO`.
Orden confirmado con la plantilla configurada en Meta el 13/10/2026:

| Variable del cuerpo | Dato |
| --- | --- |
| `{{1}}` | Nombre del destinatario |
| `{{2}}` | Número del contrato |
| `{{3}}` | Número SIMI del inmueble |
| `{{4}}` | Número del caso |
| `{{5}}` | Clasificación: dentro/fuera de término |
| `{{6}}` | Firma del funcionario |

El botón de acta usa URL dinámica con base `https://sucasainmobiliaria.com.co/`.
Se envía un componente `button`, `sub_type: url`, `index: 0` con la ruta del
documento y su consulta completa, incluida la firma del enlace. El botón
estático del Bot Guardián no requiere parámetros.

La respuesta se encola mediante `SmsQueue` y `shared-notifications`. Sus
destinatarios y canales conservan la selección del formulario y el evento de
Notificaciones internas `no_prorroga_contrato`.

Un rechazo `132000` con detalle `localizable_params (3) ... params (6)` se
debía al envío anterior de nombre, resumen y firma, en vez de los seis campos.
Reintentar un registro antiguo sin corregir su `payload_json` reproduce el
error aunque el código de creación ya esté actualizado. Conservar el historial
de intentos al corregir esos registros.

Validación: `php tests/contract-request-notifications-check.php` usa tablas
temporales y proveedores inertes; no envía mensajes reales.
