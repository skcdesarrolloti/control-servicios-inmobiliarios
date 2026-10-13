# CC al funcionario de la acción

Los correos nuevos de Control Servicios Inmobiliarios agregan en CC al
funcionario activo que realiza la acción, usando su correo en el CCT de
funcionarios. No se emplean destinatarios administrativos fijos.

La opción **Agregar al funcionario en CC de los correos de la aplicación**
aparece en **Notificaciones internas**, está activada por defecto y se guarda
como `email_actor_cc_enabled` en la configuración del sistema. Los permisos,
CSRF y transacción son los mismos de la configuración de destinatarios.
Clientes anteriores que no envían ese campo conservan su valor.

## Cobertura

`ActorCopyNotificationStorage` aplica la regla al insertar un correo en la
cola del proyecto desde `SharedNotificationsBridge`. Cubre `EmailQueue` y las
llamadas directas a la cola compartida. Notificaciones administrativas, que
inserta directamente en SQL, usa la misma política antes de guardar.

Esto incluye respuestas y seguimientos de casos, terminación/no prórroga,
retención, cotizaciones, órdenes, revisiones, aumentos, liquidación de servicios,
cartera, siniestros, auditoría de canon y notificaciones administrativas.

Los destinatarios configurados por evento en `internal_admin_notifications`
siguen vigentes. La copia al actor es adicional y no crea otro trabajo en cola.
Si se mandan varios correos distintos, el funcionario recibe copia de cada uno.

## Identidad y procesos diferidos

- En acciones del panel se consulta al funcionario mediante el `_ID` de sesión;
  la trazabilidad registra su `id_empleado` real, no ese `_ID`.
- `meta.notification_actor_employee_id` identifica explícitamente al empleado
  de una gestión persistida. Se usa para recordatorios contractuales, avisos
  diferidos de servicios públicos críticos y actas.
- Las respuestas públicas de cotizaciones/órdenes pueden aportar
  `meta.notification_actor_email` del creador guardado. Solo se acepta si
  corresponde a un funcionario activo; el operador autenticado tiene prioridad
  sobre este correo de respaldo.
- En firma pública de actas se recupera al creador original, incluso si un
  funcionario diferente editó la versión pendiente.
- Acciones públicas o automáticas sin funcionario identificable no agregan CC.

La copia queda capturada al encolar. El cron y los reintentos usan ese mismo
destinatario, aunque otro funcionario opere después.

## Conservación y auditoría

La política combina las CC existentes, normaliza y deduplica correos, y evita
repetir al funcionario si ya está en `Para`. Si estaba en CCO, se promueve a CC.
Se conservan adjuntos, `Reply-To`, destinatarios, fechas y claves de deduplicación.
Si falta el correo del funcionario, se conserva la notificación principal.

`meta.actor_email_copy` deja el resultado (`cc`, `already_to`, `no_actor`,
`employee_unavailable`, `email_missing`, `disabled`, `lookup_failed` o
`signer_only`), con identidad y correo cuando se resolvieron.

Los códigos de verificación `signature_otp` se envían exclusivamente al firmante.
WhatsApp, SMS, otros proyectos y los historiales de intentos no se modifican.
Los mensajes ya encolados antes del cambio no se reescriben.

## Verificación

- `php tests/notification-actor-copy-check.php`: SQLite aislado; identidad,
  deduplicación, adjuntos, sesión, procesos sin sesión y exclusión de OTP.
- `php tests/notification-actor-copy-integration-check.php`: tablas temporales,
  cola real y proveedor inerte; `EmailQueue`, cola directa, SQL administrativo,
  configuración, proceso diferido y trazabilidad de intentos.
- `node tests/panel-case-settings-check.cjs`: control y guardado desde el modal.

Estas pruebas no envían correos ni mensajes reales.
