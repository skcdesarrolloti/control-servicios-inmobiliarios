# Copias internas por acción

Los correos nuevos usan exclusivamente los funcionarios seleccionados para
su evento en **Notificaciones internas → Acciones y destinatarios internos**.
Esos funcionarios reciben CC del correo principal; no se agregan como correos
independientes ni se incorpora automáticamente a quien realiza la acción.

Cuando el aviso es solo interno, uno de los seleccionados queda en `Para` y
los demás en CC. Una acción vacía no genera copias internas. WhatsApp y
calendario mantienen sus entregas individuales. Los códigos de firma siguen
siendo exclusivos del firmante.

`InternalEmailCopies` prepara los destinatarios y conserva el documento de
cada actor. `EmailQueue` acepta `internal_action` para resolver la configuración
del evento o `internal_cc` cuando el flujo ya la resolvió o requiere respetar
una selección de canales. La lista explícita tiene prioridad, incluso vacía.
Se deduplican Para/CC/CCO y se conservan adjuntos, Reply-To y deduplicación.

Los módulos de casos, contratos, retención, revisiones, cartas de aumento,
cobranza, órdenes y liquidación usan esa ruta. Los avisos diferidos de servicios
críticos y no salida conservan las CC en el trabajo programado. Los avisos
administrativos que solo tienen funcionarios configurados se agrupan mediante
`enqueueConfigured`; las notificaciones manuales a funcionarios conservan su
personalización individual. Las copias de actas firmadas dejan trazabilidad
`via: cc` sobre el mismo envío principal.

El ajuste anterior `email_actor_cc_enabled` ya no determina destinatarios.
Los registros que estaban encolados antes de este cambio no se reescriben.

El popup tiene encabezado y pie fijos, y un único cuerpo desplazable. Las
secciones no tienen desplazamiento independiente. Los controles nativos de
selección múltiple conservan su navegación propia.

Validación: `tests/internal-email-copies-check.php`,
`tests/internal-email-copies-integration-check.php`, pruebas de contratos,
cotizaciones, actas y `tests/panel-case-settings-check.cjs`. Las pruebas usan
tablas temporales o dobles de proveedores; no envían mensajes reales.
