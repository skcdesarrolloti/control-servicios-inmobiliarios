# Calendario: API propia del panel

El calendario del panel (Mi calendario, Calendario equipo, vencimientos, cita desde el caso, informes) ya no usa la app
externa `calendario-actividades` ni su frontend en Netlify. Todo pasa por esta app, igual que en el panel comercial.

## Piezas

- `public/calendar-api.php`: entrada HTTP. Exige sesión del panel; los `POST` exigen el nonce del panel en la cabecera
  `X-SCM-Nonce`. Solo `listar_categorias` y `listar_funcionarios` aceptan `GET`.
- `src/Modules/Calendar/CalendarApi.php`: acciones con la misma respuesta `{success, message, data}` que usaba el panel.
- `src/Modules/Calendar/CalendarStore.php`: consultas sobre las mismas tablas (`calendario_actividades`,
  `calendario_recordatorios`, `categorias_calendario`, `registro_bloqueos`, casos e historial). Las comparte el panel comercial.
- `src/Modules/Calendar/CalendarMailer.php`: correos y avisos de recordatorio, siempre encolados en `shared-notifications`.

El JS (`admin-dashboard-runtime.js` y `scm-admin.js`) toma la URL de `config.calendar_api_url`, que el servidor fija en
`SuCasaControlServiciosInmobiliarios::calendarApiUrl()`.

## Acciones

`listar_categorias`, `listar_funcionarios`, `listar_items_calendario`, `filtrar_eventos_admin`, `listar_pendientes_vencidos`,
`obtener_tickets_funcionario`, `crear_evento`, `crear_eventos`, `crear_item_calendario`, `crear_recordatorio`,
`crear_recordatorios` (varios en una transacción),
`actualizar_evento`, `trasladar_evento`, `cambiar_estado`, `actualizar_recordatorio`, `actualizar_recordatorio_estado`.

## Permisos

- Con la opción **Calendario equipo** o un cargo administrador (11–14): leer y modificar todas las agendas.
- Sin ella: lo propio (asignado a la persona o creado por ella). La agenda de otro funcionario llega como **Ocupado**
  (solo fechas, `privado: true`) y solo se agenda a otro funcionario desde su caso abierto (cita del caso).
- Los procesos del servidor llaman a `CalendarApi` sin sesión y tienen acceso completo.

## Reglas de horario (solo con sesión)

- Nada en el pasado (margen de 10 minutos) y los eventos van el mismo día entre 8:00 a. m. y 9:00 p. m.
- Un evento realizado no se traslada; un caso cerrado no recibe citas.

## Reglas conservadas

- Bloqueo por eventos vencidos (más de 2 días sin cerrar) con registro en `registro_bloqueos`; `permitir_vencidos` lo omite.
- Cita de un caso (`es_cita = si`): el caso queda "En proceso", el estado administrativo solo cambia si el formulario lo
  envía (la cita lo pasa a "Por inspeccionar") y nunca se vacía el estado comercial. Se deja historial del caso con
  `cct_author_id` del funcionario de la sesión y se avisa al solicitante con el enlace firmado del caso.
- `creado_por` es el `id_empleado` real de la sesión. Los procesos del servidor (Revisión crítica) llaman a
  `CalendarApi` directamente y envían su propio `creado_por`.
- Correos al responsable, a quien creó, a los "roles a notificar" de la categoría y a los funcionarios elegidos.

## Categorías (Configuración › Categorías del calendario)

- Se abre desde el menú **Configuración** del encabezado y se oculta sin la acción `calendar_categories_configure`
  (grupo Calendario; por defecto solo cargos administradores).
- `src/Modules/Calendar/CalendarCategorySettings.php` edita `categorias_calendario`: nombre, color, descripción y
  **Notificar a** (`roles_notificar`, IDs de funcionarios separados por coma; solo se guardan IDs que existen). La tabla
  es compartida con el panel comercial, que aplica las mismas reglas en `CommercialSettingsService::saveCategories`.
- **Visible en el panel** se guarda en `panel_calendar_categories` (vacío = todas) y solo afecta a este panel.
- Una categoría con eventos no se elimina (la app anterior borraba también sus eventos).
- Un evento de cargo con reporte comercial obligatorio no se cierra aquí: se cierra desde el panel comercial.
- No hay sincronización con Google Calendar.

## Pruebas

`php tests/calendar-api-check.php` ejecuta la API sobre SQLite en memoria, sin base real ni notificaciones.
