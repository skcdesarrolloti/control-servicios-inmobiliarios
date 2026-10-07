# Contratos por terminar · renovación y recibo

Versión 3.3.362. El panel consulta contratos `Entregado` o `Por recibir` por mes de fecha fin. No cambia el estado administrativo de tickets existentes.

## Subpestañas

- **Por terminar:** gestión comercial y acceso al popup interno del ticket de retención del ciclo actual.
- **Probabilidad y valor:** informe de consulta de canon, probabilidad y valor ponderado; los cambios se registran desde Por terminar. Registro manual de 0 a 100 %, o sin dato. Valor ponderado mensual = `valor_canon × probabilidad / 100`. Sin probabilidad o canon no se inventa un valor. Al 100 % el servidor impide una nueva retención y omite el recibo automático; los tickets existentes siguen siendo consultables.
- **No salida del inmueble:** reporte con observación obligatoria, filtro de reportados activo inicialmente (puede desmarcarse para registrar nuevos reportes) y días de aviso configurables. Predeterminado: 30, 7 y 0 días antes de fecha fin, a las 09:00 en la zona horaria de la aplicación. Si todos los plazos pasaron, se programa un aviso inmediato. Retirar el reporte exige confirmación y cancela avisos pendientes. Editar solo la probabilidad o el responsable conserva la programación existente.
- **Recibo automático:** fecha de creación 15 días antes de fecha fin, responsable global seleccionado en Configuración → Notificaciones → Recibos automáticos y acceso al caso creado. Si una ejecución se perdió, recupera los contratos aún vigentes dentro de esos 15 días. No crea recibos de contratos ya vencidos, recibidos, desistidos, con renovación al 100 % o no salida reportada. La vista solo lista el contrato configurado cuando su fecha fin corresponde al mes consultado; si no corresponde, muestra un mensaje y el _ID configurado. Solo procesa el contrato `_ID` configurado (inicialmente 525); requiere activación explícita y un funcionario activo con correo válido. La recomendación del inmueble sigue disponible para retención, pero no cambia el responsable global del cron.

La recomendación prioriza `id_funcionario` del inmueble, consultado por `id_inmueble_data` o por código inequívoco; luego usa relaciones del contrato. Muestra propietario y funcionario del inmueble. Retención exige funcionario activo consultor de arriendo o cargo 13; recibo admite responsable activo. No se asigna por una coincidencia de nombre ni se inventa un responsable.

## Notificaciones

Configurar **Notificaciones internas → Actividades contractuales → No salida del inmueble** y **Ticket automático de recibo · 15 días**. Retención conserva su evento específico **Ticket comercial de retención de contrato**.

Los recordatorios se encolan con `scheduled_at` y clave de deduplicación en `shared-notifications`. No hay un segundo transportador. Guardar No salida falla con un mensaje explícito si faltan destinatarios o la cola no está disponible. Si cambia la fecha fin por Excel, se cancela el calendario del ciclo anterior y se reinicia su valoración. El procesador también cancela recordatorios de contratos recibidos/inactivos o cuya fecha cambió por otro flujo, antes del transporte compartido.

El recibo automático genera un ticket y una **carta de notificación previa para entrega del inmueble**, basada en `CARTA DESOCUPACION TICKET.docx`. No certifica una entrega ya realizada. Conserva el campo CCT `acta_desocupacion` para compatibilidad. El PDF contiene arrendatario, dirección, número de contrato, fecha prevista de entrega y contactos configurados; no incluye sucursal ni nombres fijos. Se encola un correo con acceso firmado al PDF para el arrendatario, responsable y funcionarios seleccionados en el evento `contrato_recibo_automatico`, deduplicando correos. No se adjunta un archivo físico al correo.

El registro fotográfico del contrato se incluye como enlace. Las imágenes locales de `imagenes`, `imagen` y `registro_fotografico`, así como evidencias recibidas por el flujo, se insertan en el PDF (JPEG/PNG validado, hasta 20 imágenes únicas, 10 MiB y 16 megapíxeles por imagen). Una carpeta externa de Drive se enlaza; no se descarga ni se inserta automáticamente su contenido.

Falta de carta, correo de arrendatario/responsable, destinatarios internos o error de encolado revierte la transacción del cron. El ticket queda pendiente de reintento, sin marcar el ciclo como procesado.

## Instalación y ejecución

El esquema se prepara automáticamente al cargar el listado; también puede prepararse antes de desplegar:

```sh
php bin/migrate-contract-renewal.php
```

Se crean tablas de negocio `wp_scm_contract_renewal`, `wp_scm_contract_renewal_events` y `wp_scm_contract_receipts` (el prefijo depende de la instalación). Conservan valoración por ciclo, observaciones, autor, auditoría y recibos creados. Las tablas CCT existentes no requieren columnas nuevas.

En **Configuración → Notificaciones → Recibos automáticos**:

1. Dejar `Contrato: _ID interno` en **525**. No usar el número contractual 2000.
2. Escoger el funcionario al que se asignan los tickets y, opcionalmente, el coordinador que aparece en la carta.
3. Seleccionar destinatarios internos en **Ticket automático de recibo · 15 días**.
4. Activar **Recibos automáticos** y guardar. La activación inicial está apagada hasta completar estos datos.

El contrato 525 consultado el 05/10/2026 termina el **09/01/2027**. Su fecha de creación prevista es **25/12/2026**, salvo cambios posteriores. Su registro fotográfico actual es una carpeta de Google Drive y se incluirá como enlace.

Para revisar sin crear tablas de negocio, tickets ni encolar mensajes:

```sh
php bin/process-contract-receipts.php --dry-run
```

El resultado indica activación, contrato configurado, candidatos en plazo y razones de exclusión (renovación al 100 %, no salida o ticket existente). No sustituye la comprobación de entrega del worker.

El cron existente de `bin/queue-worker.php` ejecuta el procesador contractual antes de la cola. Si producción usa únicamente el worker global de shared-notifications, añadir una ejecución periódica de negocio antes del transporte:

```sh
php bin/process-contract-receipts.php
```

En Hostinger, hPanel → Sitios web → Administrar → Avanzado → Cron Jobs, crear un cron **PHP** con la ruta absoluta al archivo; todos los campos de frecuencia en `*` (cada minuto). Ejemplo de ruta, sustituir usuario/dominio/carpeta:

```text
/home/u123456789/domains/tudominio.com/public_html/control-servicios-inmobiliarios/bin/process-contract-receipts.php
```

El modo PHP agrega el ejecutable; no pegar una URL ni prefijar `php` en el campo del archivo. Este cron específico solo crea recibos y concilia recordatorios del contrato configurado. Necesita el worker compartido existente para entregar avisos. Si ya se usa `bin/queue-worker.php`, no agregar otro cron de recibos: ese worker mantiene además la revisión existente de servicios públicos críticos, la cancelación de recordatorios obsoletos de todos los contratos y el transporte de la cola de la aplicación; el filtro 525 limita la creación automática de recibos.

Ejecutar al menos diariamente; cada minuto permite crear los tickets oportunamente y cancelar recordatorios obsoletos. Este comando crea tickets reales y encola avisos: no usarlo como prueba inocua. La instalación debe tener permisos para crear las tablas al preparar el esquema. Los errores de asignación salen en JSON con el contrato afectado y en el log del worker existente. Las ejecuciones concurrentes se serializan por contrato y la tabla de recibos tiene clave única por contrato y fecha fin.

## Excel y trazabilidad

Primera hoja, hasta 5.000 filas; rechaza archivos mayores en lugar de recortarlos. Requiere número de contrato e inmueble en cada fila, rechaza fechas imposibles, parejas repetidas y múltiples filas que apunten al mismo registro. Cruza el número contractual con el inmueble; no confunde números de contrato con `_ID` internos. Todas las filas del preview son consultables por páginas de 80.

La firma protege lote ordenado, valores anteriores, actor y vencimiento de 30 minutos. Al aplicar, se bloquean los contratos y se verifican valores previos. Cualquier conflicto revierte el lote completo. Cada fecha modificada se audita con su valor anterior y nuevo. La valoración usa control de revisión para rechazar cambios hechos desde un popup desactualizado. Historial muestra los últimos 30 movimientos.

## Verificación

```sh
php tests/contract-renewal-check.php
php tests/contract-receipt-letter-check.php
node tests/contract-receipt-settings-ui-check.cjs
node tests/contracts-ending-ui-check.cjs
node tests/contract-request-ui-check.cjs
php tests/contract-activities-navigation-check.php
php tests/contract-due-calendar-check.php
php tests/case-contract-lookup-check.php
php tests/cct-author-employee-check.php
```

Las pruebas nuevas usan SQLite en memoria, un adaptador de sintaxis MySQL y una cola transaccional simulada. Cubren fechas, cruces, firma, importación atómica, recomendación, valoración, recordatorios, cancelación, destinatarios internos específicos, autoría y recibos idempotentes. La prueba visual usa Playwright/Edge con solicitudes sintéticas; no envía mensajes ni crea tickets reales. No sustituye la validación del cron y la entrega de correos en el servidor desplegado.

El historial resuelve los nombres de los autores mediante una consulta separada por `id_empleado`; evita comparar directamente columnas con collations distintas entre las tablas CCT y las tablas de auditoría. Las cuatro vistas muestran acciones y resúmenes propios; la importación Excel solo se ofrece en Por terminar.

## Descripciones de retención e historial editable

Las retenciones creadas desde Por terminar, Terminación y No prórroga guardan un resumen HTML con objetivo comercial, caso de origen, contrato, arrendatario, inmueble, dirección y fecha fin. La respuesta contractual completa se conserva en el caso de origen y el PDF enlazado/archivado; no se copia en la descripción comercial. El ticket 10864 se corrigió con este formato conservando su texto anterior en el historial del ticket, sin cambiar estado ni enviar avisos.

Para revisar una descripción antigua generada por este flujo, `php bin/repair-retention-description.php --ticket=ID`. La opción `--apply` realiza la corrección de ese único ticket y guarda la descripción anterior en su historial. Las descripciones personalizadas se rechazan; no hay reparación masiva ni llamadas a transporte.

El historial contractual es la auditoría de cambios de valoración, no salida, fecha fin y tickets creados. Su nueva gestión se configura en **Permisos → Acciones del caso → Editar y anular historial contractual**. Inicialmente corresponde a **Gerencia Administrativa (cargo 11)**; otros cargos requieren autorización explícita en la matriz. Al guardar la matriz, revocar ese permiso también revoca el acceso en el servidor.

**Editar descripción** permite corregir el texto mostrado y exige motivo; mantiene autor, fecha, tipo y datos originales del movimiento. **Anular movimiento** exige motivo y confirmación y lo oculta de la consulta normal. Administradores autorizados pueden marcar **Mostrar anulados y correcciones** para revisar la trazabilidad. No hay eliminación definitiva; cada corrección conserva el contenido anterior, autor de la corrección, fecha y motivo. Un control de revisión evita sobreescribir una modificación concurrente.

Editar/anular historial no reinicia probabilidad, no retira reportes de no salida, no cancela sus avisos y no elimina tickets. Esas acciones se realizan desde sus flujos correspondientes. No se realiza limpieza automática de registros de pruebas.

En **Por terminar**, cada contrato muestra su porcentaje actual (o Sin registrar), el estado de no salida y botones independientes para agregar/editar probabilidad y reportar/editar no salida. Cada formulario conserva los datos del otro proceso. No salida y Recibo automático siguen siendo listados.

Los nuevos tickets de retención de los tres flujos solicitan conservar al cliente en SKC SuCasa Inmobiliaria y ofrecer inmuebles similares según sus necesidades y presupuesto. La descripción incluye tipo, destinación, canon mensual del contrato, habitaciones, baños, área construida y barrio. Se consulta la ficha del inmueble asociada al contrato; los datos faltantes se muestran como Sin registrar, sin sustituir el canon vigente por el precio publicado del inmueble.


## Vistas Tailwind (3.3.362)

Las cuatro subpestañas y la previsualización Excel siguen los HTML de referencia: Poppins, azul #061D49 / #1E3C76, amarillo #F8CF4A, tarjetas con borde tenue, listas blancas, etiquetas de estado y tablas. El diseño se adapta al ancho del panel; en móvil las tablas desplazan su contenido dentro del contenedor y los botones se distribuyen en dos columnas. Las pestañas admiten flechas, Inicio y Fin.

Configurar / Habilitar abre el popup existente de Notificaciones internas cuando el usuario dispone de ese permiso; la activación se guarda desde esa configuración. Crear reporte manual muestra los contratos del periodo para escoger el reporte, sin modificar datos automáticamente. Ver histórico de recibos filtra movimientos de recibo del historial consultado. El popup Excel conserva paginación, token de confirmación y aplicación solo de cambios válidos; cerrar o No aplicar no modifica fechas.

Compilación: `npx --yes tailwindcss@3.4.17 -c tailwind.config.js -i resources/css/tailwind-admin.css -o public/assets/css/tailwind-admin.css --minify`. No se utiliza Tailwind CDN en producción.
