# Casos jurídicos y comunicaciones a terceros

Menú: **Actividades contractuales › Casos jurídicos** (pestaña `casos_juridicos`). Son expedientes propios del área contractual, separados de los tickets: no tocan `estado_administrativo`, ni métricas de casos.

## Puesta en marcha

1. `php bin/migrate-legal-cases.php` crea las tablas `scm_legal_*`. También se crean solas la primera vez que alguien abre el módulo.
2. En **Permisos por cargo**, marcar la pestaña **Casos jurídicos** y las acciones del grupo **Casos jurídicos** en los cargos que corresponda. Nacen apagadas.
3. En **Permisos por cargo › Alertas**, activar **Casos jurídicos · fecha límite** en los cargos ya guardados si deben verlo en el calendario.
4. En **Notificaciones internas › Casos jurídicos › Fecha límite de casos jurídicos**, elegir quién recibe copia del recordatorio.
5. Crear y aprobar en Meta la plantilla de WhatsApp: `docs/crear-plantilla-whatsapp-comunicacion-caso.md`.

El aviso de fecha límite corre con el cron existente de `bin/send-daily-task-reminder.php`: tres días antes y al cumplirse la fecha, en días hábiles desde las 9:00 a. m.

## Comunicaciones

- Salen por `shared-notifications`: correo por SMTP y WhatsApp con la plantilla `scm_comunicacion_caso_v1`.
- Las respuestas por correo llegan al buzón del funcionario que envió (Reply-To). Se registran a mano con **Registrar respuesta**.
- WhatsApp solo avisa y enlaza a `public/comunicacion-caso.php`, con enlace firmado de 180 días. No se capturan respuestas.
- El envío es todo o nada: si un destinatario no se puede encolar, no se envía a nadie.
- Las copias van únicamente en el primer correo, con el texto personalizado para ese destinatario.
- Lo enviado no se edita ni se borra. Tras el envío solo cambian los campos de estado de cada destinatario.

## Estados

`En cola`, `Aceptado por el servidor`, `Fallido` y `Cancelado`, leídos de la cola por `dedupe_key`. **"Aceptado" no confirma entrega ni lectura**, y los PDF de constancia lo aclaran.

Para sumar un proveedor transaccional o webhooks, extender `LegalDeliveryGateway` y agregar los estados nuevos en `LegalCommunicationService`.

## Verificación

- `php tests/legal-case-flow-check.php`: reglas de negocio con SQLite y cola simulada.
- `node tests/legal-cases-ui-check.cjs`: panel en navegador con servidor simulado.
- `node tests/legal-communication-public-check.cjs`: vista pública e impresión.
