# Permisos por cargo

El modal **Permisos por cargo** tiene tres secciones:

- **Pestañas**: una por opción del menú del header (también Ocupaciones, Desocupaciones y Ocupaciones vs Desocupaciones por separado), en sus mismos grupos (Inicio, Gestión de casos, Actividades administrativas, Actividades de cartera, Actividades contractuales, Notificaciones y Métricas). La lista está en `dashboardPermissionTabs()` y los grupos en `renderDashboardPermissionsModal()`. Las claves internas `calendario_actividades` (panel Inicio), `actividades_contractuales` y `metricas` ya no se marcan: se activan si alguna de sus opciones lo está (`DERIVED_DASHBOARD_TABS`). Los permisos guardados con el formato anterior se traducen a lo que el cargo veía (`expandLegacyDashboardTabs()`) hasta que se guarden de nuevo, cuando se registra `dashboard_tab_permissions_known_keys`. Desde entonces cada opción es independiente: por ejemplo, **Casos abiertos** ya no habilita Inicio ni las solicitudes contractuales, y **Cotizaciones** ya no añade **Órdenes** si se desmarca.
- **Acciones**: el catálogo está en `dashboardActionPermissionCatalog()`. Cada acción se valida en el servidor con `canUseDashboardAction()` o `requireDashboardAction()`. `tests/dashboard-action-permissions-check.php` falla si el código exige una acción que no está en el catálogo.

- **Alertas**: los popups que ve cada cargo al entrar a Inicio. El catálogo está en `dashboardAlertCatalog()` y lo guardado en `dashboard_alert_permissions` (cargo → alertas); **Vencimientos administrativos** conserva `admin_due_popup_cargo_ids`. Las alertas nuevas nacen apagadas.

## Alertas nuevas

1. Agrega la alerta a `dashboardAlertCatalog()`.
2. Devuelve sus grupos en `ajax_handler_dashboard_alerts`: `key`, `label`, `hint`, `icon`, `count` y `rows` (`date`, `title`, `meta`, `badge`, `button`, `action`). Valida también el permiso de ver esa información.
3. Si la fila abre algo nuevo, agrega su `action.type` en `dashboardAlertActionHtml` (`admin-dashboard-runtime.js`).

Para que la alerta tenga su propio popup, el grupo lleva `popup` con el título del tema; los grupos con el mismo `popup` van juntos. `popup_note` reemplaza el subtítulo y `show_all` muestra todas las filas en lugar de las primeras 18.

Vencimientos sale primero y después cada tema, uno al cerrar el otro, porque Swal muestra un popup a la vez. Si el funcionario va a atender algo desde un popup, los siguientes esperan a que vuelva a Inicio. Salen al entrar a Inicio, en cada clic en Inicio y cada hora en cualquier pestaña, sin interrumpir a quien está escribiendo o tiene un popup abierto. Cerrarlos pide confirmación.

## Contratos por desocupar

El popup **Contratos por desocupar** (alerta `contratos_por_desocupar`) lista los casos de **Recibo de inmuebles** cuya `fecha_terminacion_contrato` cae en el mes en curso (`ContractReceiptCases`), separados en **Por recibir** (caso abierto) y **Ya cerrados**. La casilla del cargo basta para verlo, porque cartera lo necesita sin acceso a contratos ni métricas.

**Métricas › Contratos por terminar** (`metricas_por_terminar`) usa los mismos casos y abre en el mes en curso.

**Métricas › Aumentos** (`metricas_aumentos`) lista las cartas de aumento por su fecha (`ContractIncreases`), con el valor anterior y el nuevo, y abre en el mes en curso. `valor_canon` del contrato no cambia con la carta, por eso los valores salen de ella. Las cartas antiguas no guardan el valor anterior: se toma el de la carta previa o se calcula con el porcentaje, marcado como *calculado*.

Hasta que se guarden los permisos, estas dos vistas las ve quien ve Desocupaciones.

## Recordatorio diario

En **Alertas › Recordatorio diario** se activa por cargo el correo y el WhatsApp. Sale de lunes a sábado, sin festivos, desde las 9:00 a. m. de Colombia, con las tareas del calendario de vencimientos de ese cargo que ya vencieron o vencen hoy. Quien no tiene tareas no recibe nada.

## Vencimientos por cargo

En **Alertas** cada cargo tiene dos grupos con los 13 tipos de vencimiento (`adminDueTypeLabels()`):

- **Vencimientos que atiende** (`dashboard_due_type_permissions`): decide el calendario, sus filtros, el contador y el popup. El tipo también exige la pestaña en `ADMIN_DUE_TYPES_BY_TAB`. Los resúmenes del popup siguen a sus tipos: *Preventivas pendientes* a los de preventivas y *Servicios públicos pendientes* a *Servicios críticos*.
- **Tipos que entran en el recordatorio diario** (`dashboard_due_reminder_types`): recorta lo anterior solo para el correo y el WhatsApp, que siguen siendo un único mensaje al día por funcionario.

Un cargo que nunca se ha guardado conserva todos los tipos de sus pestañas. Al guardar, lo desmarcado queda desmarcado, y un tipo nuevo nace apagado para los cargos ya guardados.

Producción corre el worker global `shared-notifications/worker-hostinger.php`, que solo entrega la cola: hace falta un cron propio cada 15 minutos con `bin/send-daily-task-reminder.php` (`bin/queue-worker.php` también lo ejecuta, si se llega a usar). Solo envía una vez al día y después de las 12:00 m. ya no sale. La plantilla de WhatsApp es `scm_recordatorio_tareas_v1` (`docs/whatsapp-recordatorio-tareas-template.json`).

## Acciones nuevas

Cuando se agrega una acción al catálogo, los cargos conservan lo que podían hacer antes hasta que alguien guarde los permisos:

- Si la acción se separó de otra (por ejemplo, **Cambiar financiamiento** de **Editar cotización**), la heredan los cargos que tenían la acción original. Ese mapa está en `applyNewDashboardActionDefaults()`.
- Si antes solo se exigía la pestaña, queda permitida.
- Las acciones sensibles (`SENSITIVE_DEFAULT_ACTIONS`) solo las conservan los cargos administradores.

Al guardar permisos se registra `dashboard_action_permissions_known_keys` y desde ahí lo guardado es la configuración real. Si agregas una acción, añádela al catálogo; `LEGACY_DASHBOARD_ACTION_KEYS` no se modifica.

## Botones en el panel

- El runtime del panel no incluye las acciones AJAX que el cargo no tiene (`filterRuntimeActionsByPermission()`).
- `admin-dashboard-runtime.js` oculta con una regla CSS los botones de esas acciones, también los que se dibujan después.
- El servidor rechaza la petición aunque el botón aparezca.
