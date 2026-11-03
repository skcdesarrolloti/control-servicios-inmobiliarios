# Revisión antes de la ocupación nativa (revisión de entrega)

Reemplaza el formulario JetForm "Añadir revisión de entrega" y la página pública `revision-de-entrega/` de WordPress.
Guarda en el mismo CCT `revision_entrega`, así que las revisiones antiguas se ven y se editan igual.

## Dónde se usa

- **Panel**: en un caso de entrega de inmueble, *Acciones del caso → Complementarias → Crear / Gestionar revisión antes
  de la ocupación*. Desde ahí se crea, se ve el informe (popup), se **edita** y se **elimina** (pide escribir ELIMINAR y
  deja historial). Un caso tiene una sola revisión.
- **Página propia**: `revision.php?tipo=entrega&ticket_pk=<_ID del caso>` (mismo editor, sin cargar el panel).
- **Desde WordPress**: `https://<panel>/puente-funcionario.php?accion=revision_entrega&ticket_pk=<_ID del caso>`.
- **Informe**: `revision-entrega.php?numero=ID`. Con sesión del panel abre directo; sin sesión exige el enlace firmado
  (`expires` + `sig`, 180 días) que va en los correos. Botón Imprimir con `@media print` compacto, sin sucursal y con
  "Realizado por" al final. El registro fotográfico (carpeta de Drive) se abre dentro de la misma página.

Los enlaces de la app (historial del caso, línea de tiempo de Entrega, documentación del contrato) ya apuntan a la vista nativa.

## Formulario

Asistente por pasos con autoguardado en el navegador (también las filas agregadas): **Inmueble → Linderos → Espacios →
Servicios → Resumen**.

- Inmueble: **cantidad de pisos** (columna `piso`, numérica 1–10) y **habitaciones y baños por piso** (al crear salen
  de lo registrado en el inmueble, en el piso 1), parqueadero, depósito y enlace del registro fotográfico. El meta `piso`
  de WordPress no se usa ni se modifica (puede ser el piso donde queda el apartamento).
- Linderos: conjunto, torre, inmueble (obligatorio), parqueaderos y depósitos (solo si aplica).
- Espacios: los exteriores (conjunto, torre y fachada) se registran una vez; todo lo interior (habitaciones, baños,
  sala-comedor, cocina, área de labores, garaje y patio) se registra en un bloque por piso, y cada fila guarda su piso
  (`piso_cocina`, `piso_habitacion`, …; las revisiones antiguas sin piso quedan en el piso 1). Hay una ficha por
  habitación y por baño según la cantidad de cada piso. El **tipo** es una lista (habitación: Principal,
  Secundaria, Estudio, De servicio; baño: Principal, Social, Privado, Auxiliar, De servicio). Habitación y baño de
  servicio ya no son secciones aparte: se eligen en el tipo y se guardan en `hab_ser` / `bano_ser`. Cada fila guarda
  su piso (`piso_habitacion`, `piso_bano`, …). Los tipos escritos a mano en revisiones antiguas ("#1 o Principal",
  "#2") se muestran como la opción equivalente. Chips "qué contiene" del glosario, "Marcar todo" y "Sin novedad".
- Servicios: agua, gas y energía solo si están activos; observaciones vacías se guardan como "Ninguna".
- Se quitaron **acuerdo de pago** (`ac_pago_*`) y **resultado de la revisión** (`resultado`); las columnas siguen en el CCT
  con los datos antiguos. Sin resultado ya no se crea el caso automático de "Daños encontrados antes de la ocupación"
  ni sus correos fijos.

## Qué actualiza (igual que JetForm)

- Caso: `id_revision_entrega`, `estado_rev_entrega = Si`, `estado_rev_correctiva = Si`, `estado = En proceso`,
  `estado_administrativo = Inspeccionado` (al crear; editar no cambia estados).
- Contrato: revisión, registro fotográfico, servicios, parqueadero/depósito, linderos, póliza/medidor y lectura inicial.
  Un servicio inactivo no borra los datos del contrato.
- Inmueble (CCT `inmuebles` y post de WordPress): parqueadero, depósito, servicios, habitaciones, baños y piso.
- Historial del caso y del inmueble al crear, editar y eliminar.

## Notificaciones y permisos

- **Configuración → Notificaciones internas → Entregas de inmuebles**: *Revisión antes de la ocupación registrada*
  (también avisa a quien la registró) y *Revisión antes de la ocupación editada*. Correos en cola, sin destinatarios fijos.
- Permiso **Gestionar revisión antes de la ocupación (casos de entrega)** (`delivery_review_manage`). Hasta guardar la
  configuración de permisos lo tienen todos los cargos; sin el permiso solo se ve el informe.

## Columna nueva

`piso` en `revision_entrega` (pestaña *Informacion adicional del inmueble*), creada con
`php bin/cct-add-fields.php revision_entrega informacion_adicional_del_inmueble_tab piso="Piso" --aplicar`.

Pruebas: `php tests/delivery-review-check.php` y `node tests/delivery-review-wizard-check.cjs`.
