# Informe de refactorización

## Problemas encontrados

La versión recibida mezclaba archivos públicos, configuración, datos variables, adjuntos y código fuente en la raíz. También contenía configuración sensible, endpoints extensos, carga de archivos directamente accesible y varios archivos PHP/JS/CSS de miles de líneas.

Los principales riesgos eran:

- secretos y credenciales almacenados en archivos del proyecto;
- raíz web con acceso potencial a código, datos y configuración;
- contraseñas antiguas comparadas como texto plano;
- uploads públicos con validación limitada;
- API autenticada implementada como una cadena extensa de condiciones;
- procesamiento de cola accesible como endpoint web;
- lógica, HTML, CSS y JavaScript mezclados en las mismas clases/vistas;
- archivos duplicados, plantillas sin uso y módulos huérfanos.

## Estructura aplicada

- Los únicos entrypoints HTTP viven en `public/`.
- La configuración se obtiene de variables de entorno y `.env` queda ignorado por Git.
- `storage/` contiene datos, logs y adjuntos; solo se versionan archivos `.gitkeep`.
- Las plantillas activas se movieron a `resources/emails/`.
- El worker pasó a `bin/` y solo se ejecuta desde CLI.
- Las APIs públicas y autenticadas se distribuyeron en router, controladores y respuestas JSON.
- El acceso público firmado y el almacenamiento de archivos tienen servicios dedicados.
- Los dominios principales se dividieron en fachadas pequeñas y conjuntos de responsabilidades dentro de `Concerns/`.
- El CSS administrativo se dividió por contexto conservando el orden de cascada.
- El JavaScript administrativo se dividió en núcleo de casos, runtime del panel y guía.
- El CSS/JS embebido en vistas se extrajo a assets versionables.

## Archivos retirados

Se eliminaron los siguientes elementos por ser duplicados, huérfanos, inseguros o reemplazados:

- `config.php` y `.vscode/mcp.json`: contenían configuración local/sensible y no se importaron al nuevo repositorio.
- `src/.htaccess`: innecesario porque `src/` ya no está bajo la raíz pública.
- `src/DamageMagnitudeService.php`: implementación duplicada.
- `src/Support/MaintenanceTimelineMap.php`: mapa reemplazado por las implementaciones activas de `TimelineMaps/`.
- `src/Modules/Pending/PendingRevisionsModule.php`: módulo sin referencias.
- `src/Views/tab_magnitud_danos*.html`: fragmentos duplicados/sin uso.
- plantillas de ejemplo, base y creación de eventos que no tenían consumidores.
- entrypoints originales de la raíz: fueron reemplazados por equivalentes en `public/`.
- `queue-worker.php` web: reemplazado por `bin/queue-worker.php` exclusivo de CLI.

## Compatibilidad

La estructura de tablas y las reglas de negocio existentes no se reescribieron. Las fachadas conservan los nombres públicos usados por controladores y vistas. Para los adjuntos históricos se mantiene una ruta de solo lectura `/uploads/*`; los archivos deben copiarse a `storage/uploads` con el comando de migración.

Antes de cambiar el despliegue se debe probar con una copia de la base de datos real. La revisión automatizada no puede confirmar nombres de columnas, permisos SQL, disponibilidad del proyecto externo `shared-notifications` ni entrega real de correo/WhatsApp.

## Deuda técnica restante

- `admin-dashboard-runtime.js` y `scm-admin.js` siguen siendo módulos grandes. Ya están separados en dos ámbitos estables, pero conviene extraer después ubicación, edición de casos, formularios y filtros como componentes probados.
- Algunos `Concerns` superan 500 líneas. El siguiente paso seguro es convertir cada uno en servicios inyectables una vez existan pruebas de integración contra una base de datos de staging.
- Las vistas siguen renderizando HTML desde PHP. Una migración de frontend debe tratarse como proyecto independiente, no mezclarse con el cambio de seguridad/estructura.
- PHPStan cubre el núcleo tipado nuevo. El código heredado dinámico todavía necesita anotaciones y DTOs antes de elevar todo `src/` al mismo nivel.
- Las dependencias CDN deberían fijarse y alojarse localmente si la política operativa exige despliegues sin red externa.

Estas tareas no bloquean la nueva estructura, pero sí deben entrar en el backlog antes de agregar módulos grandes nuevos.
