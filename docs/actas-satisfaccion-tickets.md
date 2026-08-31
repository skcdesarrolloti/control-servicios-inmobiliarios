# Actas de solución y satisfacción de tickets

## Flujo

Desde **Ver caso → Acta de solución y firma**, el funcionario indica quién realizó
la solución (propietario, arrendatario o copropiedad), registra uno o varios pares
daño/solución y las observaciones, y selecciona quién firma. Ejecutor y firmante
pueden ser diferentes. Los contactos se obtienen del ticket; para copropiedad se usa
también su registro relacionado por `_ID`. No se acepta un correo arbitrario
desde el formulario. Para personas jurídicas se indica el nombre del representante
que firmará en el correo registrado.

Generar el acta deja `estado = En proceso` y
`estado_administrativo = En espera de firma`. Se guarda una copia inmutable de
los datos en `wp_scm_ticket_completion_acts` (prefijo configurable), y se encola
la invitación en **shared-notifications**. Una falla de la cola conserva el acta
y muestra la opción de reintentar; “encolado” no significa “entregado”.

El funcionario selecciona **Correo, WhatsApp o ambos** para la invitación y la
copia firmada. No se aceptan contactos arbitrarios desde el formulario.

La persona abre `public/ticket-acta.php` con su enlace personal, revisa el documento,
solicita un código al contacto registrado, escribe nombre y documento, dibuja la
firma con el dedo/mouse y acepta expresamente. Hay alternativa de teclado:
Espacio inicia/finaliza el trazo, y las flechas dibujan. El enlace permite leer el
documento; por sí solo **no permite firmar**. No debe compartirse.

El código vence a los **10 minutos**, con un minuto entre solicitudes, máximo
5 solicitudes y 5 intentos incorrectos por hora por acta. Reenviar el código no
reinicia el presupuesto de intentos. Solo el último código es válido. Se guarda
su HMAC, no el código en claro, en el registro del acta. La cola necesita el código
en el contenido del mensaje para entregarlo: sus tablas deben tener acceso
restringido y política de retención. No se copia el código a metadatos ni historial.

Es **firma electrónica con trazo, verificación de contacto y aceptación**, no firma
digital certificada ni validación documental de identidad. El código prueba acceso
al correo/teléfono; no prueba por sí mismo quién tiene ese dispositivo o cuenta.

Al firmar, una sola transacción InnoDB:

1. Registra trazos numéricos validados, nombre/documento, texto de aceptación,
   canal/desafío de verificación, fecha, IP, agente de usuario, hash de contenido
   y HMAC de la evidencia; consume el código.
2. Publica el acta en `jet_cct_actas_de_satisfaccion` y la vincula al ticket.
3. Crea un reporte en `jet_cct_reportes_administrativos`, con `exportado = No`
   y `fue_pagado = No`. Guarda el ID del reporte en el acta.
4. Genera y guarda el PDF original del destinatario en `signed_pdf` (MEDIUMBLOB),
   con SHA-256 y HMAC. No crea archivos públicos ni depende de recursos remotos.
5. Cambia el ticket a `Cerrado / Finalizado` y añade el historial.

Un fallo de PDF, cobro, cierre o historial revierte toda la transacción. Los
intentos incorrectos del código se guardan fuera de esa transacción, bajo el
mismo bloqueo del ticket, para que un rollback no reinicie el límite.

Después del commit se encola una copia por los canales seleccionados: **enlace
personal para descargar el PDF**, no un adjunto binario. Un fallo de envío no
deshace una firma válida; el panel muestra el problema y permite **Reenviar copia
firmada**. Un reintento de firma no duplica cobros ni copias ya encoladas. Un reenvío
parcial reintenta solo los canales pendientes; un reenvío expresamente solicitado
después de encolar todos permite otra copia. La entrega real se consulta en la cola.

El CCT de actas solo se publica al firmar: las líneas de tiempo anteriores
interpretan la existencia de una fila como trabajo terminado. Los enlaces del
historial nuevo abren el documento nativo y su evidencia de firma.

## Valor administrativo

Se aplica la fórmula del código de referencia entregado por el usuario:
`salario / dias_trabajo * porcentaje_smlmv_co_pre`, con respaldo en
`porcentaje_smlmv`, más transporte. Se soportan porcentajes `10` y `0.10`
sin convertir accidentalmente `0.10` en `10`. No se duplica por amoblado.
Si falta una configuración válida, el funcionario debe ingresar explícitamente
la tarifa. El total se confirma antes de generar. Es el servicio administrativo,
no el presupuesto ni el costo de los trabajos de reparación.

El reporte usa categoría **Acta de satisfaccion**, incluye el enlace al acta y
el ID interno exacto del ticket. No se crea cobro por actas pendientes o anuladas.
Una firma repetida devuelve el acta existente sin duplicar el reporte.
El documento para el destinatario no incluye el reporte administrativo interno.
El panel ofrece **PDF destinatario** y **PDF interno**. El PDF destinatario firmado
devuelve exactamente los bytes conservados. El interno es una representación
adicional con cobro, disponible exclusivamente a funcionarios autorizados.
Agregar `audience=staff` a un enlace público no concede acceso a la copia interna.

## Protección y correcciones

- Enlace HMAC de 256 bits, con vencimiento de 30 días; los datos no se sirven
  solo por un ID público. La vista interna exige sesión y permisos del caso.
- CSRF en operaciones internas y firma pública, `no-store`, `no-referrer`, CSP
  y bloqueo de indexación en la página de firma. Sin recursos de terceros allí.
- Firma y creación usan bloqueo del ticket; las acciones autenticadas del
  ticket se serializan con el mismo bloqueo. Respuesta, seguimiento, cierre,
  postergación, traslado y aprobación de pre-reporte no pueden saltarse una
  firma pendiente dentro de esta aplicación.
- Una versión pendiente se puede anular con motivo. Conserva el historial,
  revoca el enlace, no genera reporte y permite preparar otra versión.
- Un acta firmada no puede editarse o anularse desde esta interfaz. El cierre
  anterior no se aplica de nuevo si el ticket se reactiva expresamente.
- Reenviar mantiene los enlaces vigentes; si vencieron, emite uno nuevo.
- Las actas firmadas anteriores se conservan sin alterar evidencia ni cobros;
  su PDF se genera como representación histórica, sin inventar trazos ni OTP.
  Todas las actas aún pendientes, incluso las creadas antes de 3.3.4, requieren
  trazo y código al firmar. No se reescribe su contenido original.
- Las integraciones externas de WordPress no pasan por estos controles PHP.
  Si cambian el estado de un ticket pendiente, la firma falla de forma segura
  y requiere revisión; no se altera código externo desde este repositorio.

## Instalación

Antes de habilitar la versión en cada entorno:

```powershell
php bin/migrate-ticket-completion.php
```

La migración es aditiva e idempotente: crea la tabla privada si no existe o agrega
`otp_json`, `delivery_json`, `signed_pdf`, `pdf_hash`, `pdf_hmac` si faltan. Valida
columnas CCT y motor InnoDB. No modifica datos, firmas, cobros ni estados existentes.
Ejecutarla antes de activar el nuevo código. Requiere desplegar los archivos y
mantener disponible el worker de shared-notifications. Los códigos tienen prioridad
200 (el worker procesa prioridad descendente), frente a 100 de las invitaciones.

### WhatsApp

- Invitación/copia: reutiliza `scm_notificacion_general_v1`, `es_CO`, con los tres
  parámetros existentes: destinatario, mensaje/enlace, firma de la inmobiliaria.
  Debe estar aprobada y disponible en la cuenta configurada.
- OTP: requiere una plantilla de categoría **AUTHENTICATION**, botón **COPY_CODE**,
  recomendación de seguridad y vencimiento de 10 minutos. Una plantilla general
  no se usa para códigos de autenticación.
- Configurar en el entorno, **solo después de verificar su aprobación**:

```dotenv
SCM_ACTA_WHATSAPP_OTP_TEMPLATE=nombre_real_de_la_plantilla_aprobada
SCM_ACTA_WHATSAPP_OTP_LANGUAGE=es_CO
```

El nombre no incluye credenciales. La cuenta y sus secretos siguen configurados
en shared-notifications; no se duplican en este módulo. Se adjunta un ejemplo de
solicitud en `docs/whatsapp-acta-otp-template.json`; crearla/aprobarla es un paso
externo en Meta, no se realiza automáticamente al desplegar.

Si no está configurada, la interfaz informa que el enlace puede ir por WhatsApp
pero **la verificación será por correo**. Un contacto sin correo no podrá ser
elegido hasta disponer de WhatsApp OTP configurado. No existe bypass por ausencia
de plantilla, fallo de cola, firma vacía o código vencido.

Referencia de contrato de plantilla: [colección oficial de Meta](https://www.postman.com/meta/whatsapp-business-platform/request/6vkv46u/create-authentication-template-w-otp-copy-code-button).

## Verificación

```powershell
php tests/ticket-completion-check.php
php tests/ticket-completion-check.php --database
php tests/ticket-completion-delivery-check.php
```

El primer comando prueba validación y números sin base de datos. El segundo
clona **solo la estructura** de las tablas CCT en tablas `TEMPORARY` con prefijo
aleatorio, ejecuta el flujo con datos ficticios y sustituye el envío por una
captura en memoria. Las tablas desaparecen al cerrar la conexión. Incluye
doble firma, expiración, anulación, ausencia de contactos y rollback por fallo
SQL al registrar el reporte. No envía correos ni crea cobros reales.

Incluye pruebas de firma vacía/malformada, intento sin OTP, límites que sobreviven
a rollback, código expirado, PDF original/tamper, dos canales y reenvío de copia.
El segundo archivo prueba los adaptadores reales sobre copias **TEMPORARY** de
las tablas compartidas y un worker con proveedores inertes: nunca ejecuta SMTP
ni Meta. El estado `sent` en esa prueba es simulado, no evidencia de entrega real.
`--pdf-fixture` genera documentos sintéticos en `tmp/pdfs/` para inspección visual.

La entrega real de un correo requiere una prueba acordada con un destinatario
de prueba y revisar `skc_notification_queue` / `skc_notification_attempts` en
el entorno desplegado. Las pruebas automatizadas no ejecutan el worker real.
