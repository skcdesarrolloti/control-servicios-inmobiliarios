# Hoja de cierre nativa

Reemplaza los formularios JetForm "Añadir hoja de cierre de negocio", "Editar hoja de cierre de negocio", "Añadir revision de servicios publicos antes de entregar el inmueble" y "Responder hoja de cierre de negocio", y la vista de WordPress `/hoja-de-cierre/`.

## Flujo

| Estado de la hoja | Qué pasa | Quién recibe el aviso |
| --- | --- | --- |
| En verificacion de servicios | Se creó la hoja (`hoja-cierre.php`). | Notificaciones internas › **Hoja de cierre creada**, con su enlace para hacer la revisión. Copia a quien la creó. |
| Esperando respuesta | Se registró la revisión de servicios antes de la ocupación (`revision-servicios-cierre.php`). | El propietario y los copropietarios (correo y, si está activo, WhatsApp). Notificaciones internas › **Hoja de cierre por responder**, con su enlace para responder. |
| Aprobada / No aprobada | Se respondió (`hoja-cierre-ver.php`), con observación obligatoria si no se aprueba. | Notificaciones internas › **Hoja de cierre respondida** y quien hizo la hoja. |
| Esperando respuesta (otra vez) | Se editó una hoja ya respondida (aprobada o no aprobada). Hay que aprobarla de nuevo; la revisión de servicios se conserva. | **Hoja de cierre por responder**. |
| Realizada | Se creó el contrato desde "No asignado". | (sin cambios) |
| Desistida | Se desistió el negocio desde la hoja, o el contrato o el estudio ya estaban desistidos (ver **Negocio desistido**). | Notificaciones internas › **Negocio desistido** y quien hizo la hoja (solo al desistir desde la hoja). |

- La revisión se puede repetir mientras la hoja no esté aprobada.
- La respuesta se puede cambiar mientras esté "No aprobada".
- Editar una hoja que todavía no tiene revisión ("En verificacion de servicios") o que ya está "Esperando respuesta" no cambia su estado.
- Una hoja aprobada no admite otra revisión ni otra respuesta. Si se edita, vuelve a "Esperando respuesta" y hay que aprobarla de nuevo (el formulario lo advierte antes de guardar).
- Una hoja "Realizada" (ya tiene contrato) no muestra **Editar hoja**.
- Una hoja "Desistida" no se puede editar, revisar, aprobar ni desistir otra vez (también lo bloquea el servidor).

## Enlaces sin iniciar sesión

Cada aviso lleva un enlace firmado solo para ese funcionario, esa hoja y esa acción (`ClosingSheetAccess`: `p`, `f`, `expires`, `sig` con HMAC de `APP_SECRET`). La revisión y la respuesta vencen en 30 días; la vista, en 180.

Quien abre el enlace queda como autor de lo que guarda (`cct_author_id`, verificador o aprobador). Si cambia `?numero=`, el enlace deja de servir. Si el funcionario ya no está activo (`activo = Si`), el enlace no abre.

Con sesión del panel basta `?numero=` y se aplican los permisos del cargo.

## Desde WordPress (autologin)

La sesión de WordPress no sirve en el panel. Por eso, un botón de WordPress que abra cualquiera de estas páginas debe terminar en `&id_empleado=<ID_EMPLEADO>&token=<TOKEN>`. Es el mismo token de `ActaAutologin` que usan el puente, la revisión preventiva y las cotizaciones. Las páginas son:

- `hoja-cierre.php`
- `hoja-cierre-ver.php`
- `revision-servicios-cierre.php`
- `hojas-cierre.php`

Con el token, el panel inicia sesión con ese funcionario y recarga la página sin el token. Sin token, pide iniciar sesión y vuelve a la hoja.

En **Tipos de actas y enlaces › Generar enlace** se arma el enlace completo con estas acciones:

- *Editar hoja de cierre*
- *Ver o responder hoja de cierre*
- *Revisión de servicios de la hoja de cierre*

## Páginas

- `hoja-cierre-ver.php?numero=<hoja>`: la hoja como documento. Reemplaza la vista de WordPress `/hoja-de-cierre/` y contiene:
  - membrete con el NIT de la sucursal;
  - resumen del negocio y datos del estudio y entrega (aseguradora, solicitud, posible entrega, llaves);
  - las secciones de la hoja, los copropietarios y la revisión de servicios con la fecha de revisión y de corte;
  - la lista de chequeo con su conteo;
  - el cierre "Realizado por": quién elaboró, verificó y autorizó, con fecha y la imagen de `firma` de Funcionarios si existe;
  - el pie con los datos de la empresa.

  Desde ahí se aprueba o no, se hace la revisión, se edita y se imprime. El botón **Aprobar** / **No aprobar** no depende de `event.submitter` (Safari anterior a 15.4 no lo trae): se recuerda el botón pulsado. No lleva la barra superior del panel: el documento tiene su propio membrete. Se imprime en A4 sin botones ni formularios.
- `revision-servicios-cierre.php?numero=<hoja>`: revisión de servicios antes de la ocupación. Por cada servicio pide NIC, póliza o contrato; medidor; fecha de corte; resultado en tiempo (glosario 998) y resultado en valores.
- **Actividades Administrativas › Hojas de Cierre**, dentro del panel:
  - pestañas por estado con su cantidad y búsqueda;
  - abre en el primer estado con hojas pendientes;
  - **Ver**, **Revisar servicios** y **Responder** abren la página en una ventana del panel (`embed=1`, sin barra superior);
  - al guardar allí, el listado se recarga al cerrar la ventana.

  Es la pestaña `hojas_cierre` de Permisos. Mientras no se guarden de nuevo los permisos, la ven los cargos que ven *Contratos de arrendamiento* o *Revisiones de servicios públicos*.
- `hojas-cierre.php`: el mismo listado como página propia, para abrirlo con un enlace (por ejemplo desde WordPress con autologin). Dentro del panel se usa el menú.

## Qué guarda la revisión de servicios (en una transacción)

- `jet_cct_revisiones_servicios`: tipo "Antes de la ocupacion", con "Contrato sin entregar" e "Inmueble sin entregar". Guarda `id_cierre`, `id_contrato` (contrato de arrendamiento), `id_contrato_mandato` y los servicios revisados.
- La hoja: verificador, cuentas, medidores, fechas de corte y resultados. También `id_revision_servicios_publicos` y el estado "Esperando respuesta".
- El mandato: las cuentas (energía, agua, gas).
- El contrato de arrendamiento, si ya existe: fechas de corte y `ultima_revision_servicios`.
- El historial del inmueble y el contador `revisiones-servicios` del post del inmueble.

No genera actas PDF, igual que el formulario anterior. El propietario recibe el enlace firmado a `revision-servicios-publicos.php`.

## Qué guarda la respuesta

- La hoja: estado, `fecha_repuesta`, aprobador y `observacion_respuesta`.
- El mandato: `estado_hoja_cierre`.
- El historial del inmueble.

## Negocio desistido

`ClosingSheetDesist`. La hoja queda **Desistida** (opción agregada al glosario 1022 "Estado hoja de cierre", que usan
`estado` de `cierres` y `estado_hoja_cierre` del mandato), con `razon_desistido`, `fecha_desistido` y `desistido_por`
(columnas de la pestaña Administración, creadas con `bin/add-closing-sheet-desist.php`). En el listado tiene su pestaña.

**Desistir desde la hoja.** Botón **Desistir negocio** en `hoja-cierre-ver.php`, en los estados En verificación, Esperando
respuesta, No aprobada y Aprobada. Abre una ventana con el motivo (glosario 1463 "Razón de desistir"), una observación
opcional y una casilla de confirmación. Replica el JetForm de WordPress "Desistir negocio" (`?id_estudio=`):

| Qué | Cambio |
| --- | --- |
| Contrato "No asignado" (`id_contrato_arrendamiento` de la hoja o `id_contrato_mandato_arrendamiento` del estudio) | `estado = Desistido`, `razon_desistido`; historial del contrato |
| Inmueble (meta `estado` del post y CCT `inmuebles`) | `En borrador` |
| Estudio de aseguradora | `estado = Aprobado`, `estado_contrato = Desistido` y `razon_desistido`. Con *Cambio de inmueble*: `estado = Esperando respuesta` y `estado_contrato` vacío |
| Caso de entrega (`id_ticket_entrega` del estudio) | `estado = Cerrado`, `estado_administrativo = Desistido` (lo hace el formulario), `fecha_actualizacion`; historial del caso |
| Hoja y mandato | `Desistida`; el mandato solo si esa es su hoja actual (`id_hoja_cierre`) |
| Historial del inmueble | motivo y observación |

En lugar de los correos fijos del formulario se avisa por correo a Notificaciones internas › **Negocio desistido**
(`negocio_desistido`) y a quien hizo la hoja, con su enlace a la hoja y a la ficha del inmueble.

- Una hoja "Realizada" o cuyo contrato ya tiene número se desiste en Contratos de arrendamiento con **Marcar desistido**.
- Permiso *Desistir negocio desde la hoja de cierre* (`closing_sheet_desist`), grupo Contratos de arrendamiento: todos los
  cargos hasta que se guarden los permisos. Solo con sesión del panel; los enlaces firmados no lo permiten.

**Desistidos por fuera de la hoja.** El formulario de WordPress y **Marcar desistido** no tocan la hoja. `sync()` la pasa a
"Desistida" (`desistido_por` = "Automático: contrato desistido" o "Automático: estudio desistido", con el motivo del
contrato o del estudio):

- si la hoja tiene contrato, cuando el contrato está `Desistido`, aunque la hoja esté "Realizada" (contrato creado y
  luego desistido);
- si no tiene contrato, cuando el estudio tiene `estado_contrato = Desistido`. Con contrato manda el contrato: un estudio
  puede decir "Desistido" con el contrato entregado.

Corre al abrir el listado, al abrir la hoja y al marcar un contrato desistido en Contratos. Una hoja nueva no se liga a un
contrato del estudio que ya esté desistido (por ejemplo, después de un cambio de inmueble).

## Avisos

Salen siempre por correo y por WhatsApp, a quien tenga correo o celular:

- al propietario y a los copropietarios, con el resultado de la revisión de servicios;
- a los funcionarios de los tres eventos de Notificaciones internas;
- a quien hizo la hoja, con la respuesta.

No hay una configuración aparte: los funcionarios se eligen en **Notificaciones internas**. Las plantillas de WhatsApp están en `docs/crear-plantilla-whatsapp-hoja-cierre.md`. Mientras Meta no las apruebe, esos WhatsApp fallan en la cola y los correos salen igual.

Permisos nuevos, en el grupo Contratos de arrendamiento:

- *Hacer la revisión de servicios públicos antes de la ocupación*: todos los cargos.
- *Aprobar o no aprobar hojas de cierre*: solo cargos administradores por defecto.
- *Desistir negocio desde la hoja de cierre*: todos los cargos.

Las columnas `observacion_respuesta` e `id_revision_servicios_publicos` de `cierres` se crearon con `bin/cct-add-fields.php`.

## Dónde se usa

- **Añadir**: `hoja-cierre.php?id_contrato=<mandato>&id_inmueble=…&id_propietario=…&id_copropiedad=…&id_estudio_aseguradora=…`
  Son los parámetros del botón **Añadir cierre** de la tarjeta del mandato. Ese botón ya no pasa por la página
  intermedia de WordPress (`/mi-cuenta/inmueble-de-administracion/`), donde se elegía o se creaba el arrendatario:
  eso se hace ahora en la sección **Arrendatario** de la hoja.
  - El buscador encuentra al arrendatario por nombre, documento o celular. Al elegirlo se llenan sus datos y el
    `id_arrendatario`.
  - **Crear arrendatario** abre una ventana con los campos del JetForm "Añadir arrendatario" (hook
    `registro-arrendatario`). El documento se guarda sin puntos ni espacios.
    - Si el documento ya está registrado, no se crea otro ni se sobrescribe: se ofrece **Usar este arrendatario**.
      Sus datos se corrigen en los campos de la hoja, que al guardar actualiza la ficha.
    - El nuevo se registra con el `id_empleado` real como autor y recibe la bienvenida por correo (cola
      compartida). La firma es la del representante legal: el funcionario activo con el cargo 14, como en las actas
      de bienvenida (`LeaseAnnexesService::staff()`). Ya no se toma de la sucursal.
  - Si el enlace trae `id_arrendatario` (el de la página intermedia), la hoja abre con ese arrendatario elegido y se
    puede cambiar.
  - Al guardar, si no llegó el `id_arrendatario` (por ejemplo, un borrador recuperado), se busca por el documento del
    arrendatario. Sin ficha, pide elegirlo o crearlo.

  `id_empleado` e `id_sucursal` se ignoran (el funcionario es el de la sesión). Solo `id_contrato` es obligatorio: lo
  que falte se toma del mandato.
- **Editar**: `hoja-cierre.php?id_cierre=<hoja>`.
- **Sesión**: la del panel. Desde WordPress el botón puede agregar `&id_empleado=…&token=…` (mismo token de
  `ActaAutologin` que usan la revisión preventiva y las cotizaciones) para entrar sin pedir usuario; sin token pide
  iniciar sesión en el panel y vuelve a la hoja.
- **Permisos**: *Añadir hoja de cierre de negocio* (`closing_sheet_create`) y *Editar hoja de cierre de negocio*
  (`closing_sheet_edit`), grupo Contratos de arrendamiento.

## Pasos

1. **Datos del negocio**:
   - **Condiciones del negocio**: canon, administración, IVA, meses, comisión e incremento. Se precargan del mandato,
     se pueden cambiar y, al guardar, pasan también al mandato en su formato:
     - canon y administración en pesos, por ejemplo "1.200.000";
     - duración como "12 Meses";
     - administración sin valor: "0", o vacía si el mandato la tenía vacía;
     - IVA vacío cuando el canon no lo incluye.

     La hoja guarda "No aplica" en la administración vacía o en cero y en el IVA cuando el canon no lo lleva. El
     campo del IVA solo aparece si el canon incluye IVA.
   - **Gestión**: siempre "Arriendo", bloqueada (también al editar hojas viejas con "Arriendo/Venta").
   - Servicios, propiedad horizontal y copropiedad, horarios, parqueadero y depósito.
   - **Propietarios**: un bloque por persona (principal y cada copropietario del mandato) con tipo de persona,
     documento, nombre, correo, celular, representante legal si es empresa y su participación. Los % deben sumar 100.
     Los datos de los copropietarios llegan en `copropietario[N][…]` y son obligatorios como los del principal.
   - Arrendatario: igual que el propietario.
   - Mascotas, vehículos y personas.
   - Codeudores: un bloque por codeudor.
   - Acuerdos y referido PPH.
2. **Lista de chequeo**: estado de 52 ítems y observación opcional. Al editar se cargan los estados y observaciones
   guardados; en hojas anteriores a la lista (antes de la #82) los ítems vacíos no son obligatorios.
   Ver **Lista de chequeo: sugerencias y políticas**.

## Lista de chequeo: sugerencias y políticas

**Sugerencias.** No se oculta ningún ítem. Al pasar al paso 2, `closing-sheet.js` sugiere estados con los datos del
mandato (`ClosingSheetService::checklistFacts`) y lo escrito en el paso 1. Solo llena ítems vacíos o que siguen con una
sugerencia; lo que el funcionario elige no se toca. Si se vuelve al paso 1 y se cambia algo, las sugerencias que nadie
tocó se recalculan. Cada una dice de dónde sale ("Sugerido: …"). **Adjuntar** sigue activo en todos los ítems, y un
documento sugerido en «Cumple» sin archivo se marca de inmediato y entra en la lista de **Guardar sin esos archivos**
(evento `cs:checklist-change` hacia `closing-documents.js`).

| Ítem | Sugerencia |
| --- | --- |
| Facturas de agua, energía y gas | "No aplica" si el servicio no está marcado; "Cumple" si el mandato o la hoja tiene su número |
| Internet y teléfono fijo | "Cumple" o "No aplica" según el mandato (`NA`, `N/A` o vacío cuentan como que no tiene) |
| Copropiedad (10 ítems) | "No aplica" sin propiedad horizontal; con ella, administrador, correo y teléfono en "Cumple" si están en la hoja |
| Existencia y Cámara de Comercio | "No aplica" si todos los propietarios (o el arrendatario) son persona natural |
| Días de gracia | según el campo de la hoja |
| Amparo integral | `incluye_garantia_servicios` del mandato: Sí = "Cumple", No = "No cumple" |
| Certificación bancaria del propietario | "Cumple" si el mandato tiene la cuenta |
| Cobertura de 36 meses | el número de `meses_cobertura_garantia` del mandato |
| Término del contrato | `meses_contrato` de la hoja: 12 o más = "Cumple"; menos = "No cumple" (el mínimo es 12) |
| Asegurable | "Cumple" si la hoja tiene su estudio de aseguradora |

**Políticas.** `ClosingSheetPolicies`. Cada ítem puede tener políticas de `wp_jet_cct_politicas`, una regla escrita y los
estados que las incumplen ("No cumple" por defecto). Se configura en **Configuración › Configuración hoja de cierre**
(permiso `closing_sheet_policy_configure`, solo administradores por defecto; setting `closing_sheet_policy_rules`):

- pestaña **Políticas por ítem**. **Agregar política** (o tocar una política ya asignada) abre un popup encima de la
  configuración:
  - a la izquierda, la lista del catálogo, con búsqueda por número, código, título o texto y los filtros *Todas*,
    *En este ítem*, *Empleados* y *Proveedores*;
  - a la derecha, la política completa, con **Agregar a este ítem** o **Quitar de este ítem**. Enter agrega la que se
    está leyendo y las flechas cambian de política.
  - Con permiso del Gestor organizacional (`org_manager_manage`) también están **Editar** y **Nueva política**, y con
    `org_manager_view`, la ficha con su historial y lecturas. Usan el mismo formulario del Gestor
    (`window.scmOrgPolicies` en organization-manager.js). Al guardar, el catálogo se recarga
    (`scm_closing_policy_settings` con `mode=catalog`), y la política nueva queda agregada al ítem. Los cambios del
    ítem se guardan con **Guardar configuración**.
- pestaña **Archivos obligatorios** (ver más abajo).

Mientras no se guarde, rige `DEFAULT_RULES`, con las políticas para empleados. Las que dicen "(Prov.)" son la versión para
**proveedores externos** (cargos 8 y 17), no provisionales. El catálogo las marca "Proveedores", y marca también las que
no están vigentes (Borrador, Derogada):

- facturas de servicios: #47 y #45; amparo integral: #47, #23 y #45;
- servicios compartidos: #66; mora en servicios: #26 y #41; reparaciones: #41;
- pagos de administración: #25 y #15; asegurable: #7 y #19; cobertura: #19 con la regla de 36 meses;
- término del contrato: regla "El término mínimo del contrato de arrendamiento es de 12 meses" (no hay política en la
  tabla).

En el formulario (crear y editar), cada ítem con políticas o regla muestra **siempre** una guía debajo del estado.
La guía tiene las políticas (se leen completas en una ventana de la misma página), la regla escrita y en qué estados
hay que justificar (`ClosingSheetPageView::ruleGuide`). Si el ítem incumple, la guía se pone en rojo y aparece el recuadro
de justificación; su botón **Ver política** abre la misma ventana de lectura.

Al marcar a mano un estado que incumple, se abre una ventana en la misma página con la política y su texto:

- **Corregir selección** (o Escape) vuelve al estado anterior.
- **Continuar con la excepción** exige una justificación de al menos 10 caracteres.

Un incumplimiento sugerido no abre la ventana, pero muestra la casilla de justificación en rojo. El servidor también
exige la justificación. En hojas anteriores a la regla, un ítem que ya estaba en ese estado no la exige mientras no
cambie.

Las justificaciones se guardan en `excepciones_politicas` de `cierres` (JSON: ítem, estado, políticas, regla,
justificación, quién y cuándo; columna creada con `bin/cct-add-fields.php`). Para quien aprueba, en `hoja-cierre-ver.php`:

- al inicio de la hoja, un bloque rojo **Incumple N políticas** con cada ítem, la política (con su texto), la regla y la
  justificación;
- en la lista de chequeo, los ítems en rojo con su justificación;
- sobre los botones de responder, un aviso rojo;
- al aprobar, la confirmación lo repite.

El aviso de **Hoja de cierre por responder** también dice cuántas excepciones tiene.

**Archivos obligatorios.** Por defecto (`DEFAULT_REQUIRED_FILES`, setting `closing_sheet_required_documents`):

- son obligatorios: tradición, facturas de agua, energía y gas, autorización de mora, documentos del propietario
  (facultad, existencia, cámara, identidad, RUT, certificación bancaria), existencia, cámara e identidad del
  arrendatario y los codeudores, certificación bancaria y cuota de la copropiedad, y asegurable;
- son opcionales: escritura, predial, internet, teléfono, RUT del arrendatario y los codeudores, reglamento, manual,
  RUT y paz y salvo de la copropiedad.

Si un obligatorio queda en «Cumple» sin archivo, el ítem pide **Por qué no se adjunta ahora** (no se bloquea el
negocio). También lo exige el servidor, que revisa los archivos subidos al mandato (al crear) o a la hoja (al editar).
La razón se guarda en `excepciones_politicas` (`kind: archivo`) y quien aprueba la ve en rojo mientras el archivo
falte; al subirlo, deja de mostrarse. Los opcionales solo se avisan al guardar (**Guardar sin esos archivos**).
En hojas anteriores, un documento que ya estaba en «Cumple» no pide la razón mientras no cambie.

Los datos se leen de la base de datos (mandato, inmueble, copropiedad, propietario, arrendatario, estudio de
aseguradora y ticket); no se usa la API REST de JetEngine.

## Qué guarda (en una transacción)

- `jet_cct_cierres`: todos los campos, estados del checklist y observaciones en `ob_*` con el mismo HTML de JetForm,
  copropietarios (`propietarios`, `porcentaje_N`, `id_propietario_N`, nombre, documento, correo, celular, tipo de
  persona y representante), `estado = En verificacion de servicios` al crear.
- **Mandato**: `id_hoja_cierre` y `estado_hoja_cierre` al crear, y todo dato de la hoja que el mandato también tiene
  (dirección, servicios, copropiedad, parqueadero, depósito, días de gracia, propietario principal con su tipo de
  persona y representante, porcentajes e ids de los copropietarios, y los datos editados de cada copropietario en
  `nombre_N`, `documento_N`, `correo_N`, `celular_N`, `tipo_N`, `tipo_documento_N` y, si es empresa, `empresa_N` /
  `nit_N` para su representante). Lo oculto no borra lo del mandato.
- Copropietarios sin ficha se registran en `jet_cct_propietarios`, con su tipo de persona y representante.
- Como JetForm: historial del inmueble, copropiedad, ticket (`estado`, `estado_comercial`; no toca
  `estado_administrativo`) y su historial, CCT `inmuebles`, metas del post `inmuebles` y ficha del arrendatario (con
  tipo de persona y representante).
- Correos al crear: funcionarios del evento **Notificaciones internas → Hoja de cierre creada** (cada uno con su enlace
  para la revisión) y copia al creador. Editar no envía correos, salvo que la hoja estuviera "Aprobada" o "No aprobada": vuelve a
  "Esperando respuesta" y se avisa a **Hoja de cierre por responder** para aprobarla de nuevo.

## Borrador automático

La hoja de cierre (crear y editar) y la revisión de servicios guardan lo escrito en el navegador mientras se llena (`public/assets/js/form-draft.js`):

- **Cuándo guarda:** cada momento, incluidas las filas de mascotas, vehículos y personas, y los porcentajes.
- **Clave:** `scm:borrador:cierre:crear:<mandato>:<arrendatario>`, `cierre:editar:<hoja>` o `cierre:revision:<hoja>`.
- **Al volver a abrir:** sale un aviso para recuperarlo o descartarlo.
- **Cuándo se borra:** al guardar con éxito, o a los 7 días.
- **Al salir:** el navegador pregunta si hay cambios sin guardar.
- **Si la sesión vence:** el borrador queda guardado.
- **Qué no guarda:** ids, nonce, la firma del enlace ni los campos de solo lectura.
- **Dónde queda:** solo en ese navegador; no viaja a otro equipo.


## Documentos (carpeta del negocio)

Los documentos de la lista de chequeo se suben en el panel, en su almacenamiento propio con enlace firmado
(`file.php`), no en Google Drive. Tabla propia `scm_closing_documents` (la crea `ClosingSheetDocuments::ensureSchema()`).

- **Qué lleva archivo:** los ítems de `ClosingSheetForm::DOCUMENT_ITEMS`. Los ítems que son preguntas o datos no llevan
  archivo (amparo, medidor, reparaciones, días de gracia, datos del administrador, cobertura y término). «Asegurable» lleva
  el estudio de la aseguradora. Hay además **Documentos adicionales**, con nombre opcional.
- **Por propietario:** los documentos del propietario se suben por cada propietario (principal y copropietarios).
- **Formatos:** PDF, JPG, PNG, WebP, Word y Excel, hasta `UPLOAD_MAX_BYTES` (10 MB) por archivo. Las fotos se comprimen
  en el navegador (`scm-photo-compress.js`) y en el servidor (`StoredFileService::storeUploadedFile`). El mismo archivo
  dos veces en la misma casilla se rechaza.
- **Opcionales:** la hoja se guarda sin archivos. Un documento en «Cumple» sin archivo se marca en la casilla y, al
  guardar, se listan con la opción **Guardar sin esos archivos**.
- **Al crear:** la hoja aún no tiene número, así que los archivos quedan del mandato. Al guardar, la hoja se queda con
  ellos (`claim`).
- **Eliminar:** pide confirmación. No borra el archivo: guarda quién y cuándo, y se ve en la carpeta en **Eliminados**.
- **Vista de la hoja:** la lista de chequeo muestra los archivos de cada ítem. PDF e imágenes se ven en la misma página.
- **Documentos por actor:** en la hoja (formulario y vista) y en la carpeta, una barra con un botón por propietario,
  arrendatario, codeudor, inmueble, servicios públicos, copropiedad, aseguradora y adicionales. Cada botón dice cuántos
  archivos tiene y cuántos ítems en «Cumple» no tienen archivo, y abre un popup con solo esos documentos, su estado y,
  donde se puede editar, **Adjuntar** (`ClosingSheetDocumentsView::actorBar` + `closing-documents.js`).
- **Correo «Hoja de cierre respondida»:** lleva los archivos adjuntos hasta 15 MB, en el orden de la lista. Los que no
  caben se nombran en el correo y se ven en la hoja.
- **Carpeta del contrato:** `documentos-contrato.php?contrato=<id>` (o `?cierre=<hoja>`). Junta los archivos del
  contrato y los de sus hojas de cierre (`id_cierre` del contrato o `id_contrato_arrendamiento` de la hoja).
  - Se abre en Contratos de arrendamiento con **Carpeta de documentos** (en la fila y en *Documentación*).
  - En contratos viejos sin hoja nativa también se suben ahí.
  - El enlace queda en la columna `link_documentos` de `contratos_arrendamiento`. Se llena al crear el contrato desde
    «No asignado», al subir desde el contrato y al abrir la carpeta. La columna se crea con
    `php bin/cct-add-fields.php contratos_arrendamiento anexos_tab link_documentos="Carpeta de documentos (enlace)" --aplicar`.
- **Permisos:** ver la carpeta: quien ve *Contratos de arrendamiento* o puede añadir, editar, revisar o responder hojas.
  Subir y eliminar en la hoja: quien puede añadirla o editarla. En la carpeta del contrato: el permiso nuevo *Subir y
  eliminar documentos en la carpeta del contrato* (todos los cargos hasta que se guarden los permisos).
