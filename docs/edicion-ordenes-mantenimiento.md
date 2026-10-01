# Edición de órdenes de mantenimiento

En la cotización, abre **Órdenes → Editar orden**. Utiliza el permiso existente para crear órdenes. La cotización debe estar aprobada; permite editar órdenes pendientes, aprobadas o desaprobadas. Las anuladas o eliminadas no permiten edición.

Al guardar:

- Conserva el número de orden, creador y fecha de creación.
- Devuelve el valor anterior al saldo de su categoría y descuenta el nuevo valor. Si cambia la categoría, devuelve el valor en la anterior y reserva el nuevo valor en la seleccionada.
- Valida disponibilidad y bloquea operaciones simultáneas sobre la misma cotización durante el guardado.
- Limpia el autorizador y devuelve la orden a **Esperando respuesta**.
- Registra cambios de valor, categoría, concepto y proveedor, estado anterior y funcionario editor en el historial del caso y del inmueble.
- Encola correo y WhatsApp a los destinatarios configurados en **Notificaciones internas → Orden de mantenimiento creada**, reutilizando la plantilla `scm_orden_mantenimiento_funcionario_v1`.
- Cada edición usa una nueva clave de notificación para solicitar aprobación otra vez.

Las pantallas de edición y respuesta rechazan versiones anteriores si la orden cambió desde que se abrió el formulario. La respuesta compara el estado y la versión de forma atómica para evitar aprobar datos modificados simultáneamente.

La edición conserva el estado administrativo del caso. La creación mantiene el cambio de estado administrativo que ya tiene el flujo.

## Órdenes después del acta

El acta de satisfacción no bloquea la creación de nuevas órdenes. Una cotización aprobada puede crear órdenes mientras haya saldo en alguna categoría; cada orden debe ser mayor que cero y no superar el saldo de la categoría seleccionada. Si todas están agotadas, se muestra **Sin saldo para nuevas órdenes**.

## Edición de cotización

El aviso existente por guardar/editar la cotización se envía por correo al creador y a los funcionarios del evento **Cotización de mantenimiento guardada**. Ese evento no envía WhatsApp actualmente. Editar una cotización reinicia su envío/respuesta; debe enviarse y aprobarse nuevamente antes de crear o editar órdenes.

La regla existente conserva como comprometidas las órdenes desaprobadas. Esta edición no cambia esa regla ni devuelve automáticamente su saldo por desaprobarlas.

## Verificación

`php tests/maintenance-order-edit-check.php` ejecuta el guardado real con almacenamiento y colas aislados: disminución, aumento, cambio de categoría, creador, nueva aprobación, historial, destinatarios, nuevas claves de aviso, rechazo por exceso, versión anterior, otra cotización, falta de aprobación y creación después del acta.
