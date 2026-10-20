# ¿Quién ejecuta? (`ejecutado`)

Campo que indica quién ejecuta físicamente la reparación de un caso. Valores del glosario JetEngine **853**: Propietario, Arrendatario, Copropiedad, Inmobiliaria y Externo.

Desde la versión 3.3.434, el estado administrativo **ya no dice quién ejecuta**. Los estados "En ejecucion por inmobiliaria/propietario/arrendatario/copropiedad" se retiraron y se reemplazaron por un único **"En ejecucion"**. Quién ejecuta se registra solo en `ejecutado`.

## Quién aprueba ≠ quién ejecuta

La columna `ejecutado` existe en dos tablas con significados distintos:

| Tabla | Columna | Significado |
| --- | --- | --- |
| `wp_jet_cct_cotizacion_mantenimiento` | `ejecutado` | **Quién aprueba la cotización** ("¿Quién aprobará la cotización?"). Define el destinatario del envío. No se copia al caso. |
| `wp_jet_cct_tickets` | `ejecutado` | **Quién ejecuta el caso**. Lo usan los KPI y el filtro del panel. |

El trabajo cotizado siempre lo ejecuta la inmobiliaria (`CaseExecutorSql::QUOTE_EXECUTOR`). Otro ejecutor (propietario, arrendatario, copropiedad, externo) solo llega al caso por el acta de solución o por un cambio manual.

## Cómo se llena el caso

Solo estas acciones cambian `ejecutado` del caso:

1. **Crear o editar una cotización**: deja el caso en **Inmobiliaria** (`maintenance_quote_update_ticket`). El selector de la cotización solo guarda quién aprueba; por defecto propone el valor de la cotización o Propietario.
2. **Acta de solución y firma**: al generarla o editarla, deja el caso en "En ejecucion" y copia "¿Quién realizó la solución?". Si el acta viene de una cotización aprobada (acta de satisfacción del trabajo, flujo `approved_quote`), el ejecutor es siempre **Inmobiliaria** y el formulario solo ofrece esa opción. En el acta de solución del caso (flujo `ticket_solution`) solo se ofrecen Propietario, Arrendatario o Copropiedad: si ejecutó la inmobiliaria, hay cotización. Al anular, archivar o eliminar el acta, el caso recupera el valor que tenía antes.
3. **Botón "Editar ejecutado"** del detalle del caso (permiso `case_edit_executor`): cambia el valor o lo deja en "Sin definir". Si el caso está vacío y tiene cotización, propone Inmobiliaria.
4. **Responder caso** y **Seguimiento**: el selector "Ejecutado por" (por defecto "Sin cambio") permite cambiarlo al publicar.

En los puntos 3 y 4 cada cambio queda como **nota interna** del caso: indica quién lo hizo, el valor anterior y el nuevo. El texto que recibe el cliente no lo incluye.

No cambian `ejecutado`: aprobar una cotización (panel, enlace público o respuesta registrada) ni la orden de mantenimiento (que solo deja el caso "En ejecucion").

### Permiso

`case_edit_executor` ("Editar quién ejecuta el caso") controla el botón y el selector del compositor. Mientras no se guarden los permisos con esta opción, lo tienen los cargos con "Editar datos del caso".

## KPI y filtro (solo Mantenimiento y Preventiva)

La fila **¿Quién ejecuta?** cuenta los casos del universo filtrado según:

- `tickets.ejecutado`, si tiene valor;
- si no y el caso tiene cotización, **Inmobiliaria**;
- si tampoco, **Sin definir**.

Cada tarjeta filtra el listado (`<prefijo>ejecutado`). El filtro **Ejecutado por** usa la misma regla y también está en Postergados y Cerrados de mantenimiento. La lógica SQL compartida está en `src/Support/CaseExecutorSql.php`.

## Scripts de datos

- `php bin/repair-case-executor.php [--apply]`: corrige los casos con cotización que heredaron de la cotización quién aprueba. Gana el evento más reciente: cotización → Inmobiliaria; acta de solución → quien realizó la solución; cambio manual → se conserva. Casos sin cotización no se tocan. Deja una nota interna por caso corregido.
- `php bin/migrate-execution-states.php [--apply] [--prefer-state]`: pasa los casos con "En ejecucion por …" a "En ejecucion". Llena `ejecutado` con lo que decía el estado cuando está vacío. Si `ejecutado` tiene otro valor, lo conserva y lo reporta; con `--prefer-state`, lo reemplaza. Deja una nota interna por caso.

Las actas pendientes creadas con el estado anterior se siguen pudiendo firmar después de la migración.
