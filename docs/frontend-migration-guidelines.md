# Pautas de migracion frontend

Estas pautas aplican mientras sigue la migracion visual del panel. Antes de tocar frontend, consultar esta guia, `AGENTS.md` y la rama `feature/ui-redesign-modernization`, que contiene la frontera actual de la migracion frente a `main`.

## Consulta obligatoria antes de cambiar UI

- Revisar la rama activa con `git status -sb` y comparar lo migrado con `git diff main..feature/ui-redesign-modernization -- public/assets/js/scm-admin.js public/assets/js/admin-dashboard-runtime.js public/assets/css/modern-ui.css src/App/Concerns/RendersDashboard.php`.
- Identificar si el cambio pertenece al contrato PHP/HTML, a la activacion visual de tabs o al loader AJAX. No mover una pieza sin revisar las otras dos.
- Mantener en el DOM los tabs nativos y atributos `data-*` que usa el runtime, aunque el diseno nuevo muestre otra navegacion encima.
- Si se toca JS o CSS servido al navegador, subir `SCM_VERSION` para evitar cache viejo en produccion.

## Contratos que no se deben romper

- `src/App/Concerns/RendersDashboard.php` define ids de paneles, permisos, runtime JSON y `data-scm-loaded`.
- `public/assets/js/scm-admin.js` maneja la experiencia visible: tabs, popups, modal de caso, overlays y acciones de usuario.
- `public/assets/js/admin-dashboard-runtime.js` maneja loaders, filtros, paginacion, refrescos AJAX y acciones de datos.
- Los paneles lazy deben iniciar con `data-scm-loaded="0"` y pasar a `1` solo cuando el fetch correcto actualiza el contenido.
- En tickets abiertos, el panel padre es `#scm-panel-abiertos`; los temas internos usan `.scm-open-topic-panel` y `data-open-topic`.

## Reglas de experiencia

- Usar popups internos y conservar el contexto del caso; no abrir pestanas nuevas si la accion puede resolverse dentro del panel.
- Todo popup debe cerrar con un solo click, Escape si aplica, y devolver el foco a la accion que lo abrio.
- Las imagenes, audios y archivos del caso deben verse dentro de la app cuando sea posible.
- Mantener estados de carga, vacio y error visibles; nunca dejar un placeholder permanente si ya se intento cargar.
- Evitar saltos de layout: grids, cards, toolbars y botones deben tener dimensiones estables en desktop y movil.
- No cambiar `estado_administrativo` salvo solicitud explicita del usuario o regla existente del flujo.

## Checklist rapido

- `node --check` para los JS modificados.
- `php -l` para PHP modificado.
- `git diff --check`.
- Probar navegacion: Inicio, Tickets abiertos, Mantenimiento, otros temas, Mis tickets, Postergados y Cerrados.
- En Mantenimiento, confirmar que el placeholder dispara AJAX y las cards reemplazan el contenido inicial.
