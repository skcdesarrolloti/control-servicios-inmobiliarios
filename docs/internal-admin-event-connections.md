# Conexiones de Notificaciones internas administrativas

Los siete eventos que estaban pendientes están conectados a `shared-notifications`.
Se conservan los funcionarios seleccionados en `internal_admin_notifications`.
Una acción vacía desactiva sus avisos. Se validan funcionarios activos y correo;
el autor no se incorpora automáticamente. Si el correo tiene actores externos,
los internos reciben CC; en un aviso exclusivamente interno, uno queda en Para
y los demás en CC.

| Evento | Momento y destinatarios |
| --- | --- |
| Agregar nota | Después de guardar la nota, solo a internos configurados. Nunca comparte la nota con clientes. |
| Trasladar caso | Copias del traslado al seleccionar administración; usa `trasladar_caso`, no el evento general. Conserva los avisos existentes al responsable. |
| Postergar caso | Copias al seleccionar administración; usa `postergar_ticket`, no seguimiento. Conserva la regla existente de estado Postergado. |
| Agendar cita | Aviso interno cuando existe una cita guardada y vinculada al historial de un caso. |
| Cita realizada | Aviso interno cuando esa cita pasa a realizada. Una reapertura y nueva realización son una nueva gestión. |
| Comunicación por no autorización | CC del correo con el documento por falta de acceso. Si falta correo válido del arrendatario, genera un aviso solo interno. Conserva el WhatsApp existente. |
| Acta preventiva | CC de las cartas de ofrecimiento al crear el caso con administración marcada, junto con las copias del evento de creación. También avisa al enviar la revisión preventiva realizada desde JetForm. |

Los ofrecimientos conservan el documento correspondiente a cada actor. Las
revisiones realizadas se reconocen en `jet_cct_revision_preventiva` cuando
`se_envio = Si`; un borrador no genera un aviso de acta enviada.

Calendario y JetForm guardan datos fuera del panel. `InternalAdminExternalEvents`
observa sus registros confirmados; no modifica esos registros ni sus correos
propios. El panel sincroniza antes/después de crear o completar eventos de
calendario, y cada minuto mientras está visible. También se ejecuta desde
`bin/queue-worker.php` y `bin/warm-dashboard-performance.php`.
Para continuar con el panel cerrado, el cron debe ejecutar uno de esos comandos;
el worker genérico de shared-notifications solo transporta mensajes ya encolados.

La primera sincronización registra la situación actual como punto de partida:
no manda avisos del historial. El estado se guarda en la configuración bajo
`internal_admin_external_event_state`, separado de la selección de destinatarios.
Un bloqueo evita escaneos simultáneos. Estado y encolado comparten transacción;
un fallo no marca el evento como procesado. Las claves de deduplicación usan
el registro fuente y la generación de la gestión. Una configuración vacía no
acumula avisos históricos para enviarlos al seleccionar funcionarios después.

Validación: `tests/internal-admin-connected-events-check.php` cubre los siete
eventos, privacidad, CC, historial real, borradores, duplicados, fallos y reintentos
con tablas temporales y proveedor inerte. `tests/internal-admin-events-calendar-check.cjs`
comprueba ambas rutas del calendario y su política de errores sin acceder a la red.
No se envían mensajes reales durante las pruebas.
