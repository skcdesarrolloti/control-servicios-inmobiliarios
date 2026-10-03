# Seguimiento de servicios públicos críticos

La versión 3.3.348 crea seguimiento para las nuevas revisiones nativas con resultado `Estado critico`. No envía requerimientos retroactivos ni modifica las actas históricas.

1. El funcionario registra el servicio crítico, la referencia, el medidor y la deuda. La revisión, las actas, el contrato y el seguimiento se guardan juntos. La fecha límite es la hora del registro más **72 horas calendario**, en Colombia; incluye fines de semana. No depende del mes trimestral de revisión.
2. Se generan las actas PDF y se registra un seguimiento independiente. Las nuevas actas críticas incluyen la fecha límite. El editor conserva las plantillas y admite `{{fecha_limite}}`; la redacción anterior de 48 horas del modelo suministrado pasa a 72 horas para las actas nuevas.
3. Se preparan avisos al arrendatario, al creador y a los funcionarios seleccionados en `servicios_publicos_critico`. Correo y WhatsApp se encolan en **shared-notifications**; no hay un segundo transportador. Cada acta crítica se adjunta a un WhatsApp con botón dinámico **Realicé el pago**. Los correos incluyen las actas y enlace público.
4. Las personas seleccionadas en `servicios_publicos_critico_calendario` reciben un recordatorio a la hora exacta del vencimiento. La API existente del calendario programa el aviso por correo y solicita la sincronización con Google Calendar usando el `id_empleado` real. Cada persona debe tener su cuenta Google conectada y el servidor debe permitir sincronizar recordatorios. El seguimiento aparece también en el calendario de vencimientos y su popup, sujeto a los permisos/configuración del panel.
5. El botón abre `pago-servicios-publicos.php` con firma HMAC y vencimiento. El arrendatario ve la revisión y las actas sin nombre del propietario; puede previsualizar y subir un PDF de máximo 10 MB. El servidor valida contenido, tamaño, sesión del formulario, hash e idempotencia; guarda el archivo en almacenamiento privado. Se conserva la hora de recepción, incluso después del vencimiento.
6. El caso queda en **pago reportado, pendiente de verificación**. Se encolan WhatsApp con PDF y correos al creador, a los responsables del requerimiento y a la lista adicional `servicios_publicos_pago_reportado`. El mismo PDF no duplica el reporte ni los avisos.
7. En **Revisiones realizadas → Seguimiento · 72 horas**, un administrador puede consultar los soportes dentro del popup y confirmar o rechazar, con motivo y confirmación explícita. Confirmar cierra el vencimiento y solicita cancelar el recordatorio; rechazar conserva el plazo original. Ningún archivo cierra automáticamente el caso, cambia el estado del servicio o crea una revisión ficticia. Se conservan actas y auditoría.

## Configuración y activación

En **Servicios públicos → Plantillas de actas → Seguimiento crítico**, un administrador configura las tres listas, el nombre de las dos plantillas aprobadas y el idioma. Los eventos también aparecen en **Notificaciones internas**. Estos eventos admiten funcionarios activos de todos los cargos, sin ampliar destinatarios de otros módulos.

Las dos plantillas propuestas están documentadas en ese formulario:

- `scm_servicios_critico_72h`: encabezado Documento, cinco variables de cuerpo y botón URL **Realicé el pago**.
- `scm_servicios_pago_reportado`: encabezado Documento, las mismas cinco posiciones y botón URL **Ver revisión**.

Variables: nombre del destinatario, contrato, resumen del requerimiento/respuesta, fecha límite y nombre del creador. La base del botón es `SCM_BASE_URL` con barra final y una variable dinámica para la ruta y parámetros firmados. Meta debe aprobar ambas plantillas antes de activar WhatsApp; hasta entonces los trabajos quedan pendientes, con error visible y reintentos. No se sustituyen por un proveedor de texto libre.

Despliegue:

```sh
php bin/migrate-public-services.php
```

Conservar `storage/public-services-payments/` entre despliegues, fuera de la raíz pública. Se incluye `.htaccess` de denegación; PHP sirve PDFs únicamente mediante sesión del panel o firma específica de evidencia. Configurar `upload_max_filesize` y `post_max_size` para aceptar PDF de 10 MB más el formulario.

El cron existente de `bin/queue-worker.php` procesa las integraciones críticas antes del transporte compartido. Si producción usa únicamente el worker global de shared-notifications, programar **además** cada minuto:

```sh
php bin/process-public-services-critical.php 10
```

Este script procesa efectos de negocio y la API del calendario; no entrega mensajes. Evita solapamientos locales y los trabajos tienen bloqueo temporal. No ejecutar workers de prueba contra destinatarios reales.

La API usa HTTPS y, si corresponde, `SCM_CALENDAR_API_KEY` como cabecera `X-SKC-Calendar-Key`. `SCM_CALENDAR_API_URL` permite cambiar la base, cuyo valor predeterminado termina en `/calendario-actividades/index.php?action=`. No guardar la clave en el frontend. La integración recupera recordatorios por `origen_app` y `external_ref` antes de crear; si Google queda pendiente, reintenta sobre el registro existente.

## Trazabilidad y comprobación

Los estados de trabajos internos representan planificación/encolado o integración con calendario. La entrega efectiva y sus intentos se consultan en la cola compartida. Los contactos faltantes, errores de configuración y sincronización quedan visibles en el seguimiento; la carga del PDF no acredita pago bancario ni garantiza entrega de mensajes.

Pruebas: `tests/public-services-review-check.php` usa tablas TEMPORARY e incluye 107 verificaciones del flujo y sus regresiones. `tests/public-services-workspace-check.cjs` verifica interfaz y confirmación. Con el servidor local de pruebas y el PDF sintético generado, `tests/public-services-critical-upload-check.cjs` prueba multipart real, rechazo de PDF falso, deduplicación y avisos de respuesta. No invocan transportadores reales ni Google real.
