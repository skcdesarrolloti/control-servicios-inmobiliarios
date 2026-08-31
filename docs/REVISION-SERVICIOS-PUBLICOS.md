# Revisión nativa de servicios públicos — producción

## Corrección del funcionario

La sesión guarda el `_ID` de la fila en `jet_cct_funcionarios`. No es el mismo dato que `id_empleado`. La implementación anterior buscaba `id_empleado = Auth::userId()` y podía devolver otro funcionario (el caso reportado como Gloria Correa).

Ahora se consulta exclusivamente `funcionarios._ID = Auth::userId()` y de esa misma fila activa se toman `id_empleado`, nombre y correo. El cliente no puede elegir ni enviar el autor. El formulario muestra el nombre y el ID empleado. La revisión, contrato, historial y autor CCT usan ese `id_empleado`; la respuesta al correo usa su dirección. Si falta la ficha o el ID, se bloquea el guardado. No cambia la identidad global de sesión ni otros módulos. No se reatribuyen automáticamente registros históricos: requieren identificar su autor real antes de corregirlos.

## Listado y configuración

- Los contratos entregados con servicios configurados conservan su programación trimestral y los filtros existentes.
- Los contratos sin servicios aparecen en un grupo separado, **Sin servicios configurados / por verificar**, fuera del contador de revisiones pendientes. Se puede abrir el grupo para corregirlos. Este grupo respeta los filtros de contrato, inmueble y personas, pero no el mes, pues no tiene una revisión programada fiable.
- Una lista explícita vacía (`serialize([])`) significa que no hay servicios; los identificadores históricos no la vuelven a activar. Solo cuando el campo legado está vacío se infieren servicios de `luz`, `agua` o `gas`.
- El formulario siempre ofrece energía, agua y gas. **El inmueble tiene este servicio** modifica la configuración. **Revisar ahora** es independiente: desmarcarlo no elimina el servicio.
- NIC, póliza, contrato de gas y medidores son editables. Se guardan en las columnas históricas del contrato. La lista se serializa con los valores compatibles `Energia`, `Agua`, `Gas`.
- Al retirar un servicio se conserva su información histórica. No se borran revisiones, actas ni cuentas anteriores. Volver a activarlo permite recuperar/corregir sus identificadores.

## Dos flujos

### Solo corregir servicios y datos del contrato

1. Validar sesión, permiso de la pestaña, CSRF, contrato por `_ID` exacto y funcionario activo.
2. Validar la lista de servicios y limpiar los identificadores/medidores.
3. En una transacción, actualizar configuración y registrar historial con el funcionario autenticado.
4. Recargar el listado.

No crea revisión, PDF ni correo. No cambia fecha, mes siguiente ni contador de revisiones. Permite dejar datos incompletos para completarlos posteriormente. Desactivar todos los servicios requiere confirmación en la interfaz.

### Registrar revisión y generar actas

1. Resolver contrato/funcionario en el servidor y validar la configuración editable.
2. Exigir al menos un servicio a revisar, activado en la configuración. Para cada uno: identificador, medidor, resultado y valor. `Al dia` requiere 0; los estados de mora requieren un valor positivo.
3. Generar **un PDF por servicio revisado**, máximo tres. Existen seis variantes: felicitación y mora para energía, agua y gas. La mora adapta el texto a 30 días, 60 días o estado crítico. Todas usan el membrete institucional.
4. En una transacción: insertar `jet_cct_revisiones_servicios`, guardar configuración/resultados/URLs en `jet_cct_contratos_arrendamiento`, insertar `jet_cct_historial_del_inmueble` y actualizar `postmeta.revisiones-servicios` si `id_inmueble` corresponde a un post `inmuebles`.
5. Actualizar `ultima_revision_servicios` con la fecha actual e incrementar el contador. El mes siguiente mantiene exactamente `(($mes + 3 - 1) % 12) + 1`; si el mes anterior no es válido, se toma el actual. Ejemplo: noviembre → febrero.
6. Confirmar la transacción. Si falla la persistencia, revertirla y eliminar los PDF recién generados.
7. Encolar correos con adjuntos y enlaces firmados: propietario, arrendatario y los cuatro destinatarios administrativos heredados. Los destinatarios válidos son los que se encolan. El número mostrado es **encolado**, no entregado. El worker compartido registra resultado e intentos. No ejecutar el hook JetFormBuilder adicionalmente: su efecto ya está integrado y duplicarlo avanzaría dos veces el mes.
8. Mostrar confirmación/enlaces y recargar pendientes. Solo los servicios revisados obtienen nuevos resultados/actas; los demás conservan sus resultados previos en el contrato.

## Archivos que intervienen

| Archivo | Responsabilidad |
| --- | --- |
| `src/Core/Auth.php` | Sesión existente; devuelve el `_ID` interno del funcionario. No se modifica. |
| `src/Modules/Pending/PendingRepository.php` | Consultas exactas de contrato y funcionario, persistencia e historial. |
| `src/Modules/Pending/Concerns/PendingQueriesConcern.php` | Separación de contratos sin servicios del listado de revisiones. |
| `src/Modules/Pending/Concerns/PublicServicesReviewConcern.php` | Validación, configuración, transacción, autor, programación y correos. |
| `src/Modules/Pending/PendingController.php` | Conecta servicio y vistas. |
| `src/Modules/Pending/PendingView.php` | Formulario y grupo de contratos sin configurar. |
| `src/App/Concerns/HandlesTicketWorkflowActions.php` | Operaciones AJAX `load`, `configure`, `submit`, permisos y CSRF. |
| `public/assets/js/admin-dashboard-runtime.js` | Modal, modos, validación y recarga. |
| `public/assets/css/admin/04-dashboard-pending.css` | Presentación responsive. |
| `src/Modules/Pending/PublicServicesReviewPdfGenerator.php` | PDF y membrete `resources/assets/membrete-sucasa.jpg`. |
| `src/Support/StoredFileService.php` y `public/file.php` | Archivo privado y acceso con firma HMAC. |
| `src/Support/EmailQueue.php` y `SharedNotificationsBridge.php` | Cola común y adjuntos. |
| `bin/check-public-services-review.php` | Preflight de producción de solo lectura. |
| `tests/public-services-review-check.php` | Prueba integral con tablas temporales y proveedor inerte. |

Los PDF se crean en `storage/uploads/<nombre-aleatorio>.pdf`, no dentro de `public/`. No se generan archivos PHP ni plantillas adicionales por revisión. Se conserva `storage/` y el mismo `APP_SECRET` entre despliegues para que los enlaces anteriores sigan funcionando.

## Publicación

Este cambio reutiliza el esquema existente: **no requiere migración SQL**. La comprobación detecta columnas faltantes y tablas no transaccionales; si falla, no publicar ni alterar el esquema a ciegas.

1. Respaldar código, base de datos y `storage/`. Desplegar el commit completo de `main` de forma atómica, no únicamente el JavaScript o PHP. Conservar `.env` y `storage/` del servidor.
2. Instalar dependencias de producción y verificar:

   ```bash
   composer install --no-dev --optimize-autoloader
   php bin/check-public-services-review.php
   ```

3. Verificar permisos de escritura de **PHP web y del worker** sobre `storage/uploads`, `storage/logs` y `storage/data`; el worker debe poder leer los PDF. Mantener el document root en `public/` y `BASE_URL` HTTPS apuntando al panel.
4. Mantener un solo worker global de shared-notifications. No añadir otro cron si ya procesa esta cola. `bin/queue-worker.php` es el adaptador de compatibilidad disponible si la instalación lo utiliza; no ejecutarlo como una prueba inocua, porque envía trabajos reales.
5. Invalidar caché de assets/OPcache del servidor si aplica. La versión de assets de este cambio es `3.3.6`.
6. Abrir el formulario con el funcionario real y comprobar nombre + ID empleado. Probar una corrección de configuración primero: debe guardar sin actas ni correos. La prueba integral del repositorio es optativa en staging (requiere privilegio `CREATE TEMPORARY TABLES`):

   ```bash
   php tests/public-services-review-check.php
   node --check public/assets/js/admin-dashboard-runtime.js
   ```

   Para inspección visual sin base de datos ni envíos: iniciar `php -S 127.0.0.1:8765 -t .` y abrir `http://127.0.0.1:8765/tests/public-services-review-ui.php`. El servidor debe escuchar solo en loopback y detenerse al terminar; no usar este document root en producción.

7. Registrar una revisión real solamente cuando corresponda y verificar PDF, contador/mes y cola. Revisar `skc_notification_queue` por `source_module = revision-servicios-publicos-nativa`, luego `skc_notification_attempts`. Un estado `pending` todavía no acredita entrega SMTP.

El preflight local no demuestra despliegue remoto, ejecución del cron ni entrega real de correos. Esas verificaciones deben realizarse en el servidor. Si fuera necesario volver al código anterior, conservar los datos y PDF ya creados; no borrar el historial para revertir una versión.
