# ¿Quién ejecuta? (`ejecutado`)

Campo que indica quién ejecuta y paga la reparación de un caso de mantenimiento. Valores del glosario JetEngine **853**: Propietario, Arrendatario, Copropiedad, Inmobiliaria y Externo.

## Dónde vive

| Tabla | Columna | Rol |
| --- | --- | --- |
| `wp_jet_cct_cotizacion_mantenimiento` | `ejecutado` | Se elige en la cotización ("¿Quién ejecutará y aprobará?") y define el destinatario. |
| `wp_jet_cct_tickets` | `ejecutado` | Valor del caso: lo usan los KPI y el filtro del panel. |

Las revisiones (correctiva y preventiva) no guardan este campo. El acta guarda su propio "¿Quién realizó la solución?" y lo copia al caso (ver abajo).

## Cómo se llena el caso

1. **Crear o editar una cotización** desde el panel copia `ejecutado` al caso (`maintenance_quote_update_ticket`).
2. **Aprobar una cotización** (botón del panel, respuesta pública o respuesta registrada) copia al caso el `ejecutado` de la cotización aprobada.
3. **Acta de solución y firma**: al generarla o editarla, "¿Quién realizó la solución?" se copia al caso; al anularla, archivarla o eliminarla, el caso recupera el valor que tenía antes del acta. El formulario del acta propone el valor del caso cuando no hay estado "En ejecucion por …".
4. **Editar datos del caso** (detalle del caso) permite cambiarlo o dejarlo en "Sin definir". El cambio queda como nota interna. Si el caso está vacío, el formulario propone el valor de la última cotización.

Al crear una cotización nueva, el formulario parte del valor del caso.

## KPI y filtro (Mantenimiento, Preventiva, Entrega y Recibo)

La fila **¿Quién ejecuta?** cuenta los casos del universo filtrado según:

- `tickets.ejecutado`, si tiene valor;
- si no, el `ejecutado` de la cotización más reciente del caso. Esto cubre casos antiguos de JetForm que no lo copiaban al caso;
- si tampoco hay, el caso cuenta como **Sin definir**.

Cada tarjeta filtra el listado (`<prefijo>ejecutado`). El filtro **Ejecutado por** usa la misma regla y también está en Postergados/Cerrados de mantenimiento. La lógica SQL compartida está en `src/Support/CaseExecutorSql.php`.

## Casos antiguos

`php bin/sync-case-executor-from-quotes.php` (simulación) y `--apply` copian el ejecutor de la cotización aprobada más reciente (o, si no hay, de la última cotización con ejecutor) a los casos vacíos. No sobrescriben valores existentes y reportan los casos cuyo valor difiere de su cotización.
