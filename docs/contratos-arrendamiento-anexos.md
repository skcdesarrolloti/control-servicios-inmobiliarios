# Contratos de arrendamiento: creación y envío de anexos

Menú: **Actividades contractuales › Contratos de arrendamiento** (permiso de pestaña `contratos_arrendamiento`).

## Listado

Pestañas por estado (Todos, Anexos pendientes, Por entregar, Entregado, Por recibir, Desistido, No asignado). "Anexos pendientes" muestra contratos `Entregado` con `se_envio` distinto de `Si`.

Acciones por contrato, todas dentro del panel:

- **Documentación**: partes, condiciones económicas, documentos y expedientes, y la vista de anexos de cada actor.
- **Ficha del inmueble**: datos de `jet_cct_inmuebles` (por `codigo` = `id_inmueble` del contrato) y del contrato.
- **Historial inmueble**: el mismo informe de *Historial inmueble* (`scm_property_history_report`).
- **Historial contrato**: registros de `jet_cct_historial_contrato_arrendamiento`.
- **Crear caso**: abre *Crear nuevo caso* con el contrato ya seleccionado.
- **Marcar desistido**: pide motivo del glosario JetEngine "Razón de desistir" (id 1463), observación opcional y confirmación. Cambia `estado` a `Desistido` y guarda `razon_desistido`; registra el cambio en el historial del contrato y del inmueble y avisa a quienes se configuren en Notificaciones internas › Contratos › Contrato de arrendamiento desistido (`contrato_desistido`). Requiere la acción `contract_desist`.
- **Enviar / Reenviar anexos**: reemplaza el formulario JetForm "Enviar contrato de arrendamiento". Requiere la acción `contract_annexes_send`; hasta que se guarden los permisos, todos los cargos la conservan.

## Crear contrato (registros "No asignado")

Reemplaza el formulario JetForm "Crear contrato de arrendamiento". Se ofrece en los contratos con número `No asignado` (acción `contract_create`).

- Prellena desde la hoja de cierre (`jet_cct_cierres.id_contrato_arrendamiento`), el propietario, el arrendatario, la copropiedad, el estudio de aseguradora y el contrato. Usa los glosarios 783, 785, 784, 1003, 175, 1011, 1016, 992, 991, 990 y 853, y las zonas de `jet_cct_zonas_sociales`.
- Al guardar: asigna número e inmueble al contrato (estado Por entregar, tipo Nuevo, `se_envio = No`; `id_empleado`, `realizado_por` y `creador` solo si estaban vacíos), marca el cierre como `Realizada` y el mandato `estado_hoja_cierre = Realizada`, actualiza los casos de entrega y daños, la revisión de entrega y el inventario, crea los codeudores, conecta las relaciones JetEngine 864, 865, 658 y 863, actualiza los metadatos del post `inmuebles` y registra el historial del contrato y del inmueble.
- Con propiedad horizontal genera el **acta de notificación de ingreso** (arrendatario) y la **carta de presentación del nuevo inquilino** (copropiedad), las guarda en `acta_notificacion_arrendatario` / `acta_administracion`, crea el registro en `jet_cct_actas_administracion` y las envía por correo y WhatsApp (`scm_notificacion_ingreso_arrendatario_v1` y `scm_presentacion_inquilino_copropiedad_v1`).
- Aviso interno configurable: **Notificaciones internas › Contratos › Contrato de arrendamiento creado** (`contrato_arrendamiento_creado`).

## Envío de anexos

1. Valida fechas, registro fotográfico (con botón para abrir la carpeta), que el acta de entrega (`jet_cct_actas_de_entrega`) y el inventario (`jet_cct_inventario`) del contrato existan (se pueden ver desde el formulario; si falta alguno no se permite enviar), el contrato de arrendamiento (PDF o Word, máx. 10 MB) y los datos de propietario, arrendatario y codeudores.
2. Genera un **acta de bienvenida por actor** (Chromium, mismo diseño que las actas de servicios públicos) con la razón social de la sucursal y los funcionarios activos de `jet_cct_funcionarios` por `id_cargo`: contractual 7, servicio al propietario (pagos) 5, servicio al arrendatario 4, gerencia comercial 12 y gerencia general 14, que firma todas las actas con su firma cargada. Si no hay un funcionario activo con cargo 14 el envío se detiene.
3. Actualiza el contrato (`acta_*`, partes, fechas, `contrato_arrendamiento`, `se_envio = Si`; `asegurado = No` solo si estaba vacío). No cambia `estado`, `id_empleado`, `creador`, `cct_author_id` ni `cct_created` del contrato.
4. Registra el envío en el historial del contrato y del inmueble.
5. Encola por actor un correo (acta adjunta; arrendatario y codeudores también reciben el contrato de arrendamiento) y un WhatsApp con la plantilla `scm_anexos_contrato_arrendamiento_v1` (acta en el encabezado y botón "Ver anexos" con el enlace firmado) (`docs/whatsapp-anexos-contrato-arrendamiento-template.json`).
6. Envía un resumen interno con todas las actas a quienes se configuren en **Notificaciones internas › Contratos › Envío de anexos del contrato de arrendamiento** (`anexos_contrato_arrendamiento`).

## Vista pública

`public/anexos-contrato.php?numero=&tipo=propietario|arrendatario|codeudor[&codeudor=N]&expires=&sig=` (HMAC, sin sesión). Cada actor ve solo sus anexos:

- Propietario: acta, registro fotográfico, inventario, acta de entrega y **contrato de mandato** (sin contrato de arrendamiento).
- Arrendatario y codeudores: acta, **contrato de arrendamiento**, registro fotográfico, inventario y acta de entrega (sin contrato de mandato).

Los documentos se previsualizan en la misma página; la carpeta del registro fotográfico (Google Drive) se abre aparte porque Drive no permite mostrarse embebido.
