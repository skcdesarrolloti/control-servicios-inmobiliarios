# Crear la plantilla de envío de revisión preventiva

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Selecciona la misma cuenta de WhatsApp que utiliza el panel.

- Categoría: **Utilidad** (`UTILITY`).
- Nombre exacto: `scm_revision_preventiva_envio_v1`.
- Idioma: **Español (Colombia)** (`es_CO`).
- Tipo de variables: **Número** (`{{1}}`, `{{2}}`…).
- Encabezado: **Documento**. Meta pide subir un PDF de ejemplo: sirve cualquier PDF, por ejemplo uno descargado con *Descargar PDF* de una revisión. En cada envío el panel reemplaza ese documento por el PDF real de la revisión.
- Pie de página: `SKC SuCasa Inmobiliaria`.

## Cuerpo para copiar

```text
Buen día, {{1}}.

Le compartimos el informe de la revisión preventiva #{{2}} del inmueble con contrato #{{3}}, ubicado en {{4}}.

Adjuntamos el PDF y también puede consultarlo desde el botón.

Atentamente,
{{5}}
```

## Ejemplos de variables

| Variable | Contenido | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario o arrendatario | María Pérez |
| 2 | Número de la revisión preventiva | 272 |
| 3 | Contrato | 660 |
| 4 | Dirección del inmueble | Urbanización Simón Bolívar manzana 20 lote 7 |
| 5 | Firma del funcionario | Funcionario de ejemplo - Coordinador contractual - Cel. 3001234567 |

## Botón

Agrega un botón **Visitar sitio web** con:

- Texto: `Ver informe`.
- Tipo de URL: **Dinámica**.
- URL: `https://sucasainmobiliaria.com.co/{{1}}`.
- Ejemplo del sufijo: `control-servicios-inmobiliarios/public/revision-preventiva.php?numero=272&expires=1790000000&sig=demo`.

Si el formulario solicita una URL de ejemplo completa, usa `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/revision-preventiva.php?numero=272&expires=1790000000&sig=demo`.

La variable del botón es independiente de las cinco del cuerpo. El panel genera en cada envío un enlace firmado con vencimiento.

## Antes de usarla

Envía la plantilla a revisión y espera a que aparezca **Aprobada**. Mientras no esté aprobada, los WhatsApp de este envío quedan rechazados en la cola; los correos sí salen.

No cambies el nombre, el idioma, el encabezado de documento, el orden de las variables ni la base del botón: deben coincidir con el código del panel (`preventiveReviewEnqueueSentWhatsapp`). El archivo `whatsapp-revision-preventiva-envio-template.json` es la misma definición en formato API; no se pega en el campo del cuerpo.

Si Meta rechaza el texto o pide cambios, avisa antes de editarlo: cualquier cambio de variables o de nombre exige ajustar también el código.
