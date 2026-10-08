# Avisos de respuesta de terminación y no prórroga

En ambos formularios, **Notificar a** permite seleccionar arrendatario, propietario y los funcionarios del evento interno correspondiente. Cada destinatario tiene controles separados de **Correo** y **WhatsApp**. Un canal sin datos válidos queda deshabilitado; los contactos se vuelven a validar antes de generar el acta y cerrar el caso.

Los valores iniciales conservan el comportamiento anterior: arrendatario y propietario por los canales disponibles, funcionarios internos solo por correo. WhatsApp interno requiere marcarlo explícitamente al responder. Los funcionarios se resuelven desde **Configuración → Notificaciones internas → Contratos → Solicitudes de terminación de contrato / Solicitudes de no prórroga de contrato**, usando `internal_admin_notifications`. Cada evento tiene su propia selección de funcionarios activos; cada canal utiliza únicamente contactos con ese dato disponible.

Correo y WhatsApp incluyen acceso al acta mediante la cola compartida `shared-notifications`. No se envían SMS. Al responder se informa cuántos avisos del acta quedaron en cola; esto no confirma entrega. Las fallas al encolar muestran una advertencia. El estado de transporte y sus intentos se consultan en la cola compartida. Las claves de seguimiento distinguen las actas nuevas de las respuestas anteriores del mismo caso.

**Crear caso y enviar segundo mensaje** es independiente. Si se activa, crea retención, encola los correos de creación al responsable, solicitante y funcionarios configurados para `retencion_contrato_ticket`, y WhatsApp al solicitante y consultor asignado cuando tienen celular. **No notificar la respuesta** desactiva únicamente el aviso con acta; para evitar también los avisos comerciales debe desmarcarse la creación de retención.

Verificación: `php tests/contract-request-notifications-check.php` usa tablas temporales y proveedores simulados para comprobar destinatarios, canales, eventos, cola e intentos sin enviar mensajes externos. `node tests/contract-request-ui-check.cjs` verifica ambos formularios, la selección de canales, validaciones, payload y presentación móvil con datos sintéticos.
