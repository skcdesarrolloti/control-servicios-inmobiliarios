# Actas de solución y satisfacción de tickets

## Flujo

Desde **Ver caso → Acta de solución y firma**, el funcionario indica quién realizó
la solución (inmobiliaria, propietario, arrendatario o copropiedad), registra uno o varios pares
daño/solución y las observaciones, y selecciona quién firma. Ejecutor y firmante
pueden ser diferentes. Los contactos se obtienen del ticket; para copropiedad se usa
también su registro relacionado por `_ID`. No se acepta un correo arbitrario
desde el formulario. Para personas jurídicas se indica el nombre del representante
que firmará en el correo registrado.

Cada daño admite hasta **4 fotos** y el acta hasta **12**. El navegador reduce cada
imagen JPG, PNG o WebP a JPEG, máximo 1600 px y calidad 78 % antes de enviarla; el
servidor vuelve a normalizarla cuando GD está disponible y siempre rechaza una
evidencia que no termine como JPEG de máximo 1600 px y 1,5 MB. La interfaz muestra
vistas previas y el PDF incluye las fotografías junto al daño correspondiente. El
conjunto comprimido no puede superar 8 MB, para mantener el PDF dentro del BLOB.

Generar el acta deja `estado = En proceso` y conserva uno de los estados
`En ejecucion por inmobiliaria/propietario/arrendatario/copropiedad`, según quién
realizó la solución. Se guarda una copia inmutable de
los datos en `wp_scm_ticket_completion_acts` (prefijo configurable), y se encola
la invitación en **shared-notifications**. Una falla de la cola conserva el acta
y muestra la opción de reintentar; “encolado” no significa “entregado”.

Las fotos no se guardan como Base64 ni BLOB en MySQL. Los archivos comprimidos se
guardan con nombre aleatorio en `SCM_UPLOAD_PATH`; `payload_json` conserva por cada
daño el nombre, MIME, dimensiones, bytes y SHA-256. El hash del payload protege esa
relación y el PDF vuelve a comprobar el archivo antes de firmar. No se agregan campos
a `jet_cct_actas_de_satisfaccion`: esa CCT continúa siendo el hito final creado solo
al firmar. Tampoco se requiere una tabla adicional para fotos ni una migración nueva.

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
El transporte toma `valor_transporte` y fija automáticamente el doble para cubrir
ida y regreso; por ejemplo, una configuración de `$4.000` deja `$8.000` como
valor fijo. El formulario no permite editarlo y el servidor lo recalcula desde
configuración aunque se manipule el navegador.
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

### Enlace profundo desde un Listing de JetEngine

Para abrir la creación desde un ticket mostrado en WordPress, usar un Dynamic Link
con URL personalizada:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/crear-acta.php?ticket_pk=%current_field|_ID%
```

El Listing debe tener como objeto actual la CCT de tickets. `%current_field|_ID%`
representa el `_ID` interno de la fila; no se debe sustituir por `id_ticket`, que es
el número visible. El endpoint no crea ni modifica nada por GET: si falta sesión
redirige al login conservando solamente este destino interno; después comprueba los
permisos y muestra una pantalla autenticada de creación. Al guardar, redirige a
`Actividades administrativas → Actas de satisfacción`, filtrada por el caso. Un
ticket cerrado o con acta activa muestra su estado y no permite crear un duplicado.

### Enlace directo con autologin por token

Si el enlace se abre desde un flujo externo donde ya sabes qué funcionario está
operando, puedes agregar autologin con `id_empleado` y un `token` compartido del
entorno:

```text
https://sucasainmobiliaria.com.co/control-servicios-inmobiliarios/public/crear-acta.php?ticket_pk=<ID_INTERNO_CASO>&id_empleado=<ID_EMPLEADO>&token=<ACTA_AUTOLOGIN_SECRET>
```

Primero define un secret exclusivo para este flujo en `.env`:

```env
ACTA_AUTOLOGIN_SECRET=coloca-aqui-un-valor-largo-aleatorio-de-minimo-32-caracteres
```

El autologin solo funciona si:

- `token` coincide exactamente con `ACTA_AUTOLOGIN_SECRET`.
- `ACTA_AUTOLOGIN_SECRET` está configurado con mínimo 32 caracteres.
- `id_empleado` existe en `wp_jet_cct_funcionarios` y el funcionario está activo.
- El funcionario tiene permiso para crear/consultar actas del caso.

No usar `pass_others_apss` en URLs ni para generar sesión. La contraseña nunca debe
viajar por enlace; el autologin por token crea la sesión directamente con la
identidad del funcionario activo. Trata este token como una contraseña maestra de
este flujo y rótalo si se filtra.

La pantalla directa renueva la verificación de seguridad antes de guardar y mantiene
un heartbeat cada 4 minutos mientras está visible. La sesión del panel queda por
defecto en 8 horas de inactividad, con mínimo técnico de 4 horas aunque
`SESSION_IDLE_TIMEOUT` se configure por debajo.

No publicar enlaces con el token personal del destinatario ni reutilizar el token de
Solicitudes Web. Esos contratos tienen otra identidad y otros permisos.

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

### WhatsApp: tres plantillas separadas

Crear las siguientes plantillas en la misma cuenta de WhatsApp Business utilizada
por shared-notifications. Son definiciones para solicitar aprobación, **no plantillas
ya creadas o aprobadas en Meta**. No se crean automáticamente al desplegar.

#### 1. Solicitud de firma

- Nombre: `scm_acta_solicitud_firma_v1`.
- Categoría solicitada: **Utilidad / UTILITY**; Meta determina su aprobación.
- Idioma del ejemplo: **Español (Colombia), `es_CO`**.
- Solo cuerpo de texto, variables **numéricas/posicionales**. Sin encabezado,
  pie de página ni botones; el enlace va dentro del cuerpo.

```text
Hola {{1}}.

SKC SuCasa Inmobiliaria te solicita revisar el acta de solución del ticket #{{2}}, con los daños registrados, las soluciones realizadas y las observaciones.

Revisa el documento y firma únicamente si estás conforme. Al completar la firma, se cerrará el ticket.

Revisar y firmar: {{3}}

Este enlace es personal. No lo compartas.
```

Variables, en orden: `{{1}}` nombre del firmante, `{{2}}` número visible del ticket,
`{{3}}` enlace personal para revisar y firmar. Ejemplos ficticios para el formulario
de Meta: **María Pérez**, **10368** y el enlace de muestra en
[`whatsapp-acta-invitation-template.json`](whatsapp-acta-invitation-template.json).
Nunca usar un token real como muestra pública.

#### 2. Entrega del acta firmada

- Nombre: `scm_acta_firmada_v1`.
- Categoría solicitada: **Utilidad / UTILITY**.
- Mismo idioma que la solicitud; solo cuerpo, variables numéricas/posicionales.
- Sin encabezado de documento, pie de página ni botones: se entrega un **enlace
  para descargar el PDF firmado**, no un archivo adjunto al mensaje.

```text
Hola {{1}}.

Confirmamos que el acta de solución del ticket #{{2}} quedó firmada y el ticket fue cerrado.

Puedes descargar tu copia del acta firmada aquí: {{3}}

Conserva el documento para tu registro. Este enlace es personal; no lo compartas.

SKC SuCasa Inmobiliaria.
```

Variables, en orden: `{{1}}` nombre del firmante, `{{2}}` número visible del ticket,
`{{3}}` enlace personal al PDF (`format=pdf`). Definición y muestras:
[`whatsapp-acta-receipt-template.json`](whatsapp-acta-receipt-template.json).
Se encola únicamente después de confirmar la firma y el cierre en base de datos.

#### 3. Autenticación para firmar

- Nombre: `scm_acta_firma_otp_v1`.
- Categoría: **Autenticación / AUTHENTICATION** (no Utilidad ni Marketing).
- Idioma del ejemplo: `es_CO`; registrar el código exacto aprobado.
- Tipo de código: **Copiar código / COPY_CODE**; no autocompletar ni zero-tap.
- Activar recomendación de seguridad y vencimiento de **10 minutos**.
- El cuerpo utiliza el formato predefinido de autenticación de Meta: no pegar
  un texto libre con datos del ticket, enlaces o instrucciones de firma.
- Única variable de cuerpo: el código de **6 dígitos**. El sistema envía el mismo
  código también al botón «Copiar código»; no hay una segunda variable del usuario.

Definición: [`whatsapp-acta-otp-template.json`](whatsapp-acta-otp-template.json).
Una plantilla general nunca se usa para enviar códigos de autenticación.

#### Activación después de la aprobación

Configurar estos valores en el entorno de **la aplicación PHP que encola**,
solo después de confirmar aprobación, nombre, idioma y componentes de cada plantilla:

```dotenv
SCM_ACTA_WHATSAPP_INVITATION_TEMPLATE=scm_acta_solicitud_firma_v1
SCM_ACTA_WHATSAPP_RECEIPT_TEMPLATE=scm_acta_firmada_v1
SCM_ACTA_WHATSAPP_LANGUAGE=es_CO
SCM_ACTA_WHATSAPP_OTP_TEMPLATE=scm_acta_firma_otp_v1
SCM_ACTA_WHATSAPP_OTP_LANGUAGE=es_CO
```

Los idiomas deben coincidir exactamente con los aprobados. Si el administrador
ofrece solo «Español», comprobar el código antes de activar; no asumir que es
`es_CO`. Las dos plantillas de Utilidad comparten `SCM_ACTA_WHATSAPP_LANGUAGE`;
OTP tiene idioma independiente. No añadir botones ni cambiar el orden de variables
sin adaptar también el envío. Los nombres se pueden configurar si Meta aprueba
otros, pero deben respetar el mismo contrato de componentes.

Los tres nombres anteriores son los valores predeterminados del código. Las variables
de entorno permiten reemplazarlos, pero no son obligatorias. Si Meta rechaza o pausa
una plantilla no se dispara un segundo envío automático usando una plantilla general:
el intento queda trazable en la cola y el funcionario puede usar correo o reintentar.

No existe bypass por fallo de plantilla o cola, firma vacía o código vencido. Antes
de habilitar WhatsApp en producción se debe confirmar que `scm_acta_firma_otp_v1`
esté aprobada; el código tratará ese nombre exacto como la plantilla disponible.

La cuenta y sus secretos siguen configurados en shared-notifications; no se
duplican en este módulo. El correo no necesita plantillas de Meta. Esta configuración
afecta mensajes nuevos: no reescribe mensajes que ya están en cola. Verificar una
entrega con un contacto de prueba autorizado antes de dar por habilitado el canal;
encolar no confirma entrega ni aprobación de la plantilla.

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
