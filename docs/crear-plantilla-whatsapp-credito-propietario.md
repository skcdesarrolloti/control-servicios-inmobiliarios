# Plantillas de WhatsApp de créditos a propietarios

## Plantilla para el propietario al registrar la solicitud

Se envía al **propietario** cuando se registra su solicitud de crédito, desde **Nueva solicitud** o desde una cotización con financiación **Sí**. Le confirma que realizó la solicitud y le recuerda que queda sujeta a estudio, verificación y aprobación; no es una aprobación. Si ya existía con el texto anterior ("Recibimos su solicitud…"), edítala en Meta con este cuerpo (mismo nombre e idioma; ahora lleva 4 variables). Si Meta no deja agregar la variable al editar, créala como `scm_credito_propietario_solicitud_v2` y cambia el nombre en el código. Solo se envía si en **Configuración** de créditos está activo **WhatsApp al propietario al registrar la solicitud**. El correo de confirmación sale aparte, con su propia opción.

- Nombre exacto: `scm_credito_propietario_solicitud_v1`.
- Categoría: **Utilidad** (`UTILITY`): confirma una solicitud que hizo el propietario.
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botones: ninguno.

No le agregues frases de oferta ("aprovecha", "preaprobado", "crédito disponible"): Meta la reclasificaría como marketing.

### Cuerpo para copiar

```text
Hola, {{1}}.

Usted realizó una solicitud de crédito por {{2}} asociada al contrato #{{3}}.

Recuerde que el crédito queda sujeto a estudio, verificación y aprobación. Le informaremos el resultado por este medio.

Atentamente,
{{4}}

Gracias por confiar en nosotros.
```

El cuerpo no puede terminar en una variable: por eso cierra con "Gracias por confiar en nosotros.".

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | David Esquivia Polo |
| 2 | Valor solicitado y plazo; si viene de una cotización, también cuál financia | $893.723 a 12 meses · $893.723 a 12 meses para financiar la cotización #574 |
| 3 | Número del contrato | 817 |
| 4 | Quien firma: nombre - cargo - Cel. celular. En una cotización, el funcionario que la envió; en una solicitud manual, quien la registró | Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054 |

La definición para la API está en `whatsapp-credito-propietario-solicitud-template.json`.

## Plantilla para el propietario al decidir

Se envía al **propietario del contrato** cuando un funcionario **rechaza** su solicitud de crédito en el liquidador. Al aprobar ya no se usa: el propietario recibe la respuesta con los documentos (ver **Plantillas de la respuesta con documentos**). El número es el `celular_propietario` del contrato de arrendamiento, con su `indicativo_propietario`. Solo se envía si en **Métricas › Créditos a propietarios › Configuración** está activo **WhatsApp**. El correo con las condiciones y la tabla de amortización sale aparte.

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
Hola, {{1}}.

Revisamos su solicitud de crédito para {{2}} asociada al contrato #{{3}}.

{{4}}.

Atentamente,
{{5}}

Gracias por confiar en nosotros.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | David Esquivia Polo |
| 2 | Destinación del crédito, en minúscula | reparaciones y mantenimiento |
| 3 | Número del contrato | 817 |
| 4 | El resultado. Si se aprueba: valor, plazo y cuota; si se rechaza: un texto fijo | Fue aprobada por $893.723 a 12 meses, con cuota mensual de $86.070 que se descontará de los giros de arrendamiento |
| 5 | Quien firma: nombre - cargo - Cel. celular del analista que decidió | Laura Pérez - Analista de crédito - Cel. 3001234567 |

Si se rechaza, la variable 4 es: `En esta oportunidad no fue aprobada. Si desea revisar alternativas, comuníquese con nosotros`.

Esta versión reemplaza el cuerpo anterior de 4 variables: se edita la misma plantilla en Meta. Si Meta no permite agregar la quinta variable al editarla, crea `scm_credito_propietario_decision_v2` con este contenido y cambia el nombre en `OwnerCreditService::notifyDecision`.

Envía la plantilla a revisión y espera a que aparezca **Aprobada**. Después activa **WhatsApp** en la configuración de créditos. No cambies el nombre, el idioma ni el orden de las variables, porque deben coincidir con el código. La definición para la API está en `whatsapp-credito-propietario-decision-template.json`; no pegues ese JSON en el campo del cuerpo.

## Plantilla para funcionarios al aprobar o rechazar

Mensaje corto a los **funcionarios de Notificaciones internas › Créditos a propietarios › Crédito aprobado o rechazado** cuando un analista decide en el liquidador. Solo se envía si en **Configuración** de créditos está activo **WhatsApp a funcionarios**; el correo interno sale siempre. Las solicitudes nuevas usan `scm_credito_propietario_solicitud_funcionario_v1` (más abajo).

Reemplaza a `scm_credito_propietario_funcionario_v2`, que mezclaba solicitudes y decisiones en un mensaje genérico; ya no se usa y se puede eliminar de Meta.

- Nombre exacto: `scm_credito_propietario_respuesta_funcionario_v1`.
- Categoría: **Utilidad** (`UTILITY`). Sin lenguaje de oferta ("aprovecha", "preaprobado"): Meta la reclasificaría como marketing.
- Idioma: **Español (COL)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botón: **Visitar sitio web**, texto `Abrir créditos`, URL dinámica `https://sucasainmobiliaria.com.co/{{1}}`, ejemplo `control-servicios-inmobiliarios/public/index.php?tab=credito_propietario`.

### Cuerpo para copiar

```text
Hola, {{1}}. Se respondió una solicitud de crédito.

Resultado: {{2}}
Propietario: {{3}}
Detalle: {{4}}
Contrato: {{5}}
Decidió: {{6}}

Revísala en el liquidador desde el botón.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Royner Guardo |
| 2 | `Aprobado`, `Aprobado fuera de política` o `No aprobado` | Aprobado |
| 3 | Propietario | Royner Andres Guardo Hernandez |
| 4 | Aprobado: valor, plazo y cuota. No aprobado: motivo | $2.238.000 a 10 meses, cuota $251.430 |
| 5 | Contrato e inmueble | 2000 · Inmueble 204578 |
| 6 | Quién decidió | Laura Pérez |

La definición para la API está en `whatsapp-credito-propietario-respuesta-funcionario-template.json`.

## Plantillas del pagaré y la carta de instrucciones

El propietario firma una sola vez el pagaré en blanco y su carta de instrucciones, desde un enlace personal (`credito-pagare.php`). Se firma **después de que el analista aprueba**: al aprobar, el panel envía el enlace a quien no lo tenga firmado (por correo y, si está activo **WhatsApp al propietario con la respuesta**, también por WhatsApp). El analista lo puede reenviar desde la etapa **3. Firma de documentos** del liquidador. Al firmar sale sola la respuesta. Hasta que Meta apruebe estas plantillas, envíalo solo por correo: por WhatsApp no saldrá nada.

Para el código de 6 dígitos se reutiliza la plantilla de autenticación del acta (`scm_acta_firma_otp_v1`), que no menciona el acta. No hay que crear otra.

### Invitación a firmar

No menciones el código de verificación en el cuerpo: Meta la reclasifica como **Autenticación** y la rechaza. El código lo envía la plantilla del acta.

- Nombre exacto: `scm_credito_pagare_firma_v1` (se puede cambiar con la variable de entorno `SCM_PAGARE_WHATSAPP_INVITATION_TEMPLATE`).
- Categoría: **Utilidad** (`UTILITY`).
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botones: ninguno.

```text
Hola {{1}}.

Te enviamos el pagaré No. {{2}} y su carta de instrucciones para que los revises y firmes electrónicamente. Se firman una sola vez y respaldan los créditos que tomes con el Pasaporte SuCasa.

Revisar y firmar: {{3}}

Este enlace es personal. No lo compartas.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | María Pérez |
| 2 | Número del pagaré | PS-000012 |
| 3 | Enlace personal para firmar | `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/credito-pagare.php?id=12&token=EJEMPLO_NO_VALIDO` |

La definición para la API está en `whatsapp-credito-pagare-firma-template.json`.

### Copia firmada

Solo le llega a quien firma cuando la respuesta del crédito no le lleva ya el pagaré firmado (por ejemplo, un copropietario que firma un crédito de otra persona). Al beneficiario el pagaré firmado le llega en la respuesta.

- Nombre exacto: `scm_credito_pagare_firmado_v1` (variable de entorno `SCM_PAGARE_WHATSAPP_SIGNED_TEMPLATE`).
- Mismas opciones que la invitación.

```text
Hola {{1}}.

Confirmamos que quedó firmado tu pagaré No. {{2}} con su carta de instrucciones. Respalda los créditos que tomes con el Pasaporte SuCasa: no tendrás que firmarlo de nuevo.

Descarga tu copia firmada aquí: {{3}}

Conserva el documento para tu registro. Este enlace es personal; no lo compartas.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | María Pérez |
| 2 | Número del pagaré | PS-000012 |
| 3 | Enlace para descargar el PDF firmado | `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/credito-pagare.php?id=12&token=EJEMPLO_NO_VALIDO&format=pdf` |

La definición para la API está en `whatsapp-credito-pagare-firmado-template.json`.

Los funcionarios reciben un correo cuando el pagaré queda firmado si en **Notificaciones internas** está configurado el evento **Créditos a propietarios › Pagaré firmado**.

## Plantilla para funcionarios al llegar una solicitud

Mensaje corto a los funcionarios de **Solicitud de crédito** (manual) o **Solicitud de crédito desde cotización**, según el origen. Solo se envía si en **Configuración** de créditos está activo **WhatsApp a funcionarios**.

- Nombre exacto: `scm_credito_propietario_solicitud_funcionario_v1`.
- Categoría: **Utilidad** (`UTILITY`). Sin lenguaje de oferta ("aprovecha", "preaprobado"): Meta la reclasificaría como marketing.
- Idioma: **Español (COL)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botón: **Visitar sitio web**, texto `Abrir créditos`, URL dinámica `https://sucasainmobiliaria.com.co/{{1}}`, ejemplo `control-servicios-inmobiliarios/public/index.php?tab=credito_propietario`.

### Cuerpo para copiar

```text
Hola, {{1}}. Hay una nueva solicitud de crédito para revisar.

Propietario: {{2}}
Solicita: {{3}}
Destinación: {{4}}
Contrato: {{5}}
Origen: {{6}}

Revísala en el liquidador desde el botón.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Royner Guardo |
| 2 | Propietario | Royner Andres Guardo Hernandez |
| 3 | Valor y plazo solicitados | $2.238.000 a 10 meses |
| 4 | Destinación del crédito | Reparaciones y mantenimiento |
| 5 | Contrato e inmueble | 2000 · Inmueble 204578 |
| 6 | Origen | Cotización #589 · Solicitud manual |

La definición para la API está en `whatsapp-credito-propietario-solicitud-funcionario-template.json`.

## Plantillas de la respuesta con documentos

Cuando el propietario firma el pagaré de un crédito aprobado (o al aprobar, si ya lo tenía firmado), el panel le envía la **respuesta**: un correo con los tres PDF adjuntos y, por WhatsApp, **un mensaje por documento**, porque una plantilla con encabezado de documento solo lleva un PDF. Los mismos mensajes van a los funcionarios de **Notificaciones internas › Créditos a propietarios › Respuesta de crédito enviada** (en {{1}} va el nombre del funcionario).

- WhatsApp al propietario: **Configuración › WhatsApp al propietario con la respuesta**.
- WhatsApp a funcionarios: **Configuración › WhatsApp a funcionarios**.
- Las tres plantillas usan las mismas opciones:
  - Categoría: **Utilidad** (`UTILITY`).
  - Idioma: **Español (Colombia)** (`es_CO`).
  - Variables: **numéricas**.
  - Encabezado: **Documento** (en el ejemplo sube cualquier PDF; el panel manda el documento real en cada envío).
  - Pie de página: `SKC SuCasa Inmobiliaria`.
  - Botones: ninguno.

Pasos en Meta: **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla → Utilidad → Personalizada**, nombre exacto, idioma **Español (COL)**, en **Encabezado** elige **Medios → Documento**, pega el cuerpo, completa los ejemplos de las variables, agrega el pie y envía a revisión. El cuerpo no puede empezar ni terminar en una variable: por eso cierran con una frase fija.

### Carta de aprobación

- Nombre exacto: `scm_credito_carta_aprobacion_v1`.

```text
Buen día, {{1}}.

Adjuntamos la carta de aprobación del crédito No. {{2}} de {{3}}, por {{4}} a {{5}} meses, con cuota mensual de {{6}} que se descontará de los giros de arrendamiento.

Atentamente,
{{7}}

Gracias por confiar en nosotros.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Quien recibe (propietario o funcionario) | María Pérez |
| 2 | Número del crédito | 5 |
| 3 | Propietario del crédito | María Pérez |
| 4 | Valor aprobado | $3.000.000 |
| 5 | Plazo en meses | 12 |
| 6 | Cuota mensual | $285.424 |
| 7 | Quien aprobó: nombre - cargo - Cel. celular | Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054 |

### Pagaré firmado

- Nombre exacto: `scm_credito_pagare_firmado_doc_v1`.

```text
Buen día, {{1}}.

Adjuntamos el pagaré No. {{2}} y su carta de instrucciones, firmados electrónicamente por {{3}} el {{4}}. Respaldan el crédito No. {{5}} y los demás créditos del Pasaporte SuCasa.

Atentamente,
{{6}}

Conserve este documento para su registro.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Quien recibe | María Pérez |
| 2 | Número del pagaré | PS-000012 |
| 3 | Quien firmó el pagaré | María Pérez |
| 4 | Fecha de la firma | 29/10/2026 |
| 5 | Número del crédito | 5 |
| 6 | Quien aprobó: nombre - cargo - Cel. celular | Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054 |

### Condiciones y plan de pagos

- Nombre exacto: `scm_credito_plan_pagos_v1`.

```text
Buen día, {{1}}.

Adjuntamos las condiciones y el plan de pagos del crédito No. {{2}} de {{3}}: {{4}} cuotas mensuales de {{5}}, la primera con vencimiento el {{6}}, que se descontarán de los giros de arrendamiento.

Atentamente,
{{7}}

Gracias por confiar en nosotros.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Quien recibe | María Pérez |
| 2 | Número del crédito | 5 |
| 3 | Propietario del crédito | María Pérez |
| 4 | Número de cuotas | 12 |
| 5 | Valor de la cuota | $285.424 |
| 6 | Vencimiento de la primera cuota | 30/11/2026 |
| 7 | Quien aprobó: nombre - cargo - Cel. celular | Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054 |

Las definiciones para la API están en `whatsapp-credito-carta-aprobacion-template.json`, `whatsapp-credito-pagare-firmado-doc-template.json` y `whatsapp-credito-plan-pagos-template.json`.
