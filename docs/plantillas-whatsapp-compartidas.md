# Plantillas de WhatsApp compartidas con el panel comercial

`pqr_trasladada_funcionario_v1`, `scm_cita_funcionario_v1` y `scm_cita_solicitante_v1` las usan este panel y `control-servicios-comerciales` (dashboard-comercial). Se **editan** en Meta (texto y botón) con los mismos nombres; cada una conserva su cantidad de variables. La misma especificación está en `control-servicios-comerciales/docs/plantillas-whatsapp-tareas-comerciales.md` (secciones 6 a 8): si cambia una, cambiar las dos.

Categoría **Utilidad**, idioma **Spanish (COL)** (`es_CO`), tipo de variable **Número**, pie de página `SKC SuCasa Inmobiliaria` y botón **Ir al sitio web** con URL **Dinámica**.

El botón tenía fija la página de tickets anterior (`https://sucasainmobiliaria.com.co/ticket/?id_ticket={{1}}`). Ahora la **URL del sitio web** es solo `https://sucasainmobiliaria.com.co/` y cada panel completa el resto con `SharedButtonUrl` (igual en los dos repositorios): aquí `control-servicios-inmobiliarios/public/?scm_case=...` (enlace del funcionario con inicio de sesión, o consulta pública del cliente) y en el comercial `dashboard-comercial/public/...`. La referencia va en una sola variable con su nombre: `Caso #10245` aquí, `Tarea #11459` en el comercial.

Orden del cambio: publicar el código de los dos paneles y editar las tres plantillas en Meta el mismo día. Mientras uno de los dos va adelantado, el botón de esa plantilla abre una dirección incompleta (el texto llega bien).

Verificación: `php tests/shared-whatsapp-templates-check.php`.

## 1. `pqr_trasladada_funcionario_v1` (editar)

Para el nuevo responsable cuando se traslada un caso o una solicitud web. Código: `WorkflowCommandsConcern::notifyTrasladoCaso` y `HandlesPublicPqrActions::notifyPublicPqrTransferToEmployee`.

```text
Hola *{{1}}*.

Se trasladó a tu gestión: *{{2}}*.

*Tipo:* {{3}}.
*Responsable anterior:* {{4}}.

Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Royner Guardo · `{{2}}` Caso #10245 · `{{3}}` Reparaciones necesarias · `{{4}}` Daniela Hernandez Buelvas

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10245.1893456000.funcionario.1047.ejemplo`.

## 2. `scm_cita_funcionario_v1` (editar)

Para el funcionario cuando se le agenda una cita. Código: `HandlesTicketWorkflowActions` (avisos de cita del calendario).

```text
Hola *{{1}}*, se te agendó una cita de *{{2}}*.

*Referencia:* {{3}}.
*Fecha:* {{4}}.
*Hora:* {{5}}.
*Dirección:* {{6}}.

Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Alvaro Florez De Oro · `{{2}}` Revisión preventiva · `{{3}}` Caso #10245 · `{{4}}` 25/11/2026 · `{{5}}` 10:00 a. m. a 11:00 a. m. · `{{6}}` Urb Simón Bolívar Mz 20 lote 7

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10245.1893456000.funcionario.1047.ejemplo`.

## 3. `scm_cita_solicitante_v1` (editar)

Para el cliente (solicitante, propietario o arrendatario) cuando se le agenda una cita; el botón abre la consulta pública del caso con su audiencia. Código: `HandlesTicketWorkflowActions`.

```text
Hola *{{1}}*, se te agendó una cita de *{{2}}*.

*Referencia:* {{3}}.
*Fecha:* {{4}}.
*Hora:* {{5}}.
*Lugar:* {{6}}.
*Agendada por:* {{7}}.

Si no puedes asistir, comunícate con quien agendó la cita. Consulta los detalles en el botón.

*Construimos confianza, protegemos su patrimonio.*
```

Ejemplos: `{{1}}` Roy Guardo · `{{2}}` Revisión preventiva · `{{3}}` Caso #10245 · `{{4}}` 25/11/2026 · `{{5}}` 10:00 a. m. a 11:00 a. m. · `{{6}}` Urb Simón Bolívar Mz 20 lote 7 · `{{7}}` Alvaro Florez De Oro - 3001234567

Botón `Ver detalle`. URL del sitio web `https://sucasainmobiliaria.com.co/`. URL de muestra `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10245.1893456000.publico.ejemplo`.
