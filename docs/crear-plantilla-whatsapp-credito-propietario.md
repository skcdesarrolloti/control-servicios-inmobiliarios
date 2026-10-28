# Plantillas de WhatsApp de créditos a propietarios

## Plantilla para el propietario al registrar la solicitud

Se envía al **propietario** cuando se registra su solicitud de crédito, desde **Nueva solicitud** o desde una cotización con financiación **Sí**. Le confirma que realizó la solicitud y le recuerda que queda sujeta a estudio, verificación y aprobación; no es una aprobación. Si ya existía con el texto anterior ("Recibimos su solicitud…"), edítala en Meta con este cuerpo: mismo nombre, idioma y 3 variables. Solo se envía si en **Configuración** de créditos está activo **WhatsApp al propietario al registrar la solicitud**. El correo de confirmación sale aparte, con su propia opción.

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
Buen día, {{1}}.

Usted realizó una solicitud de crédito por {{2}} asociada al contrato #{{3}}.

Recuerde que el crédito queda sujeto a estudio, verificación y aprobación. Le informaremos el resultado por este medio.
```

### Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | David Esquivia Polo |
| 2 | Valor solicitado y plazo; si viene de una cotización, también cuál financia | $893.723 a 12 meses · $893.723 a 12 meses para financiar la cotización #574 |
| 3 | Número del contrato | 817 |

La definición para la API está en `whatsapp-credito-propietario-solicitud-template.json`.

## Plantilla para el propietario al decidir

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
Buen día, {{1}}.

Su solicitud de crédito asociada al contrato #{{3}} fue {{2}}.

{{4}}.

El detalle completo llegó a su correo.
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

- Nombre exacto: `scm_credito_propietario_funcionario_v2`.
- Categoría: **Utilidad** (`UTILITY`).
- Idioma: **Español (COL)** (`es_CO`). No uses English.
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.

La v1 fue reclasificada por Meta como **marketing** y ya no se usa. La v2 describe la actualización de una solicitud asignada en el panel, sin lenguaje de oferta ni promoción; no le agregues frases como "aprovecha", "oferta", "crédito preaprobado" o similares.

### Cuerpo para copiar

```text
Hola, {{1}}. Hay una actualización en una solicitud registrada en el panel de servicios inmobiliarios.

Estado: {{2}}.
Solicitud número: {{3}}.
Contrato de arrendamiento: {{4}}.
Propietario: {{5}}.
Detalle: {{6}}.

Revisa la solicitud desde el botón para continuar la gestión.
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
- Ejemplo del sufijo: `control-servicios-inmobiliarios/public/index.php?tab=credito_propietario`.

Cuando Meta la marque como **Aprobada**, activa la opción en Configuración. La definición para la API está en `whatsapp-credito-propietario-funcionario-v2-template.json`.

## Plantillas del pagaré y la carta de instrucciones

El propietario firma una sola vez el pagaré en blanco y su carta de instrucciones, desde un enlace personal (`credito-pagare.php`). El analista lo envía desde el liquidador, en la sección **Pagaré y carta de instrucciones**, eligiendo **Correo**, **WhatsApp** o ambos. Hasta que Meta apruebe estas plantillas, envíalo solo por correo: por WhatsApp no saldrá nada.

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
