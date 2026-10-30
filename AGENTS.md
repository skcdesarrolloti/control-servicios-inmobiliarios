# Reglas operativas del proyecto SuCasa

- Después de cada cambio solicitado y verificado, hacer commit y push.
- No cambiar `estado_administrativo` de tickets salvo que el usuario lo pida explícitamente o que una regla existente del flujo lo requiera.
- Las notificaciones nuevas deben ser configurables cuando aplique y deben encolarse mediante la integración compartida de `shared-notifications`; evitar envíos directos o destinatarios quemados en código.
- Cuando una acción del panel requiera avisar a funcionarios internos, crear o reutilizar un evento en **Notificaciones internas** y resolver destinatarios desde `internal_admin_notifications`; no agregar correos administrativos fijos en módulos como Guardian, tickets administrativos, mantenimiento o servicios inmobiliarios.
- La configuración de Guardian debe listar funcionarios activos (`activo = Si`) sin limitarse por los cargos configurados para el panel; los temas pueden requerir cargos comerciales u otros responsables descargados desde Guardian.
- En los modales del panel, preferir popups internos y conservar el contexto del caso; evitar abrir pestañas nuevas si la acción puede resolverse dentro de la app.
- Toda configuración de un módulo se abre desde el menú **Configuración** del encabezado (`#global-config-dropdown-menu` en `includes/header.php`), no con botones dentro de los paneles; si requiere permiso, ocultar la opción con el mapa de permisos por acción del runtime.
- Las imágenes cargadas desde formularios del panel deben comprimirse o validarse antes de guardarse para controlar peso y tamaño.
- Las acciones destructivas como eliminar/archivar/anular deben pedir confirmación clara y dejar trazabilidad cuando el módulo ya maneja historial.
- Cuando se replique dentro del panel una funcionalidad que viene de JetForm/JetEngine, revisar el JSON del formulario y, si usa `glossary_id`, consultar ese glosario en la base de datos para conservar campos dinámicos y opciones reales.
- En registros CCT creados o modificados por funcionarios, `cct_author_id` debe guardar el `id_empleado` real de `wp_jet_cct_funcionarios`, no el `_ID` interno de la sesión; alinear también `id_empleado` e historiales cuando representen al funcionario que ejecuta la acción.
- El nombre corporativo visible en vistas, PDFs, correos e informes debe escribirse como `SKC SuCasa Inmobiliaria`.
- Las vistas públicas nativas no deben ser enumerables solo cambiando `?numero=`; si no hay sesión activa del panel, exigir enlace firmado con vencimiento (`expires` + `sig` HMAC) o token equivalente.
- En vistas públicas/informes para destinatarios, no mostrar `sucursal` salvo que el usuario lo pida explícitamente; cuando se muestre el funcionario, ubicarlo como sello o cierre tipo “Realizado por” al final, no como dato suelto en la grilla principal.
- Las evidencias/imágenes en vistas públicas deben poder previsualizarse dentro de la misma página o modal, evitando mandar al destinatario a pestañas nuevas salvo que el usuario lo pida.
- Toda vista pública que tenga botón de imprimir debe incluir `@media print` compacto y probado: márgenes de página, grillas reducidas, evidencias pequeñas, acciones ocultas y `break-inside/page-break-inside` para no cortar tarjetas o firmas.
- En plantillas de WhatsApp (y sus mensajes de texto equivalentes) que cierren con “Atentamente”, la firma va siempre como `Nombre - Cargo - Cel. 3015232054` (ej. `Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054`): guiones como separador, celular nacional sin indicativo y sin correo; omitir solo las partes que no existan.
- Las columnas nuevas en tablas `wp_jet_cct_*` se agregan con `bin/cct-add-fields.php` (o `SCM\Support\CctFieldInstaller`), que crea el campo en la definición del CCT y su columna a la vez, dentro de la pestaña que corresponde; nunca `ALTER TABLE` sueltos ni columnas sin campo en JetEngine. Ver `docs/cct-agregar-columnas.md`.
