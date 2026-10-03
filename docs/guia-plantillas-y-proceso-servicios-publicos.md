# Guía de plantillas WhatsApp y seguimiento de servicios públicos críticos

Esta guía explica cómo preparar las dos plantillas y cómo funciona el seguimiento de **las nuevas revisiones de servicios públicos con resultado crítico**, asociado a mora superior a 90 días. Crear las plantillas en Meta y activar su uso en el panel son pasos separados.

## 1. Cómo crear las plantillas en Meta

En **WhatsApp Manager → Plantillas de mensajes → Crear plantilla**, utiliza la cuenta de WhatsApp Business vinculada al sistema. Crea una plantilla para el requerimiento y otra para avisar a los funcionarios cuando se recibe un comprobante.

Configura ambas así:

| Campo | Configuración |
| --- | --- |
| Categoría propuesta | Utilidad / Utility. La aprobación y categoría final dependen de Meta. |
| Idioma | Español. Anota el código exacto del idioma aprobado para ingresarlo después en el panel; por ejemplo, `es` si seleccionas español genérico. |
| Variables del cuerpo | Numéricas, de `{{1}}` a `{{5}}`, en el orden indicado en esta guía. |
| Encabezado | Documento. Adjunta un PDF de ejemplo con datos ficticios. |
| Botón | Un botón **Visitar sitio web**, con URL dinámica. |
| URL base | `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/` |

El botón abre una página pública del servidor. Debe ser un botón URL, no una respuesta rápida. El sistema envía el PDF correspondiente como documento del encabezado.

### Plantilla 1: requerimiento por revisión crítica

**Nombre:** `scm_servicios_critico_72h`

**Destinatarios:** arrendatario, funcionario creador de la revisión y funcionarios configurados para el aviso crítico.

**Encabezado:** Documento; usa como muestra un acta de requerimiento ficticia.

**Cuerpo para copiar:**

```text
Hola {{1}}.

SKC SuCasa Inmobiliaria realizó una revisión de los servicios públicos del inmueble correspondiente al contrato #{{2}}.

Durante esta revisión se identificaron obligaciones pendientes en estado crítico, con mora superior a 90 días.

Detalles de los servicios y valores reportados: {{3}}

El plazo máximo para pagar y remitir el comprobante es de 72 horas desde el registro de la revisión. Fecha límite: {{4}}.

Revisión realizada por: {{5}}.

Adjuntamos el acta de requerimiento. Después de efectuar el pago, utiliza el botón «Realicé el pago» para consultar la revisión y cargar el comprobante PDF.
```

**Texto del botón:** `Realicé el pago`

**URL dinámica que debes configurar en Meta:**

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/{{1}}
```

**Ejemplos para las variables del cuerpo:**

| Variable | Significado | Ejemplo para Meta |
| --- | --- | --- |
| `{{1}}` | Nombre del destinatario | Juan Pérez |
| `{{2}}` | Código del contrato, sin el símbolo `#` | 2000 |
| `{{3}}` | Resumen del requerimiento: revisión, servicio, referencia y deuda reportada | Revisión crítica #123. Energía eléctrica, referencia 123456, deuda reportada de $350.000 COP. |
| `{{4}}` | Fecha y hora límite de pago | 06/10/2026 10:00 Colombia |
| `{{5}}` | Nombre del funcionario que creó la revisión | Carlos Ramírez |

Cuando el mensaje va a un funcionario, la primera variable lleva el nombre de ese funcionario. Las demás variables conservan los datos del mismo caso.

### Plantilla 2: comprobante recibido, pendiente de verificación

**Nombre:** `scm_servicios_pago_reportado`

**Destinatarios:** creador de la revisión, responsables configurados del requerimiento y funcionarios adicionales configurados para recibir reportes de pago. Esta plantilla es el aviso interno de recepción del soporte.

**Encabezado:** Documento; usa como muestra un comprobante PDF ficticio.

**Cuerpo para copiar:**

```text
Hola {{1}}.

SKC SuCasa Inmobiliaria recibió un comprobante de pago relacionado con el requerimiento emitido durante la revisión de servicios públicos del inmueble correspondiente al contrato #{{2}}.

Detalles del reporte recibido: {{3}}

Fecha límite establecida en el requerimiento: {{4}}.

Revisión de servicios públicos realizada por: {{5}}.

Adjuntamos el comprobante enviado por el arrendatario. El pago queda pendiente de verificación; recibir este documento no confirma que la obligación esté cancelada.

Utiliza el botón «Ver revisión» para consultar los servicios revisados y las actas emitidas.
```

**Texto del botón:** `Ver revisión`

**URL dinámica que debes configurar en Meta:**

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/{{1}}
```

**Ejemplos para las variables del cuerpo:**

| Variable | Significado | Ejemplo para Meta |
| --- | --- | --- |
| `{{1}}` | Nombre del funcionario destinatario | María López |
| `{{2}}` | Código del contrato, sin el símbolo `#` | 2000 |
| `{{3}}` | Resumen del reporte recibido | Revisión #123. Comprobante recibido el 03/10/2026 a las 15:00, pendiente de verificación. |
| `{{4}}` | Fecha y hora límite original del requerimiento | 06/10/2026 10:00 Colombia |
| `{{5}}` | Nombre del creador de la revisión | Carlos Ramírez |

### Cómo funcionan las variables de los botones

La variable `{{1}}` del botón es independiente de la variable `{{1}}` del cuerpo. En el cuerpo representa el nombre; en el botón representa **la ruta y los parámetros del enlace firmado**.

El sistema completa el botón del requerimiento con:

```text
pago-servicios-publicos.php?revision=123&expires=1791302400&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

El enlace completo del ejemplo queda así:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/pago-servicios-publicos.php?revision=123&expires=1791302400&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

El sistema completa el botón del reporte de pago con:

```text
revision-servicios-publicos.php?numero=123&expires=1791302400&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

El enlace completo del ejemplo queda así:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/revision-servicios-publicos.php?numero=123&expires=1791302400&sig=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

**Estos enlaces son ejemplos de formato, con firmas ficticias; no permiten abrir una revisión real.** Si Meta pide una muestra del valor dinámico, usa el sufijo; si pide la URL de muestra completa, usa el enlace completo. Si requiere una página accesible para la muestra, utiliza un enlace firmado generado por el sistema para una revisión de prueba con datos ficticios.

En la definición de la plantilla conserva la URL terminada en `public/{{1}}`: no fijes un contrato, una revisión ni una firma. El servidor genera el identificador, el vencimiento y la firma de cada mensaje. Ambos botones ocupan la primera posición de botón URL, índice `0`.

Referencia técnica: [ejemplos oficiales de plantillas de WhatsApp Business Platform de Meta](https://www.postman.com/meta/whatsapp-business-platform/folder/lczy75a/templates).

## 2. Activarlas en el panel

Cuando Meta haya aprobado **las dos plantillas**:

1. Abre **Servicios públicos → Plantillas de actas → Seguimiento crítico** con una cuenta administradora.
2. Guarda los nombres exactos aprobados: `scm_servicios_critico_72h` y `scm_servicios_pago_reportado`, o los nombres definitivos si los cambiaste en Meta.
3. Ingresa el código de idioma exacto que aprobó Meta. Por ejemplo, `es` para español genérico; no conserves `es_CO` solo porque aparezca como valor inicial del formulario.
4. Selecciona los funcionarios de los tres grupos que se explican abajo.
5. Marca **Activar WhatsApp: las dos plantillas ya están aprobadas en Meta** y guarda.
6. Comprueba con una revisión de prueba que se reciben el PDF, los datos correctos y el botón que abre la página correspondiente.

Los trabajos de WhatsApp que hayan quedado pendientes por falta de configuración podrán reintentarse al activar las plantillas. Revisa esos pendientes al poner el flujo en funcionamiento.

## 3. El proceso queda así, aplicado a las nuevas revisiones críticas

### Paso 1. Configurar quién recibe cada cosa

En **Servicios públicos → Plantillas de actas → Seguimiento crítico** configura estas listas, disponibles también en **Notificaciones internas**:

| Grupo | Para qué sirve |
| --- | --- |
| Avisos de revisión crítica (`servicios_publicos_critico`) | Funcionarios que reciben el requerimiento y después el aviso del comprobante. El creador se incluye en el flujo y el arrendatario recibe el requerimiento. |
| Reportes de pago (`servicios_publicos_pago_reportado`) | Funcionarios adicionales que deben recibir el comprobante y los detalles de la respuesta por WhatsApp y correo. |
| Calendario (`servicios_publicos_critico_calendario`) | Personas a quienes se les crea el recordatorio del vencimiento y se les solicita sincronización con Google Calendar. |

La selección para calendario es independiente de las listas de avisos. Cada persona seleccionada para Google Calendar necesita su cuenta Google conectada y la sincronización habilitada en el servidor.

### Paso 2. Registrar la revisión crítica

El funcionario registra la revisión y marca el servicio con resultado **Estado crítico**, incluyendo referencia o cuenta, medidor y deuda reportada. El sistema guarda la revisión, genera las actas PDF y crea el seguimiento de pago relacionado con esa revisión.

Este flujo se aplica a las nuevas revisiones nativas críticas. Las actas históricas conservan su contenido; no se generan requerimientos retroactivos automáticamente.

### Paso 3. Calcular el plazo máximo de 72 horas

La fecha límite se calcula desde el registro de la revisión, sumando **72 horas calendario**. Incluye noches, fines de semana y festivos, y se presenta con fecha y hora de Colombia.

Ejemplo: revisión registrada el **03/10/2026 a las 10:00** → fecha límite **06/10/2026 a las 10:00**.

Este vencimiento es independiente de la próxima revisión trimestral y de `mes_revision_servicios`. Las nuevas actas críticas incluyen el plazo; el editor de actas admite la variable `{{fecha_limite}}`.

### Paso 4. Avisar al arrendatario y a los responsables

Se preparan los avisos para el arrendatario, el creador y los funcionarios configurados. El WhatsApp incluye el acta crítica PDF, los detalles y el botón **Realicé el pago**. Si se generan varias actas críticas, se prepara un WhatsApp por acta. El correo incluye las actas y el enlace público.

Los mensajes pasan por la cola compartida de notificaciones, con trazabilidad y reintentos. Un mensaje encolado aún no equivale a un mensaje entregado; los errores de contacto o configuración quedan registrados.

### Paso 5. Mostrar el vencimiento y crear recordatorios

El seguimiento aparece en el **calendario de vencimientos** y en su **popup**, según los permisos y la configuración del panel.

A las personas seleccionadas para calendario se les crea un recordatorio con la hora exacta del vencimiento. La integración solicita su sincronización con **Google Calendar**. Si la cuenta no está conectada o la sincronización falla, ese resultado queda pendiente o registrado para revisión; no se considera sincronizado solo por haber creado el recordatorio interno.

### Paso 6. Recibir la evidencia del arrendatario

El botón **Realicé el pago** abre una página pública mediante un enlace firmado con vencimiento. Allí el arrendatario puede consultar la revisión y las actas, sin ver el nombre del propietario, y previsualizar y subir un comprobante **PDF de máximo 10 MB**.

El servidor valida el archivo y lo guarda en almacenamiento privado. Conserva la fecha y hora de recepción, incluso si el soporte llega después del plazo. El vencimiento del enlace y el plazo de pago son controles distintos: subir tarde no amplía las 72 horas.

### Paso 7. Avisar que se recibió el comprobante

El caso pasa a **Pago reportado, pendiente de verificación**. Se encolan avisos por WhatsApp y correo al creador, a los responsables del requerimiento y a los funcionarios adicionales configurados para reportes de pago.

El WhatsApp adjunta el comprobante y ofrece **Ver revisión**. Recibir un PDF no confirma el pago ni cierra el caso. Volver a subir el mismo PDF para esa revisión no duplica el reporte ni sus avisos.

### Paso 8. Verificar y cerrar o rechazar

Un administrador abre **Revisiones realizadas → Seguimiento · 72 horas**, consulta el soporte dentro del popup y decide con motivo y confirmación:

- **Confirmar:** cierra el seguimiento del vencimiento y solicita cancelar el recordatorio asociado.
- **Rechazar:** devuelve el caso a pendiente y conserva la fecha límite original; no concede otras 72 horas.

Se conservan las actas, los comprobantes y la auditoría. Esta verificación no crea una revisión ficticia ni cambia automáticamente el resultado registrado del servicio.

## 4. Qué debe estar listo en producción

- Desplegar la versión que incluye el seguimiento y ejecutar la migración correspondiente.
- Tener funcionando el procesamiento automático de trabajos y la cola compartida de notificaciones.
- Aprobar en Meta las dos plantillas y guardar nombres, idioma y activación en el panel.
- Configurar destinatarios con datos de contacto válidos.
- Conectar las cuentas Google de las personas seleccionadas para calendario.
- Permitir la carga de PDFs de 10 MB y conservar el almacenamiento privado entre despliegues.

Para los comandos de despliegue, configuración del procesador y detalles de integración, consulta [Seguimiento de servicios públicos críticos](servicios-publicos-criticos.md).

Esta guía no crea ni aprueba plantillas en Meta. Describe la configuración que debe realizarse y el comportamiento implementado del flujo.
