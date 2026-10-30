# Hoja de cierre nativa

Reemplaza los formularios JetForm "Añadir hoja de cierre de negocio" y "Editar hoja de cierre de negocio".

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
- Correos al crear: funcionarios del evento **Notificaciones internas → Hoja de cierre creada** y copia al creador.
  Editar no envía correos.
