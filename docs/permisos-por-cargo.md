# Permisos por cargo

El modal **Permisos por cargo** tiene dos secciones:

- **Pestañas**: una por opción del menú del header (también Ocupaciones, Desocupaciones y Ocupaciones vs Desocupaciones por separado), en sus mismos grupos (Inicio, Gestión de casos, Actividades administrativas, Actividades de cartera, Actividades contractuales, Notificaciones y Métricas). La lista está en `dashboardPermissionTabs()` y los grupos en `renderDashboardPermissionsModal()`. Las claves internas `calendario_actividades` (panel Inicio), `actividades_contractuales` y `metricas` ya no se marcan: se activan si alguna de sus opciones lo está (`DERIVED_DASHBOARD_TABS`). Los permisos guardados con el formato anterior se traducen a lo que el cargo veía (`expandLegacyDashboardTabs()`) hasta que se guarden de nuevo, cuando se registra `dashboard_tab_permissions_known_keys`. Desde entonces cada opción es independiente: por ejemplo, **Casos abiertos** ya no habilita Inicio ni las solicitudes contractuales, y **Cotizaciones** ya no añade **Órdenes** si se desmarca.
- **Acciones**: el catálogo está en `dashboardActionPermissionCatalog()`. Cada acción se valida en el servidor con `canUseDashboardAction()` o `requireDashboardAction()`. `tests/dashboard-action-permissions-check.php` falla si el código exige una acción que no está en el catálogo.

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
