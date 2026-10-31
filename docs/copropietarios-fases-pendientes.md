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

## Fases pendientes (orden recomendado)

### 1. Fase 5: copiar los copropietarios al crear el contrato desde «No asignado»

El panel crea el contrato con `LeaseContractCreationService`, que hoy solo copia al propietario principal. El plugin de
WordPress no actúa aquí porque el panel escribe directo en la base de datos.

- `LeaseContractCreationService::prefill` / `create` (`src/Modules/LeaseContracts/`):
  - Copiar `propietarios`, `id_propietario_2..6` y `porcentaje_1..6`: primero del mandato vigente; si no los tiene, de
    la hoja de cierre. Si no coinciden, mostrar aviso.
  - En el mandato el id es `id_propietario_nuevo_N`; en el contrato se guarda como `id_propietario_N`.
  - Escribir solo lo que no está vacío, para no borrar datos. Pasar por `filterTableData`, así no falla si falta una
    columna.
  - Un copropietario incompleto o un fallo al registrarlo es un aviso, no un error. Los mandatos viejos con
    `propietarios=2` y el dueño 2 vacío deben seguir funcionando.
  - Las relaciones JetEngine siguen solo con el principal.
- `LeaseContractCreateView`: bloque de solo lectura «Copropietarios» con su %, y el aviso si no suman 100 o falta alguno.
- Nueva prueba `tests/lease-contract-creation-check.php` con cuatro casos: columnas inexistentes, un propietario, tres
  con %, y sin %.

### 2. Fase 3: completar los datos existentes

Script en `bin/` con modo de prueba primero (solo muestra) y luego `--aplicar`:

1. Mandatos con `propietarios > 1`: registrar en `wp_jet_cct_propietarios` a los propietarios 2..N que no tengan ficha
   (buscar por documento normalizado con `OwnerCreditPromissoryService::normalizeDocument`) y llenar
   `id_propietario_nuevo_N`.
2. Contratos de arrendamiento: llenar `propietarios`, `id_propietario_2..6` y `porcentaje_1..6` desde su mandato.
3. Listado final de contratos con copropietarios sin %, para cargarlos en el mandato (plugin o hoja de cierre).

### 3. Fase 6: créditos, cálculo según el % (`src/Modules/OwnerCredits/`)

- `OwnerCreditCalculator::evaluate` recibe `share`:
  - ingreso atribuible = neto × participación;
  - `max_amount = (atribuible + otros contratos atribuibles) × meses`;
  - `net_income` se sigue mostrando al 100 %.
- Aplicar la participación en `ownerPortfolio`, `otherContracts`, `workspaceFor` (contrato principal y
  `coverageContracts`), `discountSources` y `saveSources` (solo se descuenta la parte de esa persona), y en
  `simulation`.
- Mover el cupo preaprobado de «Nueva solicitud» a `requestPreapproval()` en el servicio. El JS muestra el cupo de
  reparaciones cuando se elige esa destinación.
- Reparaciones: `isRepair()` con `OwnerCreditSettings::REPAIR_PURPOSE` («Reparaciones y mantenimiento»; los créditos de
  cotización ya la traen). Contrato del inmueble al 100 % y sin otros contratos; el plazo sale solo de ese contrato
  (`syncFromQuote`, `assertRequestTerm`).
- Reparaciones vigentes en el cupo de cada copropietario: `repairCreditsOnContracts()` suma saldo y cuota ×
  participación en `workspaceFor` y `outstandingByDocument`.
- `contractsByOwnerDocument`: encontrar también a los copropietarios por `id_propietario_2..6`, emparejando por `_ID`
  de la ficha y por documento normalizado (hoy toma siempre al dueño 1).
- Liquidador: política nueva «Porcentaje de participación registrado», tiles «Participación X %» e «Ingreso
  atribuible», y los textos del PDF interno.
- Al decidir, congelar `share` en `evaluation_json`.

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

### 5. Fase 8: otros módulos (por prioridad)

1. `HandlesMaintenanceActions::maintenance_quote_owner_contact`: la cotización de reparaciones a todos los
   copropietarios.
2. `RequesterLookupConcern` y `PanelTicketCreationConcern`: buscar también por `id_propietario_2..6`.
3. `AdministrativeNotificationsService` (`contract_actor_column`): incluir a los copropietarios.
4. Vistas del contrato: mostrar «+N copropietarios».
5. Aumento de canon, anexos, terminación y cartera siguen avisando solo al propietario 1. Queda como decisión de negocio.

## Verificación por fase

- Panel: `php tests/owner-credit-flow-check.php`, `owner-credit-check.php`, `owner-credit-coverage-check.php`,
  `owner-credit-prepayment-check.php`, `contract-co-owners-check.php` y la nueva `lease-contract-creation-check.php`.
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
3. Empezar por la Fase 5. Si hace falta una columna CCT nueva, usar `bin/cct-add-fields.php`, nunca `ALTER TABLE`.
