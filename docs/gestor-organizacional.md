# Gestor organizacional

Reemplaza el plugin de WordPress **Gestor organizacional** (shortcode `[gestor_organizacional]`). Está en el panel en
**Actividades contractuales › Gestor organizacional** (`index.php?tab=gestor_organizacional`, panel
`scm-panel-gestor-organizacional`).

Código:

| Pieza | Archivo |
| --- | --- |
| Acciones AJAX (`scm_org_*`) y permisos | `src/App/Concerns/HandlesOrganizationManager.php` (rutas en `src/Http/Api/AuthenticatedActionRouter.php`) |
| Lectura y escritura de los CCT, Mi gestión, bitácora | `src/Modules/OrganizationManager/OrganizationRepository.php`, `OrganizationActivityLog.php` |
| Control documental de políticas (versiones y lecturas) | `src/Modules/OrganizationManager/OrganizationPolicies.php` |
| Traslados | `src/Modules/OrganizationManager/OrganizationTransfers.php` |
| PDF | `src/Modules/OrganizationManager/OrganizationPdf.php` + `public/assets/css/organization-document.css` |
| Migración de políticas de proveedores | `src/Modules/OrganizationManager/OrganizationPolicyMigration.php` + `bin/migrate-policies-provider.php` |
| Pantalla | `OrganizationManagerView.php` (estructura), `public/assets/js/organization-manager.js`, `public/assets/css/organization-manager.css` |
| Pruebas | `tests/organization-manager-check.php` (SQLite), `tests/organization-manager-ui-check.cjs` (Edge) |

## Pestañas

| Pestaña | Qué hace | Quién la ve |
| --- | --- | --- |
| **Mi gestión** | El cargo del funcionario y su perfil, **Mi equipo** (los cargos de `cargos_a_cargo`, que se cargan al abrirlos), mis políticas (con **Leí y entiendo**), mis funciones, los procesos de las áreas del cargo y mis compensaciones, agrupados por área y con buscador y filtro de área por sección. **Descargar gestión completa** genera el PDF. | Todo funcionario |
| **Gestión por cargo** | Lo mismo para un cargo elegido, con su PDF. | Su propio cargo y los que tiene a cargo; cualquier cargo con *org_manager_view* |
| **Áreas, Cargos, Políticas, Procesos, Funciones, Compensaciones, Funcionarios** | Listados con los filtros del plugin: título y detalle (con espera al escribir), área, cargo y activo; en políticas también estado, audiencia y «sin área». Crear, editar y eliminar en popups. Cargos tiene **Perfil**. | *org_manager_view* |
| **Traslados** | Pasa inmuebles (con su postmeta), precaptaciones, retoques y tickets de un funcionario a otro. | *org_manager_transfer* |
| **Bitácora** | Quién creó, editó, eliminó o desactivó cada registro. | *org_manager_view* |

La opción del menú no se configura por cargo: la tiene todo funcionario porque Mi gestión es de todos
(`SuCasaControlServiciosInmobiliarios::ORG_MANAGER_TAB`). Lo demás depende de los permisos por acción.

## Permisos

Reemplazan la opción de WordPress `gpo_full_access_cargo_ids` (en producción: cargos 13, 11, 12, 14 y 7) y la pestaña
**Capacidades** del plugin. Se asignan en **Configuración › Permisos por cargo**, grupo **Gestor organizacional**:

| Acción | Permite |
| --- | --- |
| `org_manager_view` | Ver las pestañas de registros, la bitácora, la gestión de cualquier cargo, el historial de versiones y quién confirmó la lectura de cada política. |
| `org_manager_manage` | Crear y editar registros y publicar nuevas versiones de políticas. |
| `org_manager_delete` | Eliminar registros y desactivar funcionarios. |
| `org_manager_transfer` | Trasladar registros entre funcionarios. |
| `org_manager_export` | Exportar las políticas a PDF. |

Las cinco son sensibles (`SENSITIVE_DEFAULT_ACTIONS`): mientras no se guarden los permisos con estas opciones solo las
tienen los cargos administradores (11, 12, 13 y 14). **El cargo 7 (Coordinador contractual) las tenía en el plugin:
si debe conservarlas, hay que marcarlas en Permisos por cargo.** Los botones se ocultan además con el mapa de
selectores del runtime (`data-om-manage`, `data-om-delete`, `data-om-transfer`, `data-om-export`).

## Eliminar y desactivar

- Áreas, cargos, políticas, procesos, funciones y compensaciones se eliminan como en el plugin (borrado físico), pero
  antes un popup nombra el registro y avisa qué le sigue apuntando (por ejemplo, «3 funcionarios», «reglas de la lista
  de chequeo de la hoja de cierre» o «versiones para proveedores»).
- Cada eliminación queda en `wp_gpo_actividad` con quién la hizo y una copia del registro (`detalle`).
- Un funcionario **no se elimina**: se desactiva (`activo = No`). Así no entra al panel ni aparece en los selectores de
  funcionarios activos; se reactiva editándolo. Nadie puede desactivarse a sí mismo.

## Usuario de WordPress (pendiente)

El plugin, al crear o editar un funcionario, creaba o actualizaba su usuario de WordPress (`wp_insert_user`,
`wp_update_user`, contraseña inicial = celular, rol según el cargo y unos 25 metas como `activo-empleado`,
`id-cargo`, `sucursal-user`, `id-comercial`). El panel **no corre WordPress** (las funciones `wp_*` son atajos en
`src/Core/Helpers.php`) y no tiene una forma segura de crear usuarios ni de cifrar contraseñas como WordPress, así que
por ahora:

- Se guarda solo la ficha del CCT `wp_jet_cct_funcionarios`, y el funcionario del cargo comercial (1) queda como
  comercial de su sucursal (`id_comercial`, `correo_comercial`), como hacía el plugin.
- **Crear** un funcionario lo enlaza con un usuario de WordPress que ya exista (por su ID o por el mismo correo). Si no
  existe, se pide crearlo primero en WordPress.
- Editar o desactivar no toca los metas de WordPress; el formulario lo advierte. El rol de WordPress se muestra solo
  como lectura.

Para completarlo se propone un endpoint en WordPress (mu-plugin, como `wordpress/mu-plugins/skc-copropietarios.php`)
que reciba del panel una petición firmada con HMAC y ejecute `wp_insert_user` / `wp_update_user` / `update_user_meta`
con la misma lógica del plugin; el panel lo llamaría después de guardar el CCT.

## Políticas: control documental

Columnas nuevas en `wp_jet_cct_politicas` (sin pestaña, como los demás campos del CCT):

| Columna | Tipo | Uso |
| --- | --- | --- |
| `codigo` | texto | `POL-<abreviatura del área>-<NNN>`. Se sugiere al elegir el área (botón **Sugerir**) y no se repite. |
| `version` | texto | Empieza en 1. Solo la sube **Nueva versión**. |
| `estado` | texto | Borrador, Vigente o Derogada. Borrador y Derogada no aparecen en Mi gestión. |
| `audiencia` | texto | Empleados, Proveedores o Ambos. |
| `id_politica_base` | texto | `_ID` de la política de empleados de la que sale la versión para proveedores. |
| `id_responsable` | texto | `id_empleado` del responsable. |
| `vigencia_desde`, `fecha_revision`, `fecha_proxima_revision` | fecha (timestamp) | La próxima revisión no puede ser anterior a la revisión. |
| `motivo_cambio` | área de texto | Motivo de la última versión. |
| `categoria`, `id_proceso` | texto | Opcionales. |

Abreviaturas por área (se calculan por `_ID`: iniciales sin conectores, o tres letras si es una palabra; si se repite se
agregan letras): MAR (1), FCA (2), CON (3), OFR (4), SPU (5), CPR (6), CCA (7), CPRO (8), CPROM (9), CAC (10), CCO (11),
TAR (12), TES (13), PDE (14), CONT (15), FCAP (16); sin área, GEN.

- **Título y texto son obligatorios.** El texto se escribe con el editor (Quill) y se guarda como HTML con lista blanca
  (`p`, `br`, `strong`, `em`, `u`, listas, `h2`–`h4`, citas y enlaces http/https/mailto). Los textos actuales, que son
  texto plano, se muestran como párrafos escapados. Hay **Vista previa** antes de guardar.
- Al crear se pone `fecha`; en cada guardado, `fecha_actualizacion`.
- El listado muestra un extracto y las insignias de código, versión, estado y audiencia; el texto completo se abre en un
  popup (**Ver política**).
- **Historial** (`wp_gpo_politica_versiones`): cada guardado deja una copia (versión, título, texto, campos de control,
  autor y motivo). Una edición normal conserva la versión (correcciones); **Nueva versión** la sube y exige el motivo.
- **Lectura** (`wp_gpo_politica_lecturas`): en Mi gestión cada funcionario confirma **Leí y entiendo** la versión
  vigente de las políticas de su cargo. Una nueva versión pide confirmarla otra vez. El botón **Lecturas** muestra quién
  confirmó y quién falta (funcionarios activos con un cargo de la política).
- Los `_ID` nunca cambian: la hoja de cierre referencia políticas por `_ID` (`ClosingSheetPolicies`), y su catálogo
  ahora también trae `code` y `audience` cuando existen las columnas.

Todo se lee y escribe con `SchemaInspector`, así que el módulo funciona antes de crear las columnas (el formulario solo
muestra los campos que existen y **Nueva versión** pide aplicar primero las columnas).

## Tablas propias

Se crean solas la primera vez que se usan (`CREATE TABLE IF NOT EXISTS`, fuera de las transacciones):

| Tabla | Contenido |
| --- | --- |
| `wp_gpo_actividad` | Bitácora: `tipo`, `registro_id`, `accion` (crear, editar, nueva_version, eliminar, desactivar, migracion), `resumen`, `detalle` (JSON), `id_empleado`, `nombre`, `fecha`. |
| `wp_gpo_politica_versiones` | Historial: `id_politica`, `version`, `accion`, `titulo`, `texto`, `datos` (JSON), `motivo`, `id_empleado`, `nombre`, `created_at`. |
| `wp_gpo_politica_lecturas` | Lecturas: `id_politica`, `version`, `id_empleado`, `nombre`, `confirmed_at` (única por política, versión y funcionario). |
| `wp_gpo_transfer_log` | La misma tabla del plugin (ya existe con su historial); el panel sigue escribiendo ahí. `user_id` es el `id_empleado` de quien trasladó. |

`cct_author_id` de los registros nuevos es el `id_empleado` real de quien los crea.

## Traslados

Igual que el plugin: origen y destino (con buscador), módulo (Inmuebles, Precaptación, Retoques, Tickets), tipo de
negocio para inmuebles, **todos los filtrados** o **solo los seleccionados**, motivo y **Previsualizar** (hasta 300
registros). **Ejecutar** pide confirmación con el número de registros y los nombres, y hace todo en una transacción
(si algo falla no se mueve nada). En inmuebles actualiza también los metas del post (`id_empleado`, `empleado`,
`email-empleado`, `celular-empleado`) y su autor si el destino es usuario de WordPress. No cambia `estado` ni
`estado_administrativo` de los tickets. El destino debe estar activo.

## PDF

Con el renderizador del servidor (`HtmlPdfRenderer`, Chromium o Gotenberg), en un popup con botón **Descargar**:

- **Exportar políticas PDF** (pestaña Políticas, con los filtros aplicados): agrupadas por su primera área, en orden
  alfabético, con código, versión, estado, audiencia, texto y cargos.
- **Reporte integral de gestión** (Mi gestión y Gestión por cargo): cargo y perfil, políticas, funciones, procesos y
  compensaciones.

Encabezado «SKC SuCasa Inmobiliaria», sin sucursal y con «Generado por … el …» al final.

## Aplicar en producción

1. Ver el plan de columnas (no escribe):

   ```bash
   php bin/add-policy-control-fields.php
   ```

2. Crear las columnas y sus campos en JetEngine (guarda respaldo en `storage/data/cct-backups/`):

   ```bash
   php bin/add-policy-control-fields.php --aplicar
   ```

   Revisar en WordPress › JetEngine › Custom Content Types › Políticas que no aparezca el aviso de esquema.

3. Ver el plan de la migración de políticas (muestra cada cambio, no escribe):

   ```bash
   php bin/migrate-policies-provider.php
   ```

4. Aplicarla (una transacción; también deja la versión 1 de cada política en el historial):

   ```bash
   php bin/migrate-policies-provider.php --aplicar
   ```

5. Asignar a mano, desde el panel, la política base de las versiones para proveedores que el informe lista sin base, y
   revisar los títulos repetidos que reporta.
6. En **Configuración › Permisos por cargo**, marcar las acciones del grupo **Gestor organizacional** a los cargos que
   correspondan (por ejemplo, el cargo 7 si debe seguir administrando).
7. Retirar el shortcode `[gestor_organizacional]` de WordPress cuando el equipo use el panel.

Los dos scripts se pueden repetir: solo agregan lo que falta.

`php bin/cct-add-fields.php politicas` lista las pestañas del CCT (no tiene); para agregar otro campo suelto en un CCT
sin pestañas, usar `-` como pestaña: `php bin/cct-add-fields.php politicas - columna="Título"`.
