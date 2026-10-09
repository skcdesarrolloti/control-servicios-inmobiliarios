# Servicios públicos críticos: revisión y caso nativo

Desde la versión **3.3.372**, la revisión crítica se atiende mediante un caso de Servicios inmobiliarios con `tema_ayuda = Servicio publico critico`. Sustituye el flujo independiente de reporte y verificación de pagos.

## Flujo

1. Guardar una revisión con uno o varios servicios en **Estado crítico** genera sus actas y **un único caso por revisión**, inicialmente asignado al creador.
2. La revisión, el caso, el vínculo y los historiales de caso e inmueble se guardan en la misma transacción. `cct_author_id` e historiales usan el `id_empleado` real.
3. El plazo del caso es de **72 horas calendario desde el registro de la revisión**, con fecha y hora de Colombia. Es independiente de la próxima revisión trimestral.
4. Se encolan avisos de revisión con actas para el arrendatario, el creador y la lista `internal_admin_notifications.servicios_publicos_critico`. WhatsApp utiliza un encabezado Documento y botón **Ver revisión** con URL firmada. Se prepara un WhatsApp por acta crítica y destinatario; se deduplican destinos y reintentos.
5. El caso aparece en vencimientos y en el popup con el grupo **Servicios críticos · 72 horas**. El acceso abre el caso nativo dentro del panel. El estado vencido se calcula a la hora exacta, incluso dentro del mismo día.
6. Los funcionarios seleccionados en el evento crítico reciben un recordatorio interno; se solicita Google Calendar solo para los que tienen cuenta conectada. El creador y el arrendatario no se agendan por el solo hecho de recibir el aviso.
7. La atención, respuestas, evidencias y cierre se realizan con el flujo normal del caso. Se consulta su estado real; cerrar/finalizar/anular el caso retira su vencimiento y el procesador solicita cancelar sus recordatorios. Esta integración no modifica `estado_administrativo` de casos existentes.

## Cambio del flujo anterior

- Se retiran los botones de reportar pago, el formulario público de comprobantes, la configuración de respuestas de pago y la verificación de pagos del módulo.
- Los enlaces de pago enviados anteriormente solo redirigen a la revisión si su firma sigue vigente. Los POST al endpoint retirado se rechazan con HTTP 405.
- Los comprobantes históricos y su auditoría se conservan como antecedentes de consulta.
- El procesador convierte los seguimientos antiguos **abiertos** en casos una sola vez, conservando el vencimiento original. No concede otras 72 horas. Registra la creación también en el historial del inmueble.
- Los seguimientos históricos ya verificados permanecen como antecedentes, sin crear casos retroactivos.
- Se cancelan los trabajos pendientes del flujo anterior y sus mensajes todavía pendientes en la cola compartida. Los mensajes ya entregados no pueden retirarse; sus enlaces públicos dejan de permitir cargas.
- Se requiere configurar y activar una **nueva plantilla de revisión**. La activación anterior no se hereda, para evitar mensajes con el botón de pago retirado.

## Configuración de WhatsApp

En **Servicios públicos → Plantillas de actas → Seguimiento crítico**, configurar una plantilla aprobada (nombre sugerido `scm_servicios_revision_critica`), su idioma exacto y los funcionarios del evento crítico. El encabezado lleva el acta PDF. Las cinco variables son destinatario, contrato, resumen con número de caso, vencimiento y creador. Botón URL dinámico **Ver revisión**, con base `SCM_BASE_URL` más `/{{1}}`.

La guía completa está en [Plantilla y proceso de servicios públicos](guia-plantillas-y-proceso-servicios-publicos.md).

## Despliegue

Ejecutar antes de registrar nuevas revisiones críticas:

```sh
php bin/migrate-public-services.php
```

Además del esquema de revisiones, la migración prepara `jet_cct_tickets`, `jet_cct_historial_del_ticket` y `jet_cct_historial_del_inmueble` como InnoDB si todavía usan otro motor. **Realizar la conversión en una ventana de mantenimiento, con respaldo de la base de datos**, porque `ALTER TABLE` puede bloquear tablas mientras las reconstruye. No cambia estados ni elimina registros.

El cron de `bin/queue-worker.php` procesa las integraciones antes del transporte compartido. Si producción solo ejecuta el worker global de shared-notifications, ejecutar además cada minuto:

```sh
php bin/process-public-services-critical.php 10
```

Este procesador convierte seguimientos abiertos anteriores, prepara avisos, sincroniza el cierre del caso y procesa la API del calendario. No envía mensajes directamente. Conservar el almacenamiento de comprobantes antiguos en `storage/public-services-payments/`.

La integración del calendario utiliza `SCM_CALENDAR_API_URL` y, cuando corresponde, `SCM_CALENDAR_API_KEY` como cabecera `X-SKC-Calendar-Key`. Recupera recordatorios por `origen_app` y `external_ref` para evitar duplicados. Si Google queda pendiente, reintenta sobre el mismo registro.

## Verificación

- `tests/public-services-review-check.php`: tablas temporales, revisión y caso, historial del inmueble, autor real, idempotencia, cola compartida, vencimiento, cierre, conversión del flujo anterior y rollback. Simula la migración InnoDB solo en sus tablas temporales.
- `tests/public-services-critical-calendar-check.php`: destinatarios activos, recordatorios, conexión Google, reintento sin duplicados y cierre del caso, con API simulada.
- `tests/public-services-workspace-check.cjs`: apertura del caso nativo, filtros, plantilla única y regresiones de interfaz en escritorio y móvil.
- `tests/public-services-critical-upload-check.cjs`: comprueba que el endpoint público retirado rechaza nuevas cargas.

No confundir un trabajo encolado con un mensaje entregado. Los intentos de transporte se consultan en shared-notifications. Estas pruebas no llaman proveedores reales ni crean eventos reales de Google.
