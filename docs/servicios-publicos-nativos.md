# Servicios públicos nativos

El módulo reúne Pendientes, Plantillas de actas y Revisiones realizadas. Usa las revisiones CCT existentes y conserva sus PDFs históricos. La página de WordPress `revision-de-servicios-publicos` y su listing Elementor 44933 se revisaron como referencia; los nuevos correos e historiales apuntan a `public/revision-servicios-publicos.php`.

## Preparación del servidor

```powershell
php bin/migrate-public-services.php
php bin/check-public-services-review.php
```

La migración agrega la tabla `scm_public_services_reviews` con el prefijo configurado. No modifica revisiones CCT, contratos ni actas existentes. Contratos, revisiones, historial y la tabla nueva requieren InnoDB. Los PDFs usan el mismo renderizador Chromium/Gotenberg de las actas de satisfacción y las fuentes locales. Se conserva la identidad del membrete en el encabezado y cierre del documento.

## Programación y duplicados

La siguiente revisión se calcula a tres meses calendario de la fecha real de revisión en Colombia. Se conserva el día cuando existe y se ajusta al último día del mes cuando es necesario. El mes anterior configurado solo interviene al no existir ninguna última revisión.

El formulario incluye un token firmado ligado al contrato, funcionario de sesión, fecha y contador de la última revisión. El servidor bloquea la fila del contrato antes de validar y guardar. Un reintento del mismo token devuelve la revisión original sin generar documentos o notificaciones nuevos; otro formulario basado en una versión anterior se rechaza. El token dura 24 horas. No basta con desactivar el botón del navegador.

Marcar Contrato recibido guarda estado Recibido, tipo Ex, fecha y funcionario real, junto con el historial del estado, tipo y fecha anteriores. Si falla el historial se revierte el cambio. Un reintento conserva la fecha registrada. No modifica estados administrativos de tickets.

## Plantillas y documentos

Las cuatro plantillas son Al día, Mora 30 días, Mora 60 días y Crítico/superior a 90 días. La última incorpora el contenido suministrado en `CARTA MORA A 90 DIAS (1).docx`, con fecha, destinatario, inmueble, servicio, referencia y valor dinámicos. El representante legal se resuelve desde la sucursal; el nombre corporativo visible es SKC SuCasa Inmobiliaria.

El editor permite cambiar título y contenido, insertar variables y ver una muestra antes de guardar. Es texto plano: no ejecuta HTML ni JavaScript. Guarda autor, fecha y versiones anteriores en la configuración del sistema. Rechaza guardar sobre una versión que otra persona haya cambiado.

Los cambios afectan las próximas actas. Cada revisión nueva conserva una instantánea de su contexto, servicios y plantillas; los PDFs emitidos y las revisiones anteriores no se regeneran por editar una plantilla.

Revisiones realizadas incluye filtros por contrato, inmueble SIMI, propietario, arrendatario y fechas; pagina de 30 en 30 y permite consultar cada revisión, previsualizar sus actas y copiar un enlace público.

La vista pública muestra datos contractuales, fechas de revisión y corte por servicio cuando existen, resultados y valores. Omite sucursal y cierra con Realizado por. Las actas se abren dentro de la página. Imprimir oculta acciones, reduce tablas y mantiene juntas las tarjetas y firmas. Sin sesión requiere `expires` y una firma HMAC ligada a la revisión. Los enlaces nuevos duran 180 días. Las URLs externas antiguas enviadas previamente no cambian automáticamente.

## Notificaciones

Los correos continúan en shared-notifications. Los destinatarios son propietario, arrendatario y funcionarios activos seleccionados en Notificaciones internas / acta_servicios_publicos. No se introducen destinatarios fijos ni envíos directos. Encolar se realiza después de confirmar la transacción.

## Verificación

```powershell
php tests/public-services-review-check.php
php tests/public-services-liquidator-check.php
```

La prueba de revisiones usa sombras TEMPORARY de las tablas, incluido el almacenamiento nativo, y un proveedor inerte: no cambia datos permanentes ni envía mensajes.

Para probar la interfaz con Playwright, sirve la raíz del repositorio con PHP CLI-server en `127.0.0.1:9015` y ejecuta `node tests/public-services-workspace-check.cjs` con Playwright disponible. El harness `tests/public-services-review-ui.php` no conecta a base de datos y simula guardados. Verifica subpestañas, edición de la plantilla crítica, variables, vista previa, borradores, filtros y diseño móvil.
