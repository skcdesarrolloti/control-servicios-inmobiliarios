# Crear casos desde el panel

El botón **Nuevo Caso** en Métricas abre un popup interno con estilos de Tailwind. Permite buscar el contrato por número, inmueble SIMI, propietario o arrendatario (nombre, identificador o documento). Devuelve hasta 30 resultados por búsqueda y guarda el `_ID` del contrato seleccionado, conservando su número y datos del inmueble.

El formulario pide título, descripción, tema, departamento, funcionario responsable y si hay adjuntos. Los temas y departamentos usan las definiciones del servicio compartido con Guardian; el tema sugiere el departamento, que puede cambiarse. Los responsables son funcionarios con `activo = Si`, sin filtro de cargos. El responsable debe tener correo válido.

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

El responsable recibe siempre el aviso de asignación. Las copias se configuran en **Notificaciones internas → Gestión del caso → Crear caso desde el panel** (`internal_admin_notifications.nuevo_caso_panel`). Se excluyen funcionarios inactivos y se deduplican los correos. No hay destinatarios fijos ni envíos directos.

Los correos se encolan en `shared-notifications`, con `source_module = nuevo_caso_panel`, deduplicación por caso y correo y metadatos del contrato, creador y responsable. La inicialización de la cola ocurre antes de la transacción para evitar DDL durante el guardado. Caso, historiales y correos se confirman juntos; una falla al encolar revierte todo.

El enlace `?scm_case=ID` abre el detalle nativo dentro del panel. Exige sesión y permite consultar al responsable, a las copias activas configuradas para casos del panel o a usuarios con permiso de Métricas. El enlace se conserva al pasar por el login. La creación, búsqueda y opciones también exigen sesión, CSRF y permiso de Métricas.

Cada formulario genera un identificador de solicitud. El servidor guarda el resultado bajo bloqueo en `storage/data/panel-case-receipts`; reintentos de la misma solicitud recuperan el caso creado sin repetir correos. Al guardar se invalidan y actualizan las métricas.

## Verificación

```powershell
php tests/panel-case-creation-check.php
php tests/panel-case-login-check.php
node tests/panel-case-attachments-check.cjs
node tests/panel-case-modal-check.cjs
```

La prueba de base de datos usa tablas temporales de la conexión y un proveedor de correo simulado. La prueba de adjuntos levanta un servidor PHP solo en localhost y limpia sus archivos. La prueba de navegador requiere Playwright y Edge; genera capturas de escritorio y móvil en `output/panel-case`.

Para regenerar los estilos: `tailwindcss -i resources/css/tailwind-admin.css -o public/assets/css/tailwind-admin.css --minify`.
