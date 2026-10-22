# Plantillas de WhatsApp de créditos a propietarios

## Plantilla para el propietario

Se envía al **propietario del contrato** cuando un funcionario aprueba o rechaza su solicitud de crédito en el liquidador. El número es el `celular_propietario` del contrato de arrendamiento, con su `indicativo_propietario`. Solo se envía si en **Métricas › Créditos a propietarios › Configuración** está activo **WhatsApp**. El correo con las condiciones y la tabla de amortización sale aparte.

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Usa la misma cuenta de WhatsApp que usa el panel.

Selecciona el idioma **Español (COL)**, no English: el panel envía `es_CO`. Meta rechaza cuerpos con muchas variables y poco texto fijo ("demasiadas variables en relación con su longitud"); por eso los cuerpos llevan frases fijas alrededor de las variables.

- Nombre exacto: `scm_credito_propietario_decision_v1`.
- Categoría: **Utilidad** (`UTILITY`): informa el resultado de una solicitud que hizo el propietario.
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botones: ninguno.

### Cuerpo para copiar

```text
Buen día, {{1}}. Le escribimos de SKC SuCasa Inmobiliaria sobre la solicitud de crédito que realizó como propietario.

Su solicitud de crédito asociada al contrato de arrendamiento número {{3}} fue {{2}}.

Detalle: {{4}}.

Le enviamos el detalle completo al correo registrado. Si tiene preguntas, puede responder este mensaje o comunicarse con nuestro equipo.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | David Esquivia Polo |
| 2 | `aprobada` o `no aprobada` | aprobada |
| 3 | Número del contrato | 817 |
| 4 | Si se aprueba: valor, plazo y cuota. Si se rechaza: un texto fijo | $893.723 a 12 meses, cuota de $86.070 |

Si se rechaza, la variable 4 es: `Comunícate con nosotros para revisar alternativas`.

Envía la plantilla a revisión y espera a que aparezca **Aprobada**. Después activa **WhatsApp** en la configuración de créditos. No cambies el nombre, el idioma ni el orden de las variables, porque deben coincidir con el código. La definición para la API está en `whatsapp-credito-propietario-decision-template.json`; no pegues ese JSON en el campo del cuerpo.

## Plantilla para funcionarios

Se envía a los **funcionarios elegidos en Notificaciones internas › Créditos a propietarios** en dos momentos: cuando llega una **solicitud de crédito** y cuando un crédito se **aprueba o rechaza**. El celular es el registrado para cada funcionario. Solo se envía si en **Configuración** de créditos está activo **WhatsApp al solicitar y al decidir**. El correo interno sale siempre.

- Nombre exacto: `scm_credito_propietario_funcionario_v1`.
- Categoría: **Utilidad** (`UTILITY`).
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.

### Cuerpo para copiar

```text
Hola, {{1}}. Te escribimos desde el panel de SKC SuCasa Inmobiliaria con una novedad de créditos a propietarios.

Novedad: {{2}} (crédito número {{3}}).
Contrato de arrendamiento: {{4}}.
Propietario del inmueble: {{5}}.
Detalle de la gestión: {{6}}.

Ingresa al panel desde el botón para revisar el liquidador, las políticas y la tabla de amortización del crédito.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Royner Guardo |
| 2 | `Nueva solicitud de crédito`, `Crédito aprobado` o `Crédito no aprobado` | Nueva solicitud de crédito |
| 3 | Número del crédito | 12 |
| 4 | Número del contrato | 817 |
| 5 | Propietario | David Esquivia Polo |
| 6 | Solicitud: valor y cotización. Aprobado: valor, plazo, cuota y si quedó fuera de política. Rechazado: motivo. Siempre indica quién decidió | Solicita $893.723 para la cotización #574. Revísala en el liquidador |

### Botón

Agrega un botón **Visitar sitio web** con:

- Texto: `Abrir créditos`.
- Tipo de URL: **Dinámica**.
- URL: `https://sucasainmobiliaria.com.co/{{1}}`.
- Ejemplo del sufijo: `control-servicios-inmobiliarios/public/index.php?tab=metricas_creditos`.

Cuando Meta la marque como **Aprobada**, activa la opción en Configuración. La definición para la API está en `whatsapp-credito-propietario-funcionario-template.json`.
