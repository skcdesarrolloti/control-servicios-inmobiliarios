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

## Páginas

- `hoja-cierre-ver.php?numero=<hoja>`: la hoja como documento. Reemplaza la vista de WordPress `/hoja-de-cierre/` y contiene:
  - membrete con el NIT de la sucursal;
  - resumen del negocio y datos del estudio y entrega (aseguradora, solicitud, posible entrega, llaves);
  - las secciones de la hoja, los copropietarios y la revisión de servicios con la fecha de revisión y de corte;
  - la lista de chequeo con su conteo;
  - el cierre "Realizado por": quién elaboró, verificó y autorizó, con fecha y la imagen de `firma` de Funcionarios si existe;
  - el pie con los datos de la empresa.

  Desde ahí se aprueba o no, se hace la revisión, se edita y se imprime. Se imprime en A4 sin botones ni formularios.
- `revision-servicios-cierre.php?numero=<hoja>`: revisión de servicios antes de la ocupación. Por cada servicio pide NIC, póliza o contrato; medidor; fecha de corte; resultado en tiempo (glosario 998) y resultado en valores.
- `hojas-cierre.php`: listado por estado con búsqueda. También se abre desde Contratos de arrendamiento › **Hojas de cierre**.

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

## Configuración

En **Configuración › Hoja de cierre** (permiso *Configurar avisos de la hoja de cierre*) se activan:

- el correo al propietario (activo por defecto);
- el WhatsApp al propietario;
- el WhatsApp a funcionarios.

Las plantillas están en `docs/crear-plantilla-whatsapp-hoja-cierre.md`.

Permisos nuevos, en el grupo Contratos de arrendamiento:

- *Hacer la revisión de servicios públicos antes de la ocupación*: todos los cargos.
- *Aprobar o no aprobar hojas de cierre*: solo cargos administradores por defecto.

Las columnas `observacion_respuesta` e `id_revision_servicios_publicos` de `cierres` se crearon con `bin/cct-add-fields.php`.

## Dónde se usa

- **Añadir**: `hoja-cierre.php?id_contrato=<mandato>&id_arrendatario=…&id_inmueble=…&id_inmueble_data=…&id_propietario=…&id_copropiedad=…&id_estudio_aseguradora=…`
  Son los mismos parámetros del enlace de WordPress (`/mi-cuenta/anadir-hoja-de-cierre/?…`); `id_empleado` e
  `id_sucursal` se ignoran (el funcionario es el de la sesión). Solo `id_contrato` es obligatorio: lo que falte se toma
  del mandato.
- **Editar**: `hoja-cierre.php?id_cierre=<hoja>`.
- **Sesión**: la del panel. Desde WordPress el botón puede agregar `&id_empleado=…&token=…` (mismo token de
  `ActaAutologin` que usan la revisión preventiva y las cotizaciones) para entrar sin pedir usuario; sin token pide
  iniciar sesión en el panel y vuelve a la hoja.
- **Permisos**: *Añadir hoja de cierre de negocio* (`closing_sheet_create`) y *Editar hoja de cierre de negocio*
  (`closing_sheet_edit`), grupo Contratos de arrendamiento.

## Pasos

1. **Datos del negocio**: negocio (canon, administración, IVA, meses, comisión e incremento vienen del mandato y no se
   editan), servicios, propiedad horizontal y copropiedad, horarios, parqueadero y depósito, propietario (tipo de
   persona, documento y representante legal si es empresa), copropietarios con su % (deben sumar 100), arrendatario
   (igual que el propietario), mascotas/vehículos/personas, codeudores, acuerdos y referido PPH.
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
  persona y representante, porcentajes e ids de los copropietarios). Lo oculto no borra lo del mandato.
- Copropietarios sin ficha se registran en `jet_cct_propietarios`.
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

