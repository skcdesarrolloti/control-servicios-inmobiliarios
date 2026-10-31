# Hoja de cierre nativa

Reemplaza los formularios JetForm "Añadir hoja de cierre de negocio", "Editar hoja de cierre de negocio", "Añadir revision de servicios publicos antes de entregar el inmueble" y "Responder hoja de cierre de negocio", y la vista de WordPress `/hoja-de-cierre/`.

## Flujo

| Estado de la hoja | Qué pasa | Quién recibe el aviso |
| --- | --- | --- |
| En verificacion de servicios | Se creó la hoja (`hoja-cierre.php`). | Notificaciones internas › **Hoja de cierre creada**, con su enlace para hacer la revisión. Copia a quien la creó. |
| Esperando respuesta | Se registró la revisión de servicios antes de la ocupación (`revision-servicios-cierre.php`). | El propietario y los copropietarios (correo y, si está activo, WhatsApp). Notificaciones internas › **Hoja de cierre por responder**, con su enlace para responder. |
| Aprobada / No aprobada | Se respondió (`hoja-cierre-ver.php`), con observación obligatoria si no se aprueba. | Notificaciones internas › **Hoja de cierre respondida** y quien hizo la hoja. |
| Esperando respuesta (otra vez) | Se editó una hoja no aprobada. | **Hoja de cierre por responder**. |
| Realizada | Se creó el contrato desde "No asignado". | (sin cambios) |

- La revisión se puede repetir mientras la hoja no esté aprobada.
- La respuesta se puede cambiar mientras esté "No aprobada".
- Una hoja aprobada no admite cambios.

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

  Desde ahí se aprueba o no, se hace la revisión, se edita y se imprime. No lleva la barra superior del panel: el documento tiene su propio membrete. Se imprime en A4 sin botones ni formularios.
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

## Avisos

Salen siempre por correo y por WhatsApp, a quien tenga correo o celular:

- al propietario y a los copropietarios, con el resultado de la revisión de servicios;
- a los funcionarios de los tres eventos de Notificaciones internas;
- a quien hizo la hoja, con la respuesta.

No hay una configuración aparte: los funcionarios se eligen en **Notificaciones internas**. Las plantillas de WhatsApp están en `docs/crear-plantilla-whatsapp-hoja-cierre.md`. Mientras Meta no las apruebe, esos WhatsApp fallan en la cola y los correos salen igual.

Permisos nuevos, en el grupo Contratos de arrendamiento:

- *Hacer la revisión de servicios públicos antes de la ocupación*: todos los cargos.
- *Aprobar o no aprobar hojas de cierre*: solo cargos administradores por defecto.

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
  para la revisión) y copia al creador. Editar no envía correos, salvo que la hoja estuviera "No aprobada": vuelve a
  "Esperando respuesta" y se avisa a **Hoja de cierre por responder**.

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
