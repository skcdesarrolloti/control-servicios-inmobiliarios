# Inventario del inmueble nativo

Reemplaza los JetForm "Añadir inventario" y "Editar inventario" y la página `inventario-inmueble/` de WordPress. Guarda en
el mismo CCT `inventario` con las mismas claves de fila, así que los inventarios antiguos se ven y se editan igual.

## Dónde se usa

- **Caso de entrega**: *Acciones del caso → Complementarias → Crear o gestionar inventario*.
- **Actividades administrativas → Proceso de entrega**: listados de *Revisiones antes de la ocupación* e *Inventarios*
  (ver y editar en popup).
- **Herramientas → Duplicar inventario**: reemplaza el plugin *SKC Mapeador de Inventario*. Muestra una vista previa y copia
  los elementos hacia el contrato de otro caso. No genera reporte administrativo.
- **Documento**: `inventario.php?numero=ID`. Con sesión del panel abre directo; sin sesión exige enlace firmado (`expires` + `sig`,
  cinco años, porque va en anexos y cartas).

## Varios inventarios por contrato

Un contrato puede entregar más de un inmueble. El primer inventario es el **principal** (`contrato.id_inventario`,
`ticket.id_inventario`) y sincroniza contrato, caso y ficha del inmueble. Los siguientes solo guardan su propio registro.
Todos los creados en el panel llevan `incluir_anexos = Si` y van en los anexos; los repetidos antiguos no se envían.
Al eliminar el principal, otro inventario vigente del contrato toma su lugar.

## Cobro

Solo en inventarios tipo Nuevo, máximo 2 reportes por caso, categoría `Revision antes de la ocupacion`.

- Tarifa: `salario / dias_trabajo × porcentaje_smlmv_entre_rec` de `confi_sistema`. Se calcula al guardar, así sigue al salario.
- Doble si es Amoblado o tiene 2 pisos o más (`cantidad_pisos`). Con las dos condiciones se duplica una sola vez.
- Transporte opcional: `valor_transporte × 2`.
- Al editar se recalcula; un reporte exportado o pagado no se toca.

## Autoguardado

`wp_scm_inventory_drafts`: una fila comprimida por funcionario, caso e inventario, más un respaldo. Guardar reemplaza la fila,
por eso no crece como `wp_skc_inventory_draft_versions` del plugin. Si ya hay borrador, el autoguardado se pausa hasta elegir
Recuperar o Empezar de nuevo. Si otro dispositivo guardó después, se pausa en lugar de sobrescribir.

El estado viaja como un solo JSON: un inventario amoblado supera `max_input_vars`.

## Formulario

- Secciones según el tipo de inmueble (`InventorySchema::profile`): cambian qué se muestra y cómo se llama, no las columnas.
- Sugerencias: glosarios más lo aprendido de los últimos 150 inventarios Nuevo (`storage/data/inventory-suggestions.json`, semanal).
- Dictado: requiere `MINIMAX_API_KEY` en el `.env` del servidor (la misma del asistente de crear caso). Con el plan de suscripción
  se usa `MiniMax-M3.1-Flash`; se cambia con `MINIMAX_INVENTORY_MODEL`. El navegador envía WAV porque MiniMax no acepta webm.
  El audio no se guarda y cada fila propuesta se acepta a mano. Si falla la transcripción, ofrece el dictado del navegador.

## Permisos

Acciones `inventory_manage` e `inventory_delete`. Pestañas `revisiones_ocupacion` e `inventarios_entrega` (hasta guardar permisos
las ve quien ve casos de entrega) y `herramienta_duplicar_inventario` (hay que activarla por cargo).

## Columnas nuevas

```bash
php bin/cct-add-fields.php inventario informacion_adicional_del_inmueble_tab cantidad_pisos="Cantidad de pisos" --aplicar
php bin/cct-add-fields.php inventario complementarios_tab incluir_anexos="Incluir en anexos del contrato" id_reporte_administrativo="Reporte administrativo" --aplicar
```

Pruebas: `php tests/inventory-public-check.php`, `php tests/inventory-flow-check.php` y `node tests/inventory-form-check.cjs`.
