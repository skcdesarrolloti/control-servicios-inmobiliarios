# Crear casos desde el panel

El botón **Nuevo Caso** en Métricas abre un popup interno con estilos de Tailwind. Permite buscar el contrato por número, inmueble SIMI, propietario o arrendatario (nombre, identificador o documento). Devuelve hasta 30 resultados por búsqueda y guarda el `_ID` del contrato seleccionado, conservando su número y datos del inmueble.

El formulario pide título, descripción, tema, departamento, funcionario responsable y si hay adjuntos. Los únicos departamentos disponibles son `Servicio al propietario`, `Servicio al arrendatario` y `Servicio a la copropiedad`. El servidor valida las mismas opciones que muestra el popup.

Los temas permitidos son: `Reparaciones necesarias`, `Reparaciones locativas`, `Mejoras utiles`, `Reparaciones voluntarias`, `Contable y tributaria`, `Certificaciones tributarias`, `Procesos juridicos`, `Solicitud contractual`, `Solicitud de servicios publicos`, `Otros servicios`, `Reparaciones antes de la entrega`, `Reparaciones antes del recibo` y `Servicio publico critico`.

**Asignar a** muestra funcionarios con `activo = Si` de los cargos habilitados en **Configuración → Permisos → Funcionarios visibles por cargo** (`dashboard_funcionario_cargo_ids`). El responsable debe tener correo válido; el servidor también exige que pertenezca a esos cargos al guardar. El creador se resuelve entre todos los funcionarios activos por su `id_empleado`, independientemente de que su cargo aparezca en la lista de asignación.

El panel también permite el tema `Servicio publico critico`, con el mismo valor de los casos de revisión de servicios públicos. Sugiere el departamento `Servicio al arrendatario`, que puede cambiarse antes de guardar.

El caso sigue la persistencia de `PublicTicketsService` utilizada por el bot, con origen `Panel administrativo` y creador `Funcionario`. El autor del CCT y los historiales usan el `id_empleado` real del creador. La asignación usa el `id_empleado` del responsable. Solo se inicializan los estados del caso nuevo según la regla existente; no se alteran otros casos.

Los pasos de descripción, asignación y adjuntos aparecen únicamente después de seleccionar un contrato. Si cambia la búsqueda, se ocultan y deshabilitan hasta seleccionar nuevamente; el botón de crear sigue la misma condición.

## Adjuntos

Las capturas se pueden pegar con **Ctrl+V** dentro del popup después de seleccionar el contrato, o con **Pegar captura** cuando el navegador permita leer el portapapeles. Se muestran miniaturas con botón **Quitar**, se agregan junto a los archivos seleccionados y comparten sus límites y validaciones. Pegar una imagen activa automáticamente la opción de adjuntos. Elegir **No** limpia las capturas y los archivos seleccionados.

- Hasta 10 archivos, máximo 10 MB por archivo (o el límite menor configurado) y 25 MB en total.
- Imágenes JPG, PNG y WebP de hasta 16 megapíxeles, validadas antes de guardar y comprimidas por `StoredFileService` cuando GD está disponible.
- Documentos PDF validados por extensión y MIME real.
- URLs firmadas de almacenamiento, imágenes en `imagenes` y documentos serializados en `archivos`, compatibles con las vistas existentes.
- Un archivo rechazado impide crear el caso; los archivos guardados se eliminan si falla la transacción.

## Correos y consulta

El responsable recibe siempre el correo de asignación y, al activar las plantillas, WhatsApp oficial. Las copias se configuran en **Notificaciones internas → Gestión del caso → Crear caso desde el panel** (`internal_admin_notifications.nuevo_caso_panel`) y reciben ambos canales cuando tienen datos válidos. Se excluyen funcionarios inactivos y se deduplican los correos y celulares por caso. No hay destinatarios fijos ni envíos directos.

En el paso **Notifica a los interesados** se pueden marcar propietario, arrendatario y/o copropiedad. Ninguno viene marcado. Sus datos se resuelven exclusivamente en el servidor: primero desde el contrato, y los campos vacíos desde la ficha relacionada por `id_propietario`, `id_arrendatario` o `id_copropiedad`. Para copropiedades se admite `contacto` como teléfono de la ficha. No se aceptan direcciones ni teléfonos arbitrarios desde el navegador. Se exige correo y celular válidos de cada interesado seleccionado y, con WhatsApp activo, del responsable.

El correo externo incluye título, tema, contrato, responsable y descripción. WhatsApp incluye los seis datos indicados en las plantillas de abajo. El botón interno exige iniciar sesión; los avisos externos no incluyen enlaces de acceso al panel ni adjuntos internos. Los destinatarios elegidos quedan registrados en los metadatos de la cola. La pantalla final muestra los contadores de correo y WhatsApp **encolados**; la entrega se consulta en la cola y sus intentos.

Los mensajes se encolan en `shared-notifications`, con `source_module = nuevo_caso_panel`, deduplicación por caso y destino y metadatos del contrato, creador y responsable. WhatsApp usa `whatsapp_official`. La inicialización de la cola ocurre antes de la transacción para evitar DDL durante el guardado. Caso, historiales, correos y WhatsApp se confirman juntos; una falla al encolar revierte todo.

El enlace `?scm_case=ID` abre el detalle nativo dentro del panel. Exige sesión y permite consultar al responsable, a las copias activas configuradas para casos del panel o a usuarios con permiso de Métricas. El enlace se conserva al pasar por el login. La creación, búsqueda y opciones también exigen sesión, CSRF y permiso de Métricas.

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

El parámetro del botón es el número interno del caso; ejemplo de URL completa: `https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/?scm_case=10945`. El destinatario debe iniciar sesión en el panel.

### Propietario, arrendatario y copropiedad: `scm_caso_registrado_v1`

Texto del cuerpo, **sin botones**:

```text
Hola {{1}}.
Te informamos que se registró el caso #{{2}}.
Título: {{3}}.
Tema: {{4}}.
Contrato: {{5}}.
Responsable asignado: {{6}}.
Este aviso corresponde a la gestión de tu contrato con SKC SuCasa Inmobiliaria.
```

Las dos plantillas reciben las mismas seis variables y en este orden:

| Variable | Contenido | Ejemplo para Meta |
| --- | --- | --- |
| `{{1}}` | Nombre del destinatario | María Pérez |
| `{{2}}` | Número interno del caso | 10945 |
| `{{3}}` | Título | Revisar fuga en cocina |
| `{{4}}` | Tema | Reparaciones necesarias |
| `{{5}}` | Número de contrato | 801 |
| `{{6}}` | Consultor asignado | Carlos López |

Tras aprobar ambas, abre **Configuración → Notificaciones internas → Crear casos · WhatsApp oficial**, confirma los nombres exactos e idioma, marca **Activar WhatsApp con plantillas aprobadas** y guarda. La configuración se almacena en `panel_case_notifications`; por defecto WhatsApp está desactivado y el popup indica que solo encolará correo hasta activarlo. No se consulta ni cambia la cuenta de Meta desde el panel. El proveedor `whatsapp_official` y el worker compartido deben estar activos. Esta activación se aplica a casos nuevos, no reenvía los ya creados.

## Verificación

```powershell
php tests/panel-case-creation-check.php
php tests/panel-case-login-check.php
node tests/panel-case-attachments-check.cjs
node tests/panel-case-modal-check.cjs
node tests/panel-case-settings-check.cjs
```

La prueba `tests/panel-case-settings-check.cjs` verifica la sección real de configuración, los valores iniciales, la validación y el envío de nombres/idioma/activación. La prueba del servicio usa tablas temporales y proveedores inertes para comprobar ambos canales, las variables, los destinatarios, la deduplicación y la reversión de fallos sin enviar mensajes reales.

La prueba de base de datos usa tablas temporales de la conexión y proveedores de correo y WhatsApp simulados. La prueba de adjuntos levanta un servidor PHP solo en localhost y limpia sus archivos. La prueba de navegador requiere Playwright y Edge; genera capturas de escritorio y móvil en `output/panel-case`.

Para regenerar los estilos: `tailwindcss -i resources/css/tailwind-admin.css -o public/assets/css/tailwind-admin.css --minify`.
