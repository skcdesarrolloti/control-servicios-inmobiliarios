# Reglas operativas del proyecto SuCasa

- Después de cada cambio solicitado y verificado, hacer commit y push **a la rama en la que se está trabajando** (ver "Trabajo en ramas"). No hacer merge a `main` sin que el usuario lo pida.

## Trabajo en ramas (varias funcionalidades en paralelo)

El proyecto permite trabajar varias funcionalidades a la vez, cada una en su propia rama y, si hay varias sesiones simultáneas, cada una en su propio **git worktree** (una carpeta por rama) para que no se pisen los archivos.

- Si el usuario pide trabajar en una rama, en un área/tab, o "en paralelo", crear o reutilizar una rama `area/<area>` o `feature/<area>-<descripcion-corta>` partiendo de `main` actualizado (`git fetch origin && git switch -c <rama> origin/main`).
- Si la sesión ya está en una rama distinta de `main`, seguir trabajando ahí; no cambiar de rama con cambios sin commitear.
- Si el usuario no menciona ramas y la sesión está en `main`, se puede seguir trabajando directo en `main` como hasta ahora.
- Push siempre con upstream: `git push -u origin <rama>`.
- Antes de empezar y antes de integrar, traer lo último de `main` (`git fetch origin && git rebase origin/main`, o `merge` si la rama ya es compartida) para reducir conflictos.
- Integrar a `main` solo cuando el usuario lo pida: por PR (`gh pr create --base main`) o merge local + push, según indique.
- Para sesiones paralelas usar worktrees en una carpeta hermana, p. ej. `git worktree add ../csi-<area> -b area/<area> origin/main`. En el worktree nuevo hay que preparar lo que no está versionado: `composer install` (o copiar `vendor/`), y copiar `.env`/`config.php` si existen. Al terminar: `git worktree remove ../csi-<area>`.
- Archivos compartidos con alto riesgo de conflicto (tocarlos lo mínimo y avisar al usuario si un cambio de área los modifica): `src/Views/DashboardView.php`, `src/App/Concerns/RendersDashboard.php`, `src/App/SuCasaControlServiciosInmobiliarios.php`, `src/Core/*`, `public/api.php`, `public/index.php`, `resources/css/*`, `tailwind*.config.js`, `composer.json`/`composer.lock`.

### Áreas sugeridas (rama `area/<area>`) según tabs del panel

| Área (rama) | Tabs del panel (`scm-panel-*`) | Código principal |
|---|---|---|
| `area/tickets` | abiertos, cerrados, postergados, mis-tickets, pqr-publico, entrega, recibo, contractual, contable, certificaciones | `Modules/GenericTickets`, `PublicTickets`, `TicketCompletion`, `Pending`, `Repositories/TicketsRepository.php`, `public/ticket*.php`, `public/caso.php` |
| `area/mantenimiento` | mant, cotizaciones-mantenimiento, preventiva, preventivas-pendientes, actas-satisfaccion | `Modules/CorrectiveReview`, `PreventiveReview`, `Views/QuotePageView.php`, `Views/ReviewPageView.php`, `public/cotizacion*.php`, `public/revision*.php`, `public/crear-acta.php`, `public/orden-publica.php` |
| `area/contratos` | contratos-arrendamiento, movimientos-contratos, funcionario-contratos, actividades-contractuales, cartas-aumento, auditoria-canon-aseguradoras | `Modules/Contracts`, `ContractHistory`, `ContractMovements`, `ContractOwnership`, `RentIncrease`, `CanonInsuranceAudit` |
| `area/servicios-publicos` | liquidador-servicios-publicos, servicios-publicos-pendientes | `Modules/PublicServicesLiquidator`, `public/pago-servicios-publicos.php`, `public/evidencia-pago-servicios.php`, `public/revision-servicios-publicos.php` |
| `area/cobro` | gestiones-cobro | `Modules/CollectionManagement` |
| `area/administrativo` | actividades-administrativas, reportes-administrativos-pendientes, calendario-actividades, admin-notificaciones | `Modules/AdministrativeNotifications`, `ServiciosInmobiliarios`, `Support/InternalAdmin*` |
| `area/metricas` | metricas, metricas-casos, metricas-cotizaciones | `Modules/Metrics`, `src/Metrics` |
| `area/core` | inicio, login, settings, layout general | `src/Core`, `src/Http`, `Views/DashboardView.php`, `public/login.php`, `public/settings.php`, `resources/css` |

Las áreas son una guía: si una funcionalidad cruza áreas, usar una rama `feature/<descripcion>` y avisar qué áreas toca.
- No cambiar `estado_administrativo` de tickets salvo que el usuario lo pida explícitamente o que una regla existente del flujo lo requiera.
- Las notificaciones nuevas deben ser configurables cuando aplique y deben encolarse mediante la integración compartida de `shared-notifications`; evitar envíos directos o destinatarios quemados en código.
- Cuando una acción del panel requiera avisar a funcionarios internos, crear o reutilizar un evento en **Notificaciones internas** y resolver destinatarios desde `internal_admin_notifications`; no agregar correos administrativos fijos en módulos como Guardian, tickets administrativos, mantenimiento o servicios inmobiliarios.
- La configuración de Guardian debe listar funcionarios activos (`activo = Si`) sin limitarse por los cargos configurados para el panel; los temas pueden requerir cargos comerciales u otros responsables descargados desde Guardian.
- En los modales del panel, preferir popups internos y conservar el contexto del caso; evitar abrir pestañas nuevas si la acción puede resolverse dentro de la app.
- Las imágenes cargadas desde formularios del panel deben comprimirse o validarse antes de guardarse para controlar peso y tamaño.
- Las acciones destructivas como eliminar/archivar/anular deben pedir confirmación clara y dejar trazabilidad cuando el módulo ya maneja historial.
- Cuando se replique dentro del panel una funcionalidad que viene de JetForm/JetEngine, revisar el JSON del formulario y, si usa `glossary_id`, consultar ese glosario en la base de datos para conservar campos dinámicos y opciones reales.
- En registros CCT creados o modificados por funcionarios, `cct_author_id` debe guardar el `id_empleado` real de `wp_jet_cct_funcionarios`, no el `_ID` interno de la sesión; alinear también `id_empleado` e historiales cuando representen al funcionario que ejecuta la acción.
- El nombre corporativo visible en vistas, PDFs, correos e informes debe escribirse como `SKC SuCasa Inmobiliaria`.
- Las vistas públicas nativas no deben ser enumerables solo cambiando `?numero=`; si no hay sesión activa del panel, exigir enlace firmado con vencimiento (`expires` + `sig` HMAC) o token equivalente.
- En vistas públicas/informes para destinatarios, no mostrar `sucursal` salvo que el usuario lo pida explícitamente; cuando se muestre el funcionario, ubicarlo como sello o cierre tipo “Realizado por” al final, no como dato suelto en la grilla principal.
- Las evidencias/imágenes en vistas públicas deben poder previsualizarse dentro de la misma página o modal, evitando mandar al destinatario a pestañas nuevas salvo que el usuario lo pida.
- Toda vista pública que tenga botón de imprimir debe incluir `@media print` compacto y probado: márgenes de página, grillas reducidas, evidencias pequeñas, acciones ocultas y `break-inside/page-break-inside` para no cortar tarjetas o firmas.
