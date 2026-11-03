# Copropietarios con % de participación: estado y fases pendientes

Estado al 31/10/2026, para retomar el trabajo desde otro equipo: `git pull` y seguir desde **Cómo retomar**.

## Objetivo

Registrar a todos los copropietarios de un inmueble con su % de participación en el mandato, la hoja de cierre y el
contrato de arrendamiento, y que los créditos a propietarios respeten ese %:

- **Crédito personal:** el cupo y el ingreso salen del % del solicitante. Firma solo el solicitante.
- **Crédito de reparaciones:** usa el 100 % del ingreso del contrato del inmueble. Firman todos los copropietarios y a
  todos les llega todo: confirmación de la solicitud, rechazo, respuesta, pagarés firmados y plan de pagos.
- No se puede romper el flujo de «Contratos no asignados».

## Decisiones tomadas

| Tema | Decisión |
|---|---|
| Columnas | Numeradas, igual que el mandato: `id_propietario_N`, `porcentaje_N`. |
| De dónde sale el % en el liquidador | `porcentaje_N` del contrato → `porcentaje_N` del mandato → partes iguales, con la política **«Porcentaje de participación registrado»** en rojo (aprobable con justificación) hasta que se cargue el %. |
| Firmantes del pagaré | Personal: solo el solicitante. Reparaciones: todos, obligatorio; el analista no puede desmarcar. |
| Avisos en reparaciones | A todos los copropietarios: confirmación, rechazo, respuesta y cada uno recibe todos los pagarés firmados. |
| Contratos que cuentan en reparaciones | Solo el contrato del inmueble, al 100 %. Los otros contratos del solicitante no entran. |
| Reparaciones vigentes | Reducen el cupo y la cobertura de **cada** copropietario según su % (saldo y cuota × participación). |
| % al crear el contrato desde «No asignado» | Solo lectura: se copian del mandato. Si están mal, se corrigen en el mandato o en la hoja de cierre. |
| Relaciones JetEngine (864 propietario→contrato, 658 propietario→inmueble) | Solo el propietario principal, como hoy. |
| Créditos ya decididos | No cambian. Al decidir se congelan `share`, el modo de firmantes y los copropietarios en `evaluation_json`; los antiguos quedan como `historico` al 100 %. |
| Porcentajes | Punto decimal, máximo 2 decimales, sin `%`, deben sumar 100 (al leer se tolera ±0,05). |

## Ya hecho

| Qué | Dónde | Commit |
|---|---|---|
| **Fase 1:** una sola clase lee los copropietarios y su % (mandato → contrato → ficha; partes iguales marcadas «no registrado» si falta el %). `OwnerCreditService::contractOwners()` ya la usa. | `src/Modules/LeaseContracts/ContractCoOwners.php`, `tests/contract-co-owners-check.php` | `71482e6c` |
| Plugin de WordPress `[skc_copropietarios]`: ver y editar el % de un mandato. Al guardar valida que sume 100, registra en Propietarios a quien no tenga ficha, escribe `porcentaje_N` en el mandato y copia `propietarios`, `id_propietario_2..6` y `porcentaje_1..6` a sus hojas de cierre y contratos. También copia al crear con JetEngine una hoja o un contrato ligado a un mandato. | `wordpress/mu-plugins/skc-copropietarios.php` | `0c818e0d` |
| Hoja de cierre nativa: edita a cada copropietario con sus datos y su %, y lo pasa al mandato. Reemplaza lo que iban a hacer las fases 2 y 4 en los formularios viejos (JetForm y `formularios/*-mandato`). | `src/Modules/ClosingSheets/*`, `docs/hoja-cierre-nativa.md` | `3d01017c` y anteriores |
| Columnas en la base de datos | `cierres` y `contratos_arrendamiento` ya tienen `propietarios`, `id_propietario_2..6` y `porcentaje_1..6`. `contrato_mandato` tiene `porcentaje_1..6` y usa `id_propietario_nuevo_N` para los ids (no `id_propietario_N`). | — |
| **Fase 5:** al crear el contrato desde «No asignado», `LeaseContractCreationService::coOwners()` copia `propietarios`, `id_propietario_2..6` y `porcentaje_1..6` del mandato (o de la hoja si el mandato no los tiene). Solo escribe lo que tiene valor. El formulario muestra el bloque «Copropietarios» de solo lectura con avisos: no suman 100, falta %, la hoja no coincide con el mandato, un dueño vacío o sin ficha. Los avisos nunca bloquean y se repiten en el resultado. Un copropietario sin id se busca en Propietarios por documento; si no tiene ficha, queda el aviso (se registra desde el mandato o la hoja). | `src/Modules/LeaseContracts/LeaseContractCreationService.php`, `LeaseContractCreateView.php`, `tests/lease-contract-creation-check.php` | ver `git log` |
| **Fase 3 (código):** `bin/sync-co-owners.php` (`CoOwnersBackfill`). Hace tres cosas: (1) vincula por documento o registra en Propietarios a los dueños 2..N de los mandatos con más de un propietario y llena `id_propietario_nuevo_N`; (2) completa en los contratos solo las columnas vacías desde el mandato y lista como conflicto los valores distintos; (3) lista los contratos con copropietarios sin % válido. Repetirlo no cambia nada. **Falta correrlo en el servidor**: primero `php bin/sync-co-owners.php` (solo muestra), revisar conflictos y luego `--aplicar --funcionario=<id_empleado>`. | `src/Modules/LeaseContracts/CoOwnersBackfill.php`, `bin/sync-co-owners.php`, `tests/co-owners-backfill-check.php` | ver `git log` |
| **Fase 6:** cálculo de créditos según el %. Puntos principales: (1) `OwnerCreditCalculator::evaluate` recibe `share`: el tope sale del ingreso atribuible y `net_income` se muestra al 100 %; (2) `ownerPortfolio` (`net_income` atribuible, `net_total` al 100 %, `share`), la cobertura y las fuentes de descuento usan solo la parte de la persona; (3) reparaciones (`OwnerCreditService::isRepair`/`mode`, `OwnerCreditSettings::REPAIR_PURPOSE`) usa el contrato del inmueble al 100 %, sin otros contratos, y su plazo sale solo de ese contrato (cotización, solicitud y simulación); (4) las reparaciones vigentes pesan sobre cada copropietario por su %, y una reparación compromete lo que ya se descuenta de su contrato (`personObligations`, `contractObligations`); (5) `requestPreapproval()` da el cupo personal y, por contrato, el de reparaciones, y el JS muestra el que toca según la destinación; (6) `contractsByOwnerDocument` encuentra copropietarios por `id_propietario_2..6` y por documento normalizado; (7) política «Porcentaje de participación registrado», tiles «Participación» e «Ingreso atribuible» y el PDF interno; (8) al decidir se congelan modo, % y copropietarios en `evaluation_json["share"]`: los decididos antes quedan `historico` al 100 %, y una reparación histórica no se reparte. | `src/Modules/OwnerCredits/*`, `public/assets/js/admin-owner-credits.js`, `tests/owner-credit-co-owners-check.php`, `tests/owner-credit-flow-check.php` | ver `git log` |
| **Fase 8 (puntos 2–4):** (1) decidido el 03/11/2026: la cotización de mantenimiento le llega **solo a su destinatario**, no a los copropietarios (se deshizo el envío a ellos); (2) la búsqueda del solicitante (`RequesterLookupConcern`, `PanelTicketCreationConcern`) encuentra contratos por `id_propietario_2..6` y por `documento_2..6` del mandato; (3) Notificaciones administrativas incluye a los copropietarios (`contract_co_actor_columns`) en filtros, resumen e importación; (4) «+N copropietarios» en el listado y la documentación del contrato. | `src/App/Concerns/HandlesMaintenanceActions.php`, `src/Modules/PublicTickets/Concerns/*`, `AdministrativeNotificationsService.php`, `LeaseContractsView.php`, `tests/co-owners-modules-check.php` | ver `git log` |
| **Fase 7 (código):** firmas y avisos. (1) `promissoryState`: en personal firma el solicitante por defecto (el analista puede sumar firmantes, como antes); en reparaciones firman todos los copropietarios, sin desmarcar (`signersLocked`; `setPromissorySigners` se rechaza). Los decididos antes del % siguen con la regla vieja. (2) Chequeo «Copropietarios con documento y contacto» (`contact_missing`): si a alguno le falta, no se envía el pagaré y el liquidador lo avisa. (3) En reparaciones, la confirmación de la solicitud, el rechazo y la respuesta llegan a cada copropietario (`creditRecipients`, sin repetir contactos), con la carta, todos los pagarés firmados y el plan de pagos; la carta nombra a todos los titulares; se usan las mismas plantillas de WhatsApp. | `src/Modules/OwnerCredits/OwnerCreditService.php`, `OwnerCreditView.php`, `OwnerCreditResponseDocuments.php`, `tests/owner-credit-co-owners-check.php` | ver `git log` |
| **Importación de copropietarios de SIMI (código):** `bin/import-co-owners.php` (`CoOwnersImport`, lector `SCM\Support\XlsxReader`). Lee el listado «COPROPETARIOS ACTIVOS 2026» (una fila por copropietario, con el principal y su % en cada fila) y lo carga en los contratos Entregado o Por entregar de cada inmueble. Manda el archivo: reemplaza `propietarios`, `id_propietario_2..8` y `porcentaje_1..8`. Registra en Propietarios a quien no tenga ficha y deja historial en el contrato. Omite, con el motivo, los inmuebles que no suman 100, no tienen contrato activo o tienen otro principal, y avisa si el mandato tiene otros copropietarios. El contrato admite hasta 8 propietarios (`ContractCoOwners::CONTRACT_MAX`; las columnas 7 y 8 salen de `bin/add-co-owner-7-8-fields.php`). Si el mandato tiene un solo dueño, se usan los copropietarios del contrato, y el plugin ya no los borra al guardar ese mandato. | `src/Modules/LeaseContracts/CoOwnersImport.php`, `src/Support/XlsxReader.php`, `bin/import-co-owners.php`, `bin/add-co-owner-7-8-fields.php`, `tests/co-owners-import-check.php` | ver `git log` |

## Fases pendientes (orden recomendado)

### Importación de SIMI: pasos en el servidor

1. `php bin/add-co-owner-7-8-fields.php` (plan) y luego `--aplicar`: copia la pestaña «Propietario 6» como
   «Propietario 7» y «Propietario 8» con todos sus campos y crea las columnas.
   - Campos: id, porcentaje, nombre, documento, correo, indicativo, celular, actas, tipo de persona y documento, y
     representante.
   - Los `id_propietario_7/8` y `porcentaje_7/8` que la primera versión dejó en la pestaña 6 pasan a su pestaña con
     su id. Repetirlo no cambia nada (`CctFieldInstaller::cloneTab`).
   - Después, revisar en JetEngine que el CCT no muestre el aviso de esquema.
2. Subir el archivo al servidor y correr `php bin/import-co-owners.php "COPROPETARIOS ACTIVOS 2026.xlsx"`, que solo
   muestra lo que haría. Revisar los omitidos (otro principal, sin contrato activo) y los avisos de mandato.
3. Aplicar con `--aplicar --funcionario=<id_empleado>`. Además de ids y %, llena en el contrato el nombre, documento,
   correo, indicativo, celular y tipo de persona de cada copropietario. Si se repite, no cambia nada.
4. Para los contratos con mandato de varios dueños: `php bin/sync-co-owners.php` y luego `--aplicar` (Fase 3). Ahora
   también llena el nombre, documento y contacto de los copropietarios que estén vacíos en el contrato.
5. Contratos con aviso «el mandato tiene otros copropietarios»: el liquidador sigue usando el mandato hasta que se
   corrija allí (plugin `[skc_copropietarios]` u hoja de cierre).

### Bot de WhatsApp: créditos

- `public/owner-credit-bot.php` (`OwnerCreditBotApi`): el bot consulta el cupo, radica y consulta solicitudes de crédito
  con la lógica del panel (plazo máximo, avisos internos y confirmación al propietario).
- El cuerpo JSON va firmado con HMAC-SHA256 en `X-SCM-Signature` y lleva `ts` (máximo 5 minutos).
- El secreto es `APP_SECRET` del panel, que debe ser igual a `WHATSAPP_APP_SECRET` del bot.
- Solo opera sobre los contratos donde el documento enviado es de un propietario, y el beneficiario es esa persona.
- Las solicitudes quedan registradas por «Guardian (WhatsApp) · celular».

### 4. Fase 7: lo que queda

- **Antes de usarla en producción:** jurídica debe confirmar que el texto del pagaré («obligaciones que adquiera»)
  respalda obligaciones solidarias de reparaciones. El texto del pagaré no se cambió.

### 5. Fase 8: lo que queda

- Aumento de canon, anexos, terminación y cartera siguen avisando solo al propietario 1. Queda como decisión de negocio.
- Decidido (03/11/2026): la cotización de mantenimiento va solo a su destinatario (propietario principal o quien se
  elija al enviarla). Los copropietarios no la reciben ni pueden responderla.
- Falta hacer:
  - el filtro «Propietario» del listado de contratos todavía no encuentra a un copropietario por nombre;
  - «+N copropietarios» solo aparece en las vistas de contratos de arrendamiento, no en casos ni revisiones.
- Ya pasaba antes: `findOwnerProfilesByDocument` compara el documento sin normalizar, así que «111111» no encuentra una
  ficha guardada como «111.111».

## Verificación por fase

- Panel: `php tests/owner-credit-flow-check.php`, `owner-credit-check.php`, `owner-credit-coverage-check.php`,
  `owner-credit-prepayment-check.php`, `contract-co-owners-check.php` y `lease-contract-creation-check.php`, `co-owners-backfill-check.php` y `owner-credit-co-owners-check.php`.
  Casos: personal al 30 % → cupo del 30 %; reparaciones → 100 % y firman 3; sin % → partes iguales y política en rojo;
  créditos antiguos sin cambio.
- Navegador: liquidador (personal y reparaciones con 3 copropietarios), bloque de copropietarios al crear el contrato y
  el PDF de la carta con varios titulares.
- Commit y push por fase (`AGENTS.md`).

## Pendientes fuera del código

- **Subir al servidor** los commits desde `e5bab3e7` hasta el último de `main` (hoja de cierre nativa, documentos,
  copropietarios en la hoja, horario de mudanza, «Otro» en listas, guardado al terminar subidas, respuesta al final,
  documentos por actor, amenidades con íconos).
- WordPress: cambiar la URL del botón **«Añadir cierre»** a `/control-servicios-inmobiliarios/public/hoja-cierre.php?…`
  y que **«Ver hoja de cierre»** abra `hoja-cierre-ver.php` con autologin (**Tipos de actas y enlaces › Generar enlace**),
  no la vista vieja `/hoja-de-cierre/`.
- Meta: crear la plantilla `scm_hoja_cierre_respuesta_v2` (`docs/whatsapp-hoja-cierre-respuesta-v2-template.json`).
- **Notificaciones internas:** elegir destinatarios de «Hoja de cierre por responder», «Hoja de cierre respondida» y
  «Revisión de servicios de la hoja de cierre». Hoy en «respondida» solo está Royner Guardo (pruebas).
- **Configuración › Permisos:** dejar «Aprobar o no aprobar hojas de cierre» solo en los cargos que responden (hasta
  guardar, solo la tienen los cargos 11–14) y revisar «Subir y eliminar documentos en la carpeta del contrato».
- Seguridad: revocar la contraseña de aplicación de WordPress que se compartió en un chat anterior.

## Pregunta abierta

La barra «Documentos por actor» (`a610f2ad`) está en el formulario de la hoja, en la vista y en la carpeta del contrato.
Falta decidir si se quita del formulario y queda solo en la vista y la carpeta.

## Cómo retomar

1. `git pull` en `main`.
2. Leer este documento, `AGENTS.md`, `docs/hoja-cierre-nativa.md` y `docs/cct-agregar-columnas.md`.
3. Pendiente: correr la Fase 3 en el servidor, la confirmación de jurídica (Fase 7) y lo que queda de la Fase 8. Si hace falta una columna CCT nueva, usar `bin/cct-add-fields.php`, nunca `ALTER TABLE`.
