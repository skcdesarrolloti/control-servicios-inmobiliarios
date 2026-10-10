# Crear casos desde el panel

El botón **Nuevo Caso** en Métricas abre un popup interno con estilos de Tailwind. Permite buscar el contrato por número, inmueble SIMI, propietario o arrendatario (nombre, identificador o documento). Devuelve hasta 30 resultados por búsqueda y guarda el `_ID` del contrato seleccionado, conservando su número y datos del inmueble.

El formulario pide título, descripción, tema, departamento, funcionario responsable y si hay adjuntos. Los únicos departamentos disponibles son `Servicio al propietario`, `Servicio al arrendatario` y `Servicio a la copropiedad`. El servidor valida las mismas opciones que muestra el popup.

Los temas permitidos son: `Reparaciones necesarias`, `Reparaciones locativas`, `Mejoras utiles`, `Reparaciones voluntarias`, `Contable y tributaria`, `Certificaciones tributarias`, `Procesos juridicos`, `Solicitud contractual`, `Solicitud de servicios publicos`, `Otros servicios`, `Reparaciones antes de la entrega`, `Reparaciones antes del recibo` y `Servicio publico critico`.

**Asignar a** muestra funcionarios con `activo = Si` de los cargos habilitados en **Configuración → Permisos → Funcionarios visibles por cargo** (`dashboard_funcionario_cargo_ids`). El responsable debe tener correo válido; el servidor también exige que pertenezca a esos cargos al guardar. El creador se resuelve entre todos los funcionarios activos por su `id_empleado`, independientemente de que su cargo aparezca en la lista de asignación.

El panel también permite el tema `Servicio publico critico`, con el mismo valor de los casos de revisión de servicios públicos. Sugiere el departamento `Servicio al arrendatario`, que puede cambiarse antes de guardar.

El caso sigue la persistencia de `PublicTicketsService` utilizada por el bot, con origen `Panel administrativo` y creador `Funcionario`. El autor del CCT y los historiales usan el `id_empleado` real del creador. La asignación usa el `id_empleado` del responsable. Solo se inicializan los estados del caso nuevo según la regla existente; no se alteran otros casos.

Después de seleccionar un contrato se pregunta **¿Quieres preparar la información del caso con el asistente?**. **Sí, usar el asistente** muestra sus herramientas y el formulario; **No, completar manualmente** muestra el formulario habitual. Antes de responder, los demás pasos permanecen ocultos y deshabilitados. Si cambia la búsqueda, se requiere seleccionar nuevamente el contrato y responder la pregunta.

## Asistente después de seleccionar contrato

El apartado opcional **Asistente para preparar el caso** aparece encima de los datos cuando se elige usar el asistente. Admite texto pegado de correos o WhatsApp y hasta 4 capturas JPG/PNG/WebP (5 MB por imagen, 12 MB en total y 16 megapíxeles). Se pueden subir con **Adjuntar captura** o pegar con Ctrl+V dentro de esa sección o con **Pegar (Ctrl+V)**.

**Analizar solicitud** genera una propuesta de título, descripción, tema y departamento. **Completar formulario** la aplica, conservando el responsable, los destinatarios y la selección de adjuntos. **Deshacer autocompletado** restaura los campos anteriores. El funcionario revisa y guarda el caso con el flujo habitual. Los temas y departamentos generados se validan contra las opciones reales del formulario; los valores desconocidos requieren selección manual.

El texto y las imágenes se envían a MiniMax solo al pulsar Analizar. Las capturas del asistente son fuentes privadas temporales: se validan y, si GD está disponible, se convierten a JPEG y se reducen a 2400 px de lado mayor antes del envío. No se guardan en el servidor ni se agregan a los adjuntos públicos del caso; sí se conservan temporalmente en el borrador local descrito abajo. Para conservar una evidencia del caso, agrégala expresamente en **Adjunta las evidencias**. El texto original y las capturas tampoco se incluyen al guardar el caso. Solo se envía al modelo contexto mínimo del contrato (número, SIMI y dirección), no su ficha completa ni contactos. Cambiar el contrato descarta las fuentes, propuestas y solicitudes pendientes del asistente.

La integración usa la API compatible con OpenAI de MiniMax, sin necesitar un servidor MCP. Configura en el `.env` privado **del servidor que ejecuta PHP**:

```dotenv
PANEL_CASE_AI_ENABLED=true
MINIMAX_API_KEY="CLAVE_PRIVADA_DE_TU_CUENTA"
MINIMAX_API_HOST=https://api.minimax.io
MINIMAX_MODEL=MiniMax-M3
```

La clave nunca se devuelve al navegador ni se escribe en Git. Usa la clave y región de la misma cuenta; para cuentas de China continental el host permitido es `https://api.minimaxi.com`. También se admite `MiniMax-M3.1-Flash-Preview` si tu cuenta lo tiene habilitado. Los modelos M2.x no se admiten en este flujo de capturas. PHP debe tener cURL, Fileinfo y mbstring; GD permite comprimir las capturas. Si falta configuración o acceso al modelo, el formulario sigue funcionando manualmente y muestra el motivo. El servidor permite 6 análisis por funcionario cada 5 minutos y 60 por hora entre todos los usuarios, con un máximo de 45 segundos por llamada y sin reintentos automáticos de consumo.

MiniMax documenta imágenes y texto en [la API multimodal compatible con OpenAI](https://platform.minimax.io/docs/api-reference/text-openai-api). Consulta [Token Plan](https://platform.minimax.io/subscribe/token-plan) para confirmar los modelos y la cuota de tu cuenta; MiniMax orienta este plan al uso individual interactivo y recomienda pago por uso para producción.

## Adjuntos

La barra de adjuntos usa botones **Adjuntar imagen**, **Pegar (Ctrl+V)** y **Adjuntar documento**, con los controles nativos de archivos ocultos. Las capturas se pueden pegar con **Ctrl+V** dentro del popup después de seleccionar el contrato y responder cómo completar el caso, o con el botón Pegar cuando el navegador permita leer el portapapeles. Se muestran miniaturas con botón **Quitar**, se agregan junto a los archivos seleccionados y comparten sus límites y validaciones. Pegar una imagen activa automáticamente la opción de adjuntos. Elegir **No** limpia las capturas y los archivos seleccionados.

- Hasta 10 archivos, máximo 10 MB por archivo (o el límite menor configurado) y 25 MB en total.
- Imágenes JPG, PNG y WebP de hasta 16 megapíxeles, validadas antes de guardar y comprimidas por `StoredFileService` cuando GD está disponible.
- Documentos PDF validados por extensión y MIME real.
- URLs firmadas de almacenamiento, imágenes en `imagenes` y documentos serializados en `archivos`, compatibles con las vistas existentes.
- Un archivo rechazado impide crear el caso; los archivos guardados se eliminan si falla la transacción.

## Protección y recuperación del borrador

El popup no se cierra al pulsar fuera ni con Escape. Los botones explícitos de cerrar y cancelar guardan primero el borrador; si el navegador rechaza el almacenamiento, muestran el error y mantienen el popup abierto. Esto también evita cierres al cancelar el selector de archivos.

El autoguardado usa IndexedDB en este navegador, con una clave opaca por funcionario y caducidad de 24 horas. Conserva contrato, campos, destinatarios, elección del asistente, texto fuente, capturas y documentos. Al reabrir o recargar recupera el borrador y vuelve a consultar el contrato antes de habilitar los pasos siguientes. No se sincroniza con otros equipos y depende de que el navegador permita almacenar datos; cerrar el sistema abruptamente puede perder el último cambio aún pendiente.

**Descartar borrador** pide confirmación dentro del popup. Crear correctamente el caso elimina el borrador. La recuperación conserva el identificador de solicitud para que un reintento después de perder la respuesta no duplique el caso ni sus avisos. El CSRF se obtiene nuevamente del servidor.

## Correos y consulta

El responsable recibe siempre el correo de asignación y, al activar las plantillas, WhatsApp oficial. Las copias se configuran en **Notificaciones internas → Gestión del caso → Crear caso desde el panel** (`internal_admin_notifications.nuevo_caso_panel`) y reciben ambos canales cuando tienen datos válidos. Se excluyen funcionarios inactivos y se deduplican los correos y celulares por caso. No hay destinatarios fijos ni envíos directos.

En el paso **Notifica a los interesados** se pueden marcar propietario, arrendatario y/o copropiedad. Ninguno viene marcado. Sus datos se resuelven exclusivamente en el servidor: primero desde el contrato, y los campos vacíos desde la ficha relacionada por `id_propietario`, `id_arrendatario` o `id_copropiedad`. Para copropiedades se admite `contacto` como teléfono de la ficha. No se aceptan direcciones ni teléfonos arbitrarios desde el navegador. Se exige correo y celular válidos de cada interesado seleccionado y, con WhatsApp activo, del responsable.

El correo externo incluye título, tema, contrato, responsable y descripción. WhatsApp incluye los seis datos indicados en las plantillas de abajo. Ambos canales incluyen **Ver caso**, con enlace firmado válido por 30 días. Los destinatarios elegidos quedan registrados en los metadatos de la cola. La pantalla final muestra los contadores de correo y WhatsApp **encolados**; la entrega se consulta en la cola y sus intentos.

Los mensajes se encolan en `shared-notifications`, con `source_module = nuevo_caso_panel`, deduplicación por caso y destino y metadatos del contrato, creador y responsable. WhatsApp usa `whatsapp_official`. La inicialización de la cola ocurre antes de la transacción para evitar DDL durante el guardado. Caso, historiales, correos y WhatsApp se confirman juntos; una falla al encolar revierte todo.

Al crear el caso, el mismo popup muestra la confirmación, los contadores y el **Detalle de los avisos de WhatsApp** por destinatario. El popup del caso se abre únicamente al pulsar **Aceptar**, después de cerrar la confirmación, y no repite el aviso dentro de la ficha. Si dos o más destinatarios comparten celular se envía un solo mensaje a ese número y se explica en el resumen. Las copias internas sin celular válido también se indican. El contador representa destinos únicos encolados, no personas ni confirmaciones de entrega. No se cambia el estado administrativo al abrir el caso.

Los enlaces nuevos usan `?scm_case=ID.VENCIMIENTO.DESTINATARIO.FIRMA`, con HMAC SHA-256 específico que también protege el destinatario (`funcionario`, `propietario`, `arrendatario` o `copropiedad`). Con sesión activa se vuelve a `?scm_case=ID` y se abre el popup nativo, conservando sus permisos: responsable, copias activas configuradas o usuarios con permiso de Métricas. Sin sesión (también si expiró), el enlace firmado abre la vista pública de solo lectura. Cambiar el ID, destinatario o firma impide el acceso; el enlace vencido no permite acceso público. Los enlaces firmados anteriores de tres partes siguen funcionando como consulta pública genérica, sin acceso de funcionarios. Un enlace anterior que solo tenga el número del caso sigue enviando al login y conserva el caso de destino, sin permitir consulta pública por número.

La vista adapta el subtítulo al destinatario y solo muestra **Acceso funcionarios** en enlaces firmados para funcionarios. Propietarios, arrendatarios y copropiedades no reciben ese botón. Usa Poppins y el logo y favicon configurados en la configuración central del sistema mediante `system_image()`.

La vista pública muestra título, estado, tema, contrato, SIMI, dirección, fecha, descripción y las evidencias originales del caso. Usa el mismo lenguaje visual Tailwind del popup, permite previsualizar imágenes y PDF dentro de la página y tiene impresión A4 compacta. El responsable aparece en el cierre. No publica sucursal, datos de contacto, estado administrativo, historial privado ni acciones internas. Usa una lista explícita de campos y adjuntos de almacenamiento firmado o medios corporativos públicos; nunca reutiliza el HTML administrativo. No se modifica el caso al consultar. `public/caso.php` también permite abrir directamente la misma referencia firmada. La creación, búsqueda y opciones del panel continúan exigiendo sesión, CSRF y permiso de Métricas.

Cada formulario genera un identificador de solicitud. El servidor guarda el resultado bajo bloqueo en `storage/data/panel-case-receipts`; reintentos de la misma solicitud recuperan el caso creado sin repetir correos. Al guardar se invalidan y actualizan las métricas.

## Plantillas de WhatsApp

En WhatsApp Manager crea estas dos plantillas para la misma cuenta de WhatsApp Business usada por el worker. Categoría propuesta: **Utilidad**; idioma: **Español (Colombia)**, código `es_CO`. Usa variables **numéricas/posicionales**, no variables con nombre. No agregues encabezado, archivos, pie de página ni respuestas rápidas. La categoría final y aprobación las decide Meta.

### Consultor y copias internas: `scm_caso_asignado_v1`

Texto del cuerpo:

```text
Hola {{1}}.
Se registró el caso #{{2}} para gestión.
Título: {{3}}.
Tema: {{4}}.
Contrato: {{5}}.
Responsable asignado: {{6}}.
Consulta los detalles en el panel de SKC SuCasa Inmobiliaria.
```

Agrega exactamente un botón **Visitar sitio web**, URL **dinámica**, texto **Ver caso**, con esta URL (usa la base real del despliegue si cambia):

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case={{1}}
```

El parámetro del botón es ahora el token completo `ID.VENCIMIENTO.DESTINATARIO.FIRMA`, **no solamente el número del caso**. El código lo genera y sustituye automáticamente en `{{1}}`, sin cambiar la URL base ni la estructura de las plantillas ya configuradas. Para el ejemplo que pide Meta, copia un enlace firmado de un correo de caso nuevo; los correos ya incluyen ese enlace aunque WhatsApp todavía no esté activo. Con sesión abre el panel; sin sesión, la vista pública correspondiente. No construyas una firma manual ni uses un enlace con solo el número como ejemplo de acceso público.

### Propietario, arrendatario y copropiedad: `scm_caso_registrado_v2`

Texto del cuerpo:

```text
Hola {{1}}.
Te informamos que se registró el caso #{{2}}.
Título: {{3}}.
Tema: {{4}}.
Contrato: {{5}}.
Responsable asignado: {{6}}.
Este aviso corresponde a la gestión de tu contrato con SKC SuCasa Inmobiliaria.
```

Agrega el mismo botón **Ver caso**, URL dinámica `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case={{1}}`. La plantilla externa anterior `scm_caso_registrado_v1` no tenía botón: crea esta versión nueva y cambia el nombre en Configuración, o actualiza la anterior con el botón y espera su aprobación antes de usarla. El código ya envía un componente de botón en ambas plantillas; no es compatible con una plantilla externa que siga sin botón.

Las dos plantillas reciben las mismas seis variables y en este orden:

| Variable | Contenido | Ejemplo para Meta |
| --- | --- | --- |
| `{{1}}` | Nombre del destinatario | María Pérez |
| `{{2}}` | Número interno del caso | 10945 |
| `{{3}}` | Título | Revisar fuga en cocina |
| `{{4}}` | Tema | Reparaciones necesarias |
| `{{5}}` | Número de contrato | 801 |
| `{{6}}` | Consultor asignado | Carlos López |

La activación, los nombres de plantillas y el idioma se administran internamente en la configuración `panel_case_notifications`, leída por `PanelCaseNotifications::config()`. Los nombres predeterminados son `scm_caso_asignado_v1` y `scm_caso_registrado_v2`, con idioma `es_CO`. El apartado de edición de plantillas se retiró de **Notificaciones internas administrativas**; los valores previamente guardados, incluida la activación, se conservan. Guardar los destinatarios internos no cambia esta configuración. Si no existe configuración previa, WhatsApp sigue desactivado hasta que el administrador técnico establezca `enabled = true` después de aprobar ambas plantillas. En Notificaciones internas permanece el evento **Crear caso desde el panel** para configurar las copias. No se consulta ni cambia la cuenta de Meta desde el panel. El proveedor `whatsapp_official` y el worker compartido deben estar activos. Esta activación se aplica a casos nuevos, no reenvía los ya creados.

## Verificación

```powershell
php tests/panel-case-creation-check.php
php tests/panel-case-login-check.php
node tests/panel-case-attachments-check.cjs
node tests/panel-case-modal-check.cjs
node tests/panel-case-draft-ui-check.cjs
node tests/panel-case-settings-check.cjs
php tests/panel-case-ai-check.php
node tests/panel-case-ai-uploads-check.cjs
node tests/panel-case-ai-ui-check.cjs
php tests/public-case-check.php
node tests/public-case-ui-check.cjs
```

La prueba `tests/panel-case-settings-check.cjs` verifica el modal real, la ausencia de controles de plantillas y el guardado de copias y recibos sin enviar cambios a la configuración interna de WhatsApp. La prueba del servicio usa tablas temporales y proveedores inertes para comprobar ambos canales, las variables, los destinatarios, la deduplicación y la reversión de fallos sin enviar mensajes reales.

Las pruebas de IA verifican el contrato como requisito, fuentes privadas, límites y MIME real, compresión, formato de respuesta, listas de opciones, revisión/aplicación/deshacer, fallos de cuota, descarte al cambiar de contrato, móvil, confirmación con el detalle de avisos y apertura del caso solo después de aceptar. Usan respuestas simuladas y cargas multipart locales; no consumen cuota de MiniMax ni envían mensajes reales.

`panel-case-draft-ui-check.cjs` comprueba la pregunta del asistente, controles de archivos, protección frente a clic fuera/Escape/cancelar selector, recuperación de archivos al reabrir y recargar, separación por funcionario, limpieza tras crear y confirmación al descartar.

`public-case-check.php` verifica las firmas, destinatarios, compatibilidad anterior, vencimiento y lista pública de campos/adjuntos. `public-case-ui-check.cjs` levanta un servidor en localhost con registros temporales y secreto de prueba: recorre las cuatro vistas por destinatario, logo/Poppins, intentos de cambiar el destinatario, retorno al panel con sesión, enlaces antiguos al login, rechazos sin firma, sesión vencida, previsualizaciones, móvil e impresión de un caso representativo en una página A4. Genera capturas y PDF de prueba en `output/public-case`.

La prueba de base de datos usa tablas temporales de la conexión y proveedores de correo y WhatsApp simulados. La prueba de adjuntos levanta un servidor PHP solo en localhost y limpia sus archivos. La prueba de navegador requiere Playwright y Edge; genera capturas de escritorio y móvil en `output/panel-case`.

Para regenerar los estilos: `tailwindcss -i resources/css/tailwind-admin.css -o public/assets/css/tailwind-admin.css --minify`.
