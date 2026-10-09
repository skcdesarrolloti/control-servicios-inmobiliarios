# Plantilla WhatsApp y proceso de servicios públicos críticos

Actualizado para la versión **3.3.372**. El flujo utiliza un caso con tema **Servicio publico critico**, con plazo de atención de 72 horas. Reemplaza las dos plantillas anteriores de requerimiento y reporte de pago.

## Crear la plantilla

En **WhatsApp Manager → Plantillas de mensajes → Crear plantilla**, dentro de la cuenta vinculada al proyecto:

| Campo | Valor |
| --- | --- |
| Nombre sugerido | `scm_servicios_revision_critica` |
| Categoría propuesta | Utilidad / Utility, sujeta a revisión de Meta |
| Idioma | Español; guardar después en el panel el código exacto aprobado, por ejemplo `es` |
| Encabezado | **Documento**, con PDF de acta de ejemplo y datos ficticios |
| Variables del cuerpo | Cinco variables numéricas, en el orden siguiente |
| Botón | **Visitar sitio web**, URL dinámica |
| Texto del botón | **Ver revisión** |

El acta PDF se adjunta al encabezado en cada envío. Ya no se utiliza una plantilla para comprobantes de pago.

### Texto para copiar

```text
Hola {{1}}.

SKC SuCasa Inmobiliaria registró una revisión de servicios públicos con resultado crítico para el contrato #{{2}}.

Detalles de la revisión y del caso: {{3}}

Se creó un caso con el tema Servicio publico critico para gestionar esta situación. Su plazo de atención es de 72 horas desde el registro de la revisión. Vence: {{4}}.

Revisión realizada por: {{5}}.

Adjuntamos el acta. Consulta los servicios revisados y las actas con el botón Ver revisión.
```

### Variables y ejemplos

| Variable | Contenido | Ejemplo ficticio |
| --- | --- | --- |
| `{{1}}` | Destinatario | Juan Pérez |
| `{{2}}` | Contrato, sin `#` | 2000 |
| `{{3}}` | Número de caso, revisión y servicios críticos | Caso #456. Revisión #123. Energía eléctrica, referencia 123456, deuda reportada $350.000 COP. |
| `{{4}}` | Vencimiento del caso, fecha y hora de Colombia | 12/10/2026 10:00 Colombia |
| `{{5}}` | Funcionario creador | Carlos Ramírez |

El nombre del destinatario cambia para cada funcionario o arrendatario; el caso y los detalles corresponden a la misma revisión.

### Enlace del botón

Configurar exactamente esta **URL dinámica**:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/{{1}}
```

El `{{1}}` del botón es independiente del `{{1}}` del cuerpo. El servidor entrega un sufijo firmado con esta estructura:

```text
revision-servicios-publicos.php?numero=123&expires=1791849600&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

Ejemplo de URL completa:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/revision-servicios-publicos.php?numero=123&expires=1791849600&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

La firma del ejemplo es ficticia y no permite abrir una revisión. Para la muestra de Meta, usar el sufijo o la URL completa según el campo solicitado. Si requiere una página accesible, usar un enlace firmado de una revisión de prueba con datos ficticios. En la definición conservar `public/{{1}}`, sin fijar números ni firmas. Es el primer botón URL, índice `0`.

La página permite consultar la revisión y previsualizar sus actas, sin nombre del propietario. No contiene un formulario de reporte de pago.

## Activación en el panel

1. Esperar la aprobación de la nueva plantilla en Meta.
2. Abrir **Servicios públicos → Plantillas de actas → Seguimiento crítico** como administrador.
3. Guardar el nombre exacto de la nueva plantilla y el código de idioma aprobado.
4. Seleccionar los funcionarios que reciben los avisos y recordatorios del evento crítico.
5. Marcar **Activar WhatsApp: la plantilla de revisión crítica está aprobada en Meta** y guardar.
6. Comprobar con una revisión de prueba que el PDF, el número de caso y el botón son correctos.

La activación anterior de las plantillas de pago no se hereda. Los mensajes nuevos de revisión que queden pendientes podrán reintentarse al activar esta plantilla. Crear este documento no crea ni aprueba plantillas en Meta.

## El proceso queda así

1. **Registrar la revisión:** el funcionario selecciona los servicios revisados y su resultado. Si alguno es crítico, se generan sus actas y se activa el nuevo flujo.
2. **Crear el caso:** se crea un único caso por revisión, aunque existan varios servicios críticos. Tema: `Servicio publico critico`; estado inicial: Nuevo; prioridad urgente; responsable inicial: creador de la revisión.
3. **Registrar en el inmueble:** el historial del inmueble deja el número del caso y de la revisión, los servicios, el vencimiento y el funcionario. El caso también tiene su historial de creación.
4. **Calcular el vencimiento:** 72 horas calendario desde el registro, incluyendo fines de semana y festivos. Una revisión del 09/10/2026 a las 10:00 vence el 12/10/2026 a las 10:00, hora de Colombia.
5. **Notificar la revisión:** WhatsApp con acta PDF y botón Ver revisión, y correo con las actas, para arrendatario, creador y funcionarios configurados. Los envíos pasan por shared-notifications.
6. **Mostrar y recordar el caso:** aparece en vencimientos y popup como Servicios críticos · 72 horas; el botón abre el caso dentro del panel. Los funcionarios seleccionados reciben recordatorio interno y sincronización Google si tienen cuenta conectada.
7. **Atender el caso:** registrar respuestas, actuaciones y evidencias en el caso. Consultar la revisión conserva sus datos y actas. El plazo no se reinicia por responder o adjuntar archivos; al superar las 72 horas, el caso abierto aparece vencido.
8. **Cerrar con el flujo normal:** al cerrar/finalizar/anular el caso, sale de los vencimientos pendientes; el procesador solicita cancelar los recordatorios. El historial y las actas permanecen disponibles.

Los seguimientos anteriores abiertos se convierten en casos una sola vez al procesarlos, conservando su fecha límite. Los comprobantes anteriores quedan como antecedentes; los seguimientos ya verificados no generan nuevos casos.

Consulta [despliegue y comprobaciones técnicas](servicios-publicos-criticos.md) y [el ejemplo del proceso simulado](simulacion-caso-servicios-publicos-criticos.md).
