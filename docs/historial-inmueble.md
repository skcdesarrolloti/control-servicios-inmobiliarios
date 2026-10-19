# Historial del inmueble

Un solo componente genera el informe en todos los canales:

| Pieza | Panel (este repo) | Bot de WhatsApp | Portal del propietario |
|---|---|---|---|
| HTML | `src/Support/PropertyHistoryReport.php` | `app/PropertyHistoryReport.php` (sin namespace) | `app/Support/PropertyHistoryReport.php` (`App\Support`) |
| Estilos | `public/assets/css/property-history-report.css` | `public/assets/property-history-report.css` | `resources/css/property-history-report.css` |
| Filtros y búsqueda | `public/assets/js/property-history-report.js` | `public/assets/property-history-report.js` | `resources/js/property-history-report.js` |
| Datos | `HandlesPropertyHistoryActions::property_history_payload()` | `public/reporte-inmueble.php` (`guardian_report_payload`) | `app/Services/PropertyHistoryService.php` |

Las copias de los otros proyectos no tienen namespace propio (bot) o usan `App\Support` (portal); el resto debe mantenerse igual. Si cambias el componente aquí, cópialo también allá.

- **Panel**: *Inicio › Historial inmueble* y el botón *Historial inmueble* de Contratos de arrendamiento. "Descargar PDF" imprime el mismo HTML con Chromium (`HtmlPdfRenderer`); si Chromium no está disponible se usa el PDF básico anterior.
- **Bot**: enlace firmado `reporte-inmueble.php` que recibe el propietario; botón "Imprimir / guardar PDF" del navegador.
- **Portal del propietario**: *Inmuebles › Ver historial* (`/inmuebles/historial?id=`), solo para inmuebles del propietario autenticado.

El informe incluye identificación del inmueble, indicadores, contratos con estado y canon, una línea de tiempo agrupada por mes con filtros por fuente y búsqueda, el detalle por fuente y el sello "Realizado por". La impresión es compacta y no corta tarjetas ni eventos.
