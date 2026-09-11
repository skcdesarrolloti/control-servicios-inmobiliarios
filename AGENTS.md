# Reglas operativas del proyecto SuCasa

- Después de cada cambio solicitado y verificado, hacer commit y push.
- No cambiar `estado_administrativo` de tickets salvo que el usuario lo pida explícitamente o que una regla existente del flujo lo requiera.
- Las notificaciones nuevas deben ser configurables cuando aplique y deben encolarse mediante la integración compartida de `shared-notifications`; evitar envíos directos o destinatarios quemados en código.
- En los modales del panel, preferir popups internos y conservar el contexto del caso; evitar abrir pestañas nuevas si la acción puede resolverse dentro de la app.
- Las imágenes cargadas desde formularios del panel deben comprimirse o validarse antes de guardarse para controlar peso y tamaño.
- Las acciones destructivas como eliminar/archivar/anular deben pedir confirmación clara y dejar trazabilidad cuando el módulo ya maneja historial.
- Cuando se replique dentro del panel una funcionalidad que viene de JetForm/JetEngine, revisar el JSON del formulario y, si usa `glossary_id`, consultar ese glosario en la base de datos para conservar campos dinámicos y opciones reales.
- En registros CCT creados o modificados por funcionarios, `cct_author_id` debe guardar el `id_empleado` real de `wp_jet_cct_funcionarios`, no el `_ID` interno de la sesión; alinear también `id_empleado` e historiales cuando representen al funcionario que ejecuta la acción.
- El nombre corporativo visible en vistas, PDFs, correos e informes debe escribirse como `SKC SuCasa Inmobiliaria`.
- Las vistas públicas nativas no deben ser enumerables solo cambiando `?numero=`; si no hay sesión activa del panel, exigir enlace firmado con vencimiento (`expires` + `sig` HMAC) o token equivalente.
- En vistas públicas/informes para destinatarios, no mostrar `sucursal` salvo que el usuario lo pida explícitamente; cuando se muestre el funcionario, ubicarlo como sello o cierre tipo “Realizado por” al final, no como dato suelto en la grilla principal.
