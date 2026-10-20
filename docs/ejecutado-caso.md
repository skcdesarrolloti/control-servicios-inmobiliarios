# ¿Quién ejecuta? (`ejecutado`)

Campo que indica quién ejecuta y paga la reparación de un caso. Valores del glosario JetEngine **853**: Propietario, Arrendatario, Copropiedad, Inmobiliaria y Externo.

Desde la versión 3.3.434, el estado administrativo **ya no dice quién ejecuta**. Los estados "En ejecucion por inmobiliaria/propietario/arrendatario/copropiedad" se retiraron y se reemplazaron por un único **"En ejecucion"**. Quién ejecuta se registra solo en `ejecutado`. El estado anterior no dejaba trazabilidad: la orden de mantenimiento ponía "por inmobiliaria" aunque ejecutara el propietario.

## Dónde vive

| Tabla | Columna | Rol |
| --- | --- | --- |
| `wp_jet_cct_cotizacion_mantenimiento` | `ejecutado` | Se elige en la cotización ("¿Quién ejecutará y aprobará?") y define el destinatario. |
| `wp_jet_cct_tickets` | `ejecutado` | Valor del caso: lo usan los KPI y el filtro del panel. |

Las revisiones (correctiva y preventiva) no guardan este campo. El acta guarda su propio "¿Quién realizó la solución?" y lo copia al caso (ver abajo).

## Cómo se llena el caso

1. **Crear o editar una cotización** desde el panel copia `ejecutado` al caso (`maintenance_quote_update_ticket`).
2. **Aprobar una cotización** (botón del panel, respuesta pública o respuesta registrada) copia al caso el `ejecutado` de la cotización aprobada.
3. **Acta de solución y firma**: al generarla o editarla, deja el caso en "En ejecucion" y copia "¿Quién realizó la solución?" a `ejecutado`. Al anularla, archivarla o eliminarla, el caso recupera el valor que tenía antes del acta. El formulario propone el valor del caso.
4. **Orden de mantenimiento**: deja el caso en "En ejecucion" y no cambia `ejecutado`.
5. **Botón "Editar ejecutado"** del detalle del caso (permiso `case_edit_executor`): cambia el valor o lo deja en "Sin definir". Si el caso está vacío, propone el de la última cotización.
6. **Responder caso** y **Seguimiento**: el selector "Ejecutado por" (por defecto "Sin cambio") permite cambiarlo al publicar. En una respuesta que además aprueba una cotización, prevalece lo elegido en el selector.

En los puntos 5 y 6 cada cambio queda como **nota interna** del caso: indica quién lo hizo, el valor anterior y el nuevo, y si vino de una respuesta o de un seguimiento. El texto que recibe el cliente no lo incluye. Al crear una cotización nueva, el formulario parte del valor del caso.

### Permiso

`case_edit_executor` ("Editar quién ejecuta el caso") controla el botón y el selector del compositor. Mientras no se guarden los permisos con esta opción, lo tienen los cargos con "Editar datos del caso".

## KPI y filtro (solo Mantenimiento y Preventiva)

La fila **¿Quién ejecuta?** cuenta los casos del universo filtrado según:

- `tickets.ejecutado`, si tiene valor;
- si no, el `ejecutado` de la cotización más reciente del caso;
- si tampoco hay, el caso cuenta como **Sin definir**.

Cada tarjeta filtra el listado (`<prefijo>ejecutado`). El filtro **Ejecutado por** usa la misma regla y también está en Postergados y Cerrados de mantenimiento. La lógica SQL compartida está en `src/Support/CaseExecutorSql.php`.

## Scripts de datos

- `php bin/sync-case-executor-from-quotes.php [--apply]`: llena los casos vacíos con el ejecutor de la cotización aprobada más reciente o, si no hay, de la última con ejecutor. No sobrescribe valores existentes.
- `php bin/migrate-execution-states.php [--apply] [--prefer-state]`: pasa los casos con "En ejecucion por …" a "En ejecucion". Llena `ejecutado` con lo que decía el estado cuando está vacío. Si `ejecutado` tiene otro valor, lo conserva y lo reporta; con `--prefer-state`, lo reemplaza. Deja una nota interna por caso.

Las actas pendientes creadas con el estado anterior se siguen pudiendo firmar después de la migración.
