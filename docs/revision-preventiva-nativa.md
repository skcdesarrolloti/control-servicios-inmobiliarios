# Revisión preventiva nativa

Reemplaza el formulario JetForm "Añadir revisión preventiva" y la página pública de WordPress.

## Dónde se usa

- **Panel**: en un caso preventivo, *Acciones del caso → Complementarias → Crear / Gestionar revisión preventiva*.
  Asistente por pasos: Datos → Estructura → Instalaciones → Espacios → Contrato → Daños → Cobro.
  Autoguardado en el navegador (incluidas las fotos), botón "Todo sin novedad" por sección y resumen antes de guardar.
- **Informe público**: `revision-preventiva.php?numero=ID`. Con sesión del panel abre directo; sin sesión exige el enlace
  firmado (`expires` + `sig`) que se envía en los correos.
- **Desde WordPress** (usuarios que aún entran por allá): cambiar el botón/enlace del formulario por
  `https://<panel>/puente-funcionario.php?accion=revision_preventiva&ticket_pk=<_ID del caso>`
  (también acepta `id_ticket`). Usa el mismo inicio de sesión del puente que la revisión correctiva
  (`id_empleado` + `token`), abre el caso y el asistente.

## Qué guarda (igual que JetForm)

- `jet_cct_revision_preventiva`: checklist serializada por repeater (`suelos`, `tuberias`, …), `encontro_danos`,
  `evaluacion_de_danos` (mismo formato que la correctiva), datos del contrato y coordinador contractual de la sucursal.
- Caso: `id_revision_preventiva`, `estado_rev_preventiva = Si`, `estado_administrativo = Inspeccionado`, `reportes + 1`.
- Contrato: `id_revision_preventiva`, `revisiones_preventivas + 1`, `ultima_revision_preventiva`; post del inmueble: `revisiones-preventivas`.
- Historial del caso y del inmueble.
- **Cobro**: `jet_cct_reportes_administrativos` categoría *Revision preventiva*, `valor_revision` = tarifa de
  `jet_cct_confi_sistema` (salario / días × porcentaje) y `transporte` elegido (0, base o doble). Al editar se ajusta el
  transporte si el cobro no fue exportado/pagado; si ya lo fue, no se puede eliminar la revisión.
- Correo al creador y a los funcionarios del evento **Notificaciones internas → Revisión preventiva creada**
  (reemplaza los correos fijos del formulario de WordPress).

Permiso propio: *Gestionar revisión preventiva* (`preventive_review_manage`), separado de *Gestionar revisión correctiva*.
El botón de la correctiva solo aparece en casos de mantenimiento y el de la preventiva solo en casos preventivos.
Hasta que se guarde la configuración de permisos por primera vez, los cargos con la correctiva heredan la preventiva.
