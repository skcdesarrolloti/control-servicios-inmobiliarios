# Crear la plantilla de decisión de crédito al propietario

Se envía al **propietario del contrato** cuando un funcionario aprueba o rechaza su solicitud de crédito en el liquidador. El número es el `celular_propietario` del contrato de arrendamiento, con su `indicativo_propietario`. Solo se envía si en **Métricas › Créditos a propietarios › Configuración** está activo **WhatsApp**. El correo con las condiciones y la tabla de amortización sale aparte.

En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Usa la misma cuenta de WhatsApp que usa el panel.

- Nombre exacto: `scm_credito_propietario_decision_v1`.
- Categoría: **Utilidad** (`UTILITY`): informa el resultado de una solicitud que hizo el propietario.
- Idioma: **Español (Colombia)** (`es_CO`).
- Variables: **numéricas**.
- Encabezado: ninguno.
- Pie de página: `SKC SuCasa Inmobiliaria`.
- Botones: ninguno.

## Cuerpo para copiar

```text
Buen día, {{1}}.

Su solicitud de crédito asociada al contrato #{{3}} fue {{2}}.

{{4}}.

El detalle completo llegó a su correo.
```

## Variables

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | David Esquivia Polo |
| 2 | `aprobada` o `no aprobada` | aprobada |
| 3 | Número del contrato | 817 |
| 4 | Si se aprueba: valor, plazo y cuota. Si se rechaza: un texto fijo | $893.723 a 12 meses, cuota de $86.070 |

Si se rechaza, la variable 4 es: `Comunícate con nosotros para revisar alternativas`.

Envía la plantilla a revisión y espera a que aparezca **Aprobada**. Después activa **WhatsApp** en la configuración de créditos. No cambies el nombre, el idioma ni el orden de las variables, porque deben coincidir con el código. La definición para la API está en `whatsapp-credito-propietario-decision-template.json`; no pegues ese JSON en el campo del cuerpo.
