# Actas de solución y satisfacción de tickets

## Flujo

Desde **Ver caso → Acta de solución y firma**, el funcionario indica quién realizó
la solución (propietario, arrendatario o copropiedad), registra uno o varios pares
daño/solución y las observaciones, y selecciona quién firma. Ejecutor y firmante
pueden ser diferentes. El correo se obtiene del ticket; para copropiedad se usa
también su registro relacionado por `_ID`. No se acepta un correo arbitrario
desde el formulario. Para personas jurídicas se indica el nombre del representante
que firmará en el correo registrado.

Generar el acta deja `estado = En proceso` y
`estado_administrativo = En espera de firma`. Se guarda una copia inmutable de
los datos en `wp_scm_ticket_completion_acts` (prefijo configurable), y se encola
la invitación en **shared-notifications**. Una falla de la cola conserva el acta
y muestra la opción de reintentar; “encolado” no significa “entregado”.

La persona abre `public/ticket-acta.php` con su enlace personal y revisa el
documento. Debe escribir el nombre designado, documento de identidad y aceptar
expresamente. Es firma electrónica por nombre escrito y enlace personal, no
firma digital certificada ni validación documental de identidad. El enlace es
una credencial: quien lo posee puede usarlo, por lo que no debe compartirse.

Al firmar, una sola transacción InnoDB:

1. Registra firma, texto de aceptación, fecha, IP, agente de usuario, hash de
   contenido y HMAC de la evidencia.
2. Publica el acta en `jet_cct_actas_de_satisfaccion` y la vincula al ticket.
3. Crea un reporte en `jet_cct_reportes_administrativos`, con `exportado = No`
   y `fue_pagado = No`. Guarda el ID del reporte en el acta.
4. Cambia el ticket a `Cerrado / Finalizado` y añade el historial.

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
- Las integraciones externas de WordPress no pasan por estos controles PHP.
  Si cambian el estado de un ticket pendiente, la firma falla de forma segura
  y requiere revisión; no se altera código externo desde este repositorio.

## Instalación

Antes de habilitar la versión en cada entorno:

```powershell
php bin/migrate-ticket-completion.php
```

La migración es aditiva: crea únicamente la tabla privada nueva y valida las
columnas CCT requeridas y el motor InnoDB. No modifica ni cierra tickets
existentes. No se ejecuta DDL durante solicitudes web. Requiere desplegar
los archivos nuevos y mantener disponible el worker de shared-notifications.

## Verificación

```powershell
php tests/ticket-completion-check.php
php tests/ticket-completion-check.php --database
```

El primer comando prueba validación y números sin base de datos. El segundo
clona **solo la estructura** de las tablas CCT en tablas `TEMPORARY` con prefijo
aleatorio, ejecuta el flujo con datos ficticios y sustituye el envío por una
captura en memoria. Las tablas desaparecen al cerrar la conexión. Incluye
doble firma, expiración, anulación, ausencia de contactos y rollback por fallo
SQL al registrar el reporte. No envía correos ni crea cobros reales.

La entrega real de un correo requiere una prueba acordada con un destinatario
de prueba y revisar `skc_notification_queue` / `skc_notification_attempts` en
el entorno desplegado. Las pruebas automatizadas no ejecutan el worker real.
