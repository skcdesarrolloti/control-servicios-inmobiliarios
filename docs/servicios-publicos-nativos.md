# Servicios públicos nativos

El módulo reúne Pendientes, Plantillas de actas y Revisiones realizadas. Usa las revisiones CCT existentes y conserva sus PDFs históricos. La página de WordPress `revision-de-servicios-publicos` y su listing Elementor 44933 se revisaron como referencia; los nuevos correos e historiales apuntan a `public/revision-servicios-publicos.php`.

## Preparación del servidor

```powershell
php bin/migrate-public-services.php
php bin/check-public-services-review.php
```

La migración agrega la tabla `scm_public_services_reviews` con el prefijo configurado y la columna nullable `proxima_revision_servicios` en contratos para la reprogramación administrativa. Conserva los valores existentes, revisiones y actas; la columna nueva inicia vacía. Contratos, revisiones, historial y la tabla nueva requieren InnoDB. Los PDFs usan el mismo renderizador Chromium/Gotenberg de las actas de satisfacción y las fuentes locales. Se conserva la identidad del membrete en el encabezado y cierre del documento.

## Programación y duplicados

La siguiente revisión se calcula a tres meses calendario de la fecha real de revisión en Colombia. Se conserva el día cuando existe y se ajusta al último día del mes cuando es necesario. El mes anterior configurado solo interviene al no existir ninguna última revisión.

El formulario incluye un token firmado ligado al contrato, funcionario de sesión, fecha y contador de la última revisión. El servidor bloquea la fila del contrato antes de validar y guardar. Un reintento del mismo token devuelve la revisión original sin generar documentos o notificaciones nuevos; otro formulario basado en una versión anterior se rechaza. El token dura 24 horas. No basta con desactivar el botón del navegador.

Marcar Contrato recibido guarda estado Recibido, tipo Ex, fecha y funcionario real, junto con el historial del estado, tipo y fecha anteriores. Si falla el historial se revierte el cambio. Un reintento conserva la fecha registrada. No modifica estados administrativos de tickets.

## Plantillas y documentos

Las cuatro plantillas son Al día, Mora 30 días, Mora 60 días y Crítico/superior a 90 días. La última incorpora el contenido suministrado en `CARTA MORA A 90 DIAS (1).docx`, con fecha, destinatario, inmueble, servicio, referencia y valor dinámicos. El representante legal se resuelve desde la sucursal; el nombre corporativo visible es SKC SuCasa Inmobiliaria.

El editor permite cambiar título y contenido, insertar variables y ver una muestra antes de guardar. Los botones B/I/U aplican formato al texto seleccionado con `**negrita**`, `*cursiva*` y `__subrayado__`; la vista previa y las actas interpretan únicamente esas marcas y escapan todo HTML de origen. Guarda autor, fecha y versiones anteriores en la configuración del sistema. Rechaza guardar sobre una versión que otra persona haya cambiado.

Los cambios afectan las próximas actas. Cada revisión nueva conserva una instantánea de su contexto, servicios y plantillas; los PDFs emitidos y las revisiones anteriores no se regeneran por editar una plantilla.

Revisiones realizadas incluye filtros por contrato, inmueble SIMI, propietario, arrendatario y fechas; pagina de 30 en 30 y permite consultar cada revisión, previsualizar sus actas y copiar un enlace público.

La vista pública muestra datos contractuales, fechas de revisión y corte por servicio cuando existen, resultados y valores. Omite el nombre del propietario (incluida la impresión) y sucursal, y cierra con Realizado por. Las actas se abren dentro de la página. Imprimir oculta acciones, reduce tablas y mantiene juntas las tarjetas y firmas. Sin sesión requiere `expires` y una firma HMAC ligada a la revisión. Los enlaces nuevos duran 180 días. Las URLs externas antiguas enviadas previamente no cambian automáticamente.

## Notificaciones

Los correos continúan en shared-notifications. Los destinatarios son propietario, arrendatario y funcionarios activos seleccionados en Notificaciones internas / acta_servicios_publicos. No se introducen destinatarios fijos ni envíos directos. Encolar se realiza después de confirmar la transacción.

## Verificación

La interfaz usa Tailwind 3.4.17 compilado localmente, sin CDN ni preflight adicional. Sus utilidades `!sp-` están acotadas al panel para prevalecer sobre reglas antiguas sin alterar otros módulos. Para recompilar:

```powershell
npx --yes tailwindcss@3.4.17 -c tailwind.services.config.js -i resources/css/tailwind-services.css -o public/assets/css/tailwind-services.css --minify
```

Pendientes conserva filtros de servidor y añade paginación local, tamaño de página y filtros rápidos sobre el resultado cargado. Sincronizar recarga el listado y sus indicadores. Las exportaciones descargan CSV UTF-8 compatible con Excel: todos los pendientes del resultado actual, o la página actual del historial (indicado en el nombre del archivo y la ayuda del botón). Se neutralizan fórmulas en campos aportados por usuarios.

```powershell
php tests/public-services-review-check.php
php tests/public-services-liquidator-check.php
```

La prueba de revisiones usa sombras TEMPORARY de las tablas, incluido el almacenamiento nativo, y un proveedor inerte: no cambia datos permanentes ni envía mensajes.

Para probar la interfaz con Playwright, sirve la raíz del repositorio con PHP CLI-server en `127.0.0.1:9015` y ejecuta `node tests/public-services-workspace-check.cjs` con Playwright disponible. El harness `tests/public-services-review-ui.php` no conecta a base de datos y simula guardados. Verifica subpestañas, edición de la plantilla crítica, variables, vista previa, borradores, filtros y diseño móvil.

## Recuperación de fechas históricas (3.3.343)

Los contratos entregados sin `ultima_revision_servicios` pueden recuperar la fecha real desde las revisiones CCT existentes, incluidas las realizadas antes de la ocupación. Se exige coincidencia exacta de `id_contrato` con el `_ID` del contrato y de `id_inmueble`; se rechazan códigos contradictorios, fechas futuras y registros sin fecha real. Se toma la revisión válida más reciente, usando `fecha` o, cuando está vacía, las fechas explícitas de revisión de sus servicios. No se usa la fecha de creación como prueba de revisión.

El listado utiliza esa evidencia sin escribir en la base de datos, de modo que también cubre nuevas entregas provenientes del flujo externo. La próxima revisión se calcula tres meses después de la revisión real. Si no existe evidencia, el mes heredado se sitúa en su primera ocurrencia desde la entrega; no se fuerza al año actual ni se desplazan vencimientos antiguos. La fecha de entrega nunca se registra como una revisión realizada.

`php bin/repair-public-services-dates.php` muestra un diagnóstico sin cambios. `--contract=787` limita por `_ID` CCT. Para persistir: `php bin/repair-public-services-dates.php --apply --employee=1`, usando el `id_empleado` real de un funcionario activo. Cada reparación bloquea el contrato, vuelve a validar su fecha vacía y la evidencia, actualiza fecha/mes y conserva los valores anteriores y el ID de revisión fuente en el historial del inmueble, en una transacción. Es idempotente; no crea revisiones, actas ni notificaciones y no incrementa contadores. Los contratos sin respaldo se mantienen sin fecha para su verificación.

Ejecución del 2 de octubre de 2026: de 131 contratos entregados sin fecha, se recuperaron 87 con evidencia y quedaron 44 sin respaldo inequívoco. El contrato #852 (CCT 787) recuperó el 25/09/2026 desde la revisión 890, realizada antes de la ocupación; próxima revisión 25/12/2026 y `mes_revision_servicios=12`.

## Ajuste administrativo de programación (3.3.345)

En la columna Acciones del listado, los administradores encuentran «Ajustar fecha». Abre el editor directamente en la fila del contrato; no aparece dentro del popup de revisión. Antes de guardar, solicita confirmación mostrando contrato, fecha elegida, próxima revisión y motivo. Cancelar no envía la solicitud ni altera los datos. Se verifica el cargo administrativo (11, 12, 13 o 14, igual que la administración de permisos del panel) desde la ficha activa del funcionario en base de datos, tanto al mostrar como al guardar. No se acepta elevar permisos por campos de formulario o por un cargo de sesión desactualizado.

Permite elegir una fecha hasta hoy, con un motivo obligatorio y vista previa de la próxima revisión (tres meses calendario). Actualiza `ultima_revision_servicios` y `mes_revision_servicios` en una transacción con bloqueo del contrato, token firmado y control de versión. El historial conserva motivo, valores anteriores y nuevos y autor real; distingue el ajuste de una revisión efectivamente realizada. No cambia las revisiones históricas, actas, resultados ni contadores, y no envía notificaciones. Al guardar refresca los filtros y totales, y renueva los tokens del listado. Los contratos salen del mes anterior cuando cambia su próxima fecha. Un reintento idéntico no duplica historial; un formulario antiguo no puede sobrescribir otro ajuste.


## Reprogramación individual y múltiple (3.3.346)

En Acciones aparece «Programar para otro mes». Selecciona únicamente ese contrato y abre el control «Programar seleccionados» sobre la tabla, donde se escogen mes y año completos y un motivo. También se pueden marcar varios contratos, seleccionar la página actual o todos los resultados del filtro (incluido el filtro rápido). Las selecciones se conservan al cambiar de página; al recargar o filtrar en el servidor se limpian. El contador informa cuántos contratos se enviarán. Límite por operación: 500.

Antes de guardar se confirma cantidad, contratos, mes/año y motivo. Cancelar no envía la solicitud. El destino debe ser el mes actual o uno futuro. Se conserva el día de la próxima fecha anterior y se ajusta al último día del mes si no existe (31 de enero a febrero de 2027: 28 de febrero). La programación completa se guarda en `proxima_revision_servicios`, con prioridad sobre el cálculo trimestral, y se sincroniza `mes_revision_servicios`. No se altera `ultima_revision_servicios`, resultados, contadores ni actas y no se emiten notificaciones.

Permisos administrativos se verifican desde la ficha activa en base de datos. Todos los contratos se bloquean y validan con tokens firmados que incluyen la programación manual, luego se actualizan junto con sus historiales en una única transacción. Los cambios se escriben en bloque para evitar consultas por cada contrato. Cualquier conflicto cancela todo el grupo; un reintento idéntico no duplica historial. Cada historial conserva fecha anterior y destino, motivo y funcionario real. Al registrar una revisión efectiva o ajustar administrativamente la fecha de última revisión, se borra la reprogramación manual y se retoma el ciclo trimestral desde esa fecha. La recuperación de una fecha histórica faltante respeta cualquier programación manual existente.
