# Contratos de arrendamiento y envío de anexos

Menú: **Actividades contractuales › Contratos de arrendamiento** (permiso de pestaña `contratos_arrendamiento`).

## Listado

Pestañas por estado (Todos, Anexos pendientes, Por entregar, Entregado, Por recibir, Desistido, No asignado). "Anexos pendientes" muestra contratos `Entregado` con `se_envio` distinto de `Si`.

Acciones por contrato, todas dentro del panel:

- **Documentación**: partes, condiciones económicas, documentos y expedientes, y la vista de anexos de cada actor.
- **Ficha del inmueble**: datos de `jet_cct_inmuebles` (por `codigo` = `id_inmueble` del contrato) y del contrato.
- **Historial inmueble**: el mismo informe de *Historial inmueble* (`scm_property_history_report`).
- **Historial contrato**: registros de `jet_cct_historial_contrato_arrendamiento`.
- **Crear caso**: abre *Crear nuevo caso* con el contrato ya seleccionado.
- **Enviar / Reenviar anexos**: reemplaza el formulario JetForm "Enviar contrato de arrendamiento". Requiere la acción `contract_annexes_send`; hasta que se guarden los permisos, todos los cargos la conservan.

## Envío de anexos

1. Valida fechas, registro fotográfico, IDs de acta de entrega e inventario, el contrato de arrendamiento (PDF o Word, máx. 10 MB) y los datos de propietario, arrendatario y codeudores.
2. Genera un **acta de bienvenida por actor** (Chromium, mismo diseño que las actas de servicios públicos) con los funcionarios de la sucursal (`jet_cct_sucursales`).
3. Actualiza el contrato (`acta_*`, partes, fechas, `contrato_arrendamiento`, `se_envio = Si`; `asegurado = No` solo si estaba vacío). No cambia `estado`.
4. Registra el envío en el historial del contrato y del inmueble.
5. Encola por actor un correo (acta adjunta; arrendatario y codeudores también reciben el contrato de arrendamiento) y un WhatsApp con la plantilla `scm_anexos_contrato_arrendamiento_v1` (`docs/whatsapp-anexos-contrato-arrendamiento-template.json`).
6. Envía un resumen interno con todas las actas a quienes se configuren en **Notificaciones internas › Contratos › Envío de anexos del contrato de arrendamiento** (`anexos_contrato_arrendamiento`).

## Vista pública

`public/anexos-contrato.php?numero=&tipo=propietario|arrendatario|codeudor[&codeudor=N]&expires=&sig=` (HMAC, sin sesión). Cada actor ve solo sus anexos:

- Propietario: acta, registro fotográfico, inventario, acta de entrega y **contrato de mandato** (sin contrato de arrendamiento).
- Arrendatario y codeudores: acta, **contrato de arrendamiento**, registro fotográfico, inventario y acta de entrega (sin contrato de mandato).

Los documentos se previsualizan en la misma página; la carpeta del registro fotográfico (Google Drive) se abre aparte porque Drive no permite mostrarse embebido.
