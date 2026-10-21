# Crear la plantilla del aviso previo de revisión antes de la desocupación

Se envía al arrendatario cuando se crea un caso de **Recibo de inmuebles** (Crear nuevo caso, Contratos o recibo automático), junto con el correo. El responsable y los funcionarios del aviso interno reciben la plantilla de caso asignado (`scm_caso_asignado_v1`).

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Selecciona la misma cuenta de WhatsApp que utiliza el panel.

- Categoría: **Utilidad** (`UTILITY`).
- Nombre exacto: `scm_aviso_revision_desocupacion_v1`.
- Idioma: **Español (Colombia)** (`es_CO`).
- Tipo de variables: **Número** (`{{1}}`, `{{2}}`…).
- Encabezado: **Documento**. Meta pide subir un PDF de ejemplo: sirve cualquier PDF, por ejemplo el aviso de un recibo ya creado. En cada envío el panel reemplaza ese documento por el PDF real del aviso.
- Pie de página: `SKC SuCasa Inmobiliaria`.

## Cuerpo para copiar

```text
Buen día, {{1}}.

Le informamos que su contrato de arrendamiento #{{2}} del inmueble ubicado en {{3}} se encuentra próximo a finalizar.

Adjuntamos el aviso previo de revisión antes de la desocupación del inmueble con las condiciones para el recibo. También puede consultarlo desde el botón.

Para coordinar la revisión comuníquese con {{4}}.
```

## Ejemplos de variables

| Variable | Contenido | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del arrendatario | María Pérez |
| 2 | Contrato | 1380 |
| 3 | Dirección del inmueble | Cra. 3 #8-129, Edificio Ejemplo Apto 502, Bocagrande |
| 4 | Responsable del recibo y su celular | Funcionario de ejemplo · Cel. 3001234567 |

## Botón

Agrega un botón **Visitar sitio web** con:

- Texto: `Ver aviso`.
- Tipo de URL: **Dinámica**.
- URL: `https://sucasainmobiliaria.com.co/{{1}}`.
- Ejemplo del sufijo: `control-servicios-inmobiliarios/public/?scm_case=10452.1790000000.arrendatario.demo`.

Si el formulario solicita una URL de ejemplo completa, usa `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10452.1790000000.arrendatario.demo`.

La variable del botón es independiente de las cuatro del cuerpo. El panel genera en cada envío un enlace firmado con vencimiento a la página pública del caso, donde el arrendatario ve el aviso sin salir de la página.

## Antes de usarla

Envía la plantilla a revisión y espera a que aparezca **Aprobada**. Mientras no esté aprobada, el WhatsApp al arrendatario queda rechazado en la cola; el correo con el aviso sí sale y el recibo se crea igual.

No cambies el nombre, el idioma, el encabezado de documento, el orden de las variables ni la base del botón: deben coincidir con el código del panel (`PanelCaseNotifications::enqueueReceiptWhatsApp`). El archivo `whatsapp-aviso-revision-desocupacion-template.json` es la misma definición en formato API; no se pega en el campo del cuerpo.

Si Meta rechaza el texto o pide cambios, avisa antes de editarlo: cualquier cambio de variables o de nombre exige ajustar también el código.
