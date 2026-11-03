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
| **Fase 8 (puntos 1–4):** (1) la cotización que aprueba el propietario también llega, por correo y WhatsApp, a cada copropietario (`ContractCoOwnerContacts::forContract`), sin repetir a quien ya es el destinatario; (2) la búsqueda del solicitante (`RequesterLookupConcern`, `PanelTicketCreationConcern`) encuentra contratos por `id_propietario_2..6` y por `documento_2..6` del mandato; (3) Notificaciones administrativas incluye a los copropietarios (`contract_co_actor_columns`) en filtros, resumen e importación; (4) «+N copropietarios» en el listado y la documentación del contrato. | `src/App/Concerns/HandlesMaintenanceActions.php`, `src/Modules/PublicTickets/Concerns/*`, `AdministrativeNotificationsService.php`, `LeaseContractsView.php`, `tests/co-owners-modules-check.php` | ver `git log` |

## Fases pendientes (orden recomendado)

### 4. Fase 7: créditos, firmas y avisos

- `promissoryState`: personal → el solicitante; reparaciones → todos los copropietarios, obligatorio. El modo se congela
  al decidir; los créditos aprobados antes siguen con la regla vieja. `setPromissorySigners` queda bloqueado en
  reparaciones.
- Nuevo chequeo «Copropietarios con documento y contacto» en reparaciones: si falta alguno, no deja enviar el pagaré.
- `sendResponse` en reparaciones: a todos los copropietarios, cada uno con la carta, todos los pagarés firmados y el plan
  de pagos. La carta nombra a todos los titulares. Mismas plantillas de WhatsApp.
- `notifyOwnerRequested` y el rechazo (`notifyDecision`): también a todos en reparaciones.
- **Antes de cerrar esta fase:** jurídica debe confirmar que el texto del pagaré («obligaciones que adquiera») respalda
  obligaciones solidarias de reparaciones.

### 5. Fase 8: lo que queda

- Aumento de canon, anexos, terminación y cartera siguen avisando solo al propietario 1. Queda como decisión de negocio.
- Por confirmar:
  - los copropietarios reciben el mismo enlace firmado de la cotización, así que cualquiera puede responderla;
  - si el destinatario es «Inmobiliaria», no se envía a los copropietarios.
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
3. Seguir con la Fase 7 (y correr la Fase 3 en el servidor). Si hace falta una columna CCT nueva, usar `bin/cct-add-fields.php`, nunca `ALTER TABLE`.
