# Plantilla de WhatsApp: respuesta del caso

Una sola plantilla se usa en los dos sentidos de la conversación de un caso:

- **El funcionario responde desde el panel**: llega a los actores marcados en la respuesta (solicitante, propietario y arrendatario) y al responsable asignado cuando responde otra persona. Las copias internas siguen solo por correo.
- **Un actor responde desde la vista pública** (`?scm_case=`): llega al responsable asignado y a las copias del evento **Respuesta de un actor en la vista pública** de Notificaciones internas.

El botón de los actores abre su vista pública del caso, donde pueden volver a responder. El de los funcionarios inicia su sesión y abre el caso en el panel.

## Crear la plantilla en Meta

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla** con la misma cuenta de WhatsApp del panel.

- Categoría: **Utilidad** (`UTILITY`).
- Nombre exacto: `scm_caso_respuesta_v1`.
- Idioma: **Español (Colombia)** (`es_CO`).
- Tipo de variables: **Número** (`{{1}}`, `{{2}}`…).
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.

## Cuerpo para copiar

```text
Hola {{1}}.

El caso #{{2}} tiene una nueva respuesta de {{3}}.

*Asunto:* {{4}}
*Estado:* {{5}}
*Respuesta:* {{6}}

Consulta el caso completo y responde desde el botón.
```

## Ejemplos de variables

| Variable | Contenido | Ejemplo |
| --- | --- | --- |
| 1 | Nombre de quien recibe | María Pérez |
| 2 | Número del caso | 10945 |
| 3 | Quién respondió | Propietario · Juan Gómez |
| 4 | Asunto del caso | Revisión de humedad en el baño |
| 5 | Estado del caso | En proceso |
| 6 | Texto de la respuesta (máximo 500 caracteres) | Ya programamos la visita del técnico para el jueves en la mañana. |

## Botón

- Tipo: **Visitar sitio web**, URL **dinámica**.
- Texto: `Ver caso`.
- URL: `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case={{1}}`.
- Ejemplo: `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10945.1893456000.propietario.ejemplo`.

## Activación

El envío queda apagado hasta que Meta apruebe la plantilla; mientras tanto las respuestas salen solo por correo. Cuando quede **Activa**, el administrador técnico establece `reply_enabled = true` en la configuración interna `panel_case_notifications`. El nombre se puede cambiar con `reply_template`. La definición completa está en `docs/whatsapp-respuesta-caso-template.json`.

Estado: activada en producción el 2026-11-26 (`reply_enabled = true`). Los avisos de la vista pública al funcionario asignado salen por correo y WhatsApp; las copias se configuran en el evento **Respuesta de un actor en la vista pública** de Notificaciones internas.

## Correos adicionales (CC) del caso

Los correos escritos en **Correos adicionales (CC)** al responder o hacer seguimiento quedan guardados en `correos_adicionales` del caso (CCT `tickets`, pestaña Complementarios, máximo 50). En los siguientes envíos aparecen como chips para agregarlos con un clic o con **Usar todos**; la ✕ los quita del caso con confirmación, sin afectar envíos anteriores.
