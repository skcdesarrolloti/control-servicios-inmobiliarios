# Crear la plantilla de respuesta de cotización

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Selecciona la misma cuenta de WhatsApp que utiliza el panel.

- Nombre exacto: `scm_cotizacion_mantenimiento_respuesta_v1`.
- Categoría: **Utilidad** (`UTILITY`).
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno. Esta notificación no lleva PDF adjunto.
- Pie de página: ninguno; la empresa ya está incluida en el cuerpo.

## Cuerpo para copiar

```text
Buen día, {{1}}.

Se registró una respuesta a la cotización de mantenimiento #{{2}} del caso #{{3}}.

Respuesta: {{4}}.
Destinatario: {{5}}.
Contrato: {{6}} · Inmueble SIMI: {{7}}.
Detalle: {{8}}.

Puedes consultar la cotización desde el botón.

SKC SuCasa Inmobiliaria
```

## Ejemplos de variables

| Variable | Ejemplo |
| --- | --- |
| 1 | Royner Guardo |
| 2 | 570 |
| 3 | 10841 |
| 4 | Aprobada |
| 5 | María Pérez |
| 6 | 2000 |
| 7 | 204578 |
| 8 | Autorizo los trabajos. Financiación: No |

## Botón

Agrega un botón **Visitar sitio web** con:

- Texto: `Ver cotización`.
- Tipo de URL: **Dinámica**.
- URL: `https://sucasainmobiliaria.com.co/{{1}}`.
- Ejemplo del sufijo: `control-servicios-inmobiliarios/public/cotizacion-mantenimiento.php?numero=570&expires=1790000000&sig=demo`.

Si el formulario solicita una URL de ejemplo completa, usa `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/cotizacion-mantenimiento.php?numero=570&expires=1790000000&sig=demo`.

La variable del botón es independiente de las ocho del cuerpo. El enlace de ejemplo demuestra el formato; el panel genera una firma y vencimiento válidos para cada envío.

Envía la plantilla a revisión y verifica que aparezca **Aprobada** antes de probar el envío. No cambies el nombre, idioma, orden de variables ni base del botón: deben coincidir con el código del panel. El JSON `whatsapp-cotizacion-mantenimiento-respuesta-template.json` es la definición para API; no se pega completo en el campo del cuerpo.

## Firmas de las otras plantillas

En las plantillas que cierran con **Atentamente**, el sistema envía en la variable de firma una sola línea:

`Nombre del funcionario - Cargo del catálogo - Cel. Teléfono`

Se completaron las firmas del envío de cotizaciones, órdenes de mantenimiento, comunicaciones preventivas, seguimientos de reparaciones, reembolsos de servicios y cartas de aumento. Las administrativas, cobranza, terminación y no prórroga ya resuelven esos datos. La revisión correctiva pública y el PDF del liquidador también disponen de cargo y contacto en su cierre.

Estas correcciones conservan los nombres y cantidades de variables de las plantillas existentes. No requieren recrear las aprobadas en Meta. Completa el cargo y teléfono del funcionario en su registro: el sistema no inventa datos ausentes. En las cartas de aumento se utiliza la identidad del coordinador contractual.

La plantilla de **respuesta** anterior es un aviso automático de la decisión del destinatario, por lo que cierra con la empresa y no firma en nombre de un funcionario.

Referencia oficial: [Plantillas de Meta WhatsApp Business Platform](https://www.postman.com/meta/whatsapp-business-platform/folder/lczy75a/templates).
