# Crear la plantilla de WhatsApp de las comunicaciones de casos jurídicos

Se usa cuando en **Casos jurídicos › Nueva comunicación** se marca el canal WhatsApp. Avisa al destinatario y le da un botón para leer el mensaje completo y sus adjuntos; el cuerpo del mensaje no cabe en una plantilla.

Mientras Meta no la apruebe, los envíos por WhatsApp quedarán como **Fallido** en la comunicación. El correo no se ve afectado.

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Selecciona la misma cuenta de WhatsApp que utiliza el panel.

- Categoría: **Utilidad** (`UTILITY`).
- Nombre exacto: `scm_comunicacion_caso_v1`.
- Idioma: **Español (Colombia)** (`es_CO`).
- Tipo de variables: **Número** (`{{1}}`, `{{2}}`…).
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.

## Cuerpo para copiar

```text
Hola {{1}}.

SKC SuCasa Inmobiliaria te envió una comunicación relacionada con el caso {{2}}.

Asunto: {{3}}

Consulta el contenido completo y sus documentos desde el botón.

Atentamente,
{{4}}
```

## Ejemplos de variables

| Variable | Contenido | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del destinatario | María Pérez |
| 2 | Código del caso | CASO-0001 |
| 3 | Asunto de la comunicación | Requerimiento por daños en el apartamento 401 |
| 4 | Firma del funcionario | Royner Guardo - Asistente de Desarrollo TI - Cel. 3015232054 |

## Botón

- Tipo: **Visitar sitio web**, URL **dinámica**.
- Texto: `Ver comunicación`.
- URL: `https://sucasainmobiliaria.com.co/{{1}}`.
- Ejemplo: `https://sucasainmobiliaria.com.co/comunicacion-caso.php?numero=1&expires=1893456000&sig=ejemplo`.

Envía la plantilla a revisión y espera a que quede **Activa** antes de usar el canal WhatsApp. La definición completa está en `docs/whatsapp-comunicacion-caso-template.json`.
