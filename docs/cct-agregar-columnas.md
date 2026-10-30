# Agregar columnas a un CCT de JetEngine

**Regla:** cuando un desarrollo necesite columnas nuevas en una tabla `wp_jet_cct_*`, se agregan con
`bin/cct-add-fields.php` (o un script que use `SCM\Support\CctFieldInstaller`). No se crean con `ALTER TABLE`
sueltos ni campo por campo a mano.

## Por qué

JetEngine guarda cada Custom Content Type en dos lugares:

1. **La definición** en `wp_jet_post_types` (`status = 'content-type'`, `slug = <cct>`), columna `meta_fields`:
   un arreglo PHP serializado con cada campo (`title`, `name`, `object_type`, `type`, `id`, `isNested`, …) y las
   pestañas (`object_type = accordion`).
2. **La tabla** `wp_jet_cct_<slug>`, con una columna por campo.

Si existe una columna sin campo en la definición (o al revés), JetEngine muestra en el CCT *"DB schema does not
correspond to CCT settings"*. Si alguien pulsa **Update Content Type** en ese estado, JetEngine puede borrar la
columna que no conoce, y con ella sus datos. Por eso una columna nunca se crea sola: siempre con su campo en la
definición. Así quedan de inmediato en JetEngine, en el *fields map* de JetForm y en el panel.

## Cómo

```bash
# 1. Ver las pestañas del CCT para elegir dónde va el campo
php bin/cct-add-fields.php contratos_arrendamiento

# 2. Ver el plan (no escribe nada)
php bin/cct-add-fields.php contratos_arrendamiento propietario_tab correo_2="Correo 2" fecha_firma:fecha="Fecha de firma"

# 3. Aplicar
php bin/cct-add-fields.php contratos_arrendamiento propietario_tab correo_2="Correo 2" fecha_firma:fecha="Fecha de firma" --aplicar
```

- Tipos: `text` (por defecto, columna `text`), `textarea` (columna `text`) y `fecha` (fecha guardada como timestamp,
  columna `bigint(20)`, como `fin_contrato`).
- Nombre de columna: minúsculas, números y `_`.
- Lo que ya existe se salta: el comando se puede repetir sin efecto. Un campo que ya está en su pestaña no se mueve; uno que está en otra pestaña se mueve al final de
  la indicada (solo cambia el orden de la definición, no la columna ni los datos).
- Nunca cambia ni borra campos o columnas existentes.
- Antes de escribir guarda la definición anterior en `storage/data/cct-backups/<slug>-<fecha>.json` (no va a git).
  Para deshacer: volver a poner ese `meta_fields` en `wp_jet_post_types` (las columnas nuevas quedan vacías y sin uso).

Para un grupo de campos que va a varias pestañas o a varios CCT, se escribe un script corto que llame a
`CctFieldInstaller::run($slug, [pestaña => [columna => [título, tipo]]], $aplicar)`. Ejemplo:
`bin/add-person-type-fields.php` (tipo de persona y representante legal en cierres y contratos de arrendamiento).

## Antes de aplicar en producción

1. Correr el plan (sin `--aplicar`) y revisar columnas, tipos y pestañas.
2. Si son muchos campos o un CCT crítico, probar primero sobre una copia local: copiar `SHOW CREATE TABLE` de la
   tabla y de `wp_jet_post_types` más la fila del CCT a un MySQL local, y correr el comando con `DB_HOST`,
   `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` apuntando a esa copia (las variables de entorno mandan sobre el `.env`).
3. Aplicar y revisar en WordPress › JetEngine › Custom Content Types que el CCT no muestre el aviso de esquema.
4. En el código del panel, leer y escribir esas columnas a través de `SchemaInspector::filterTableData()` /
   `columnExists()`, para que nada falle si la columna todavía no existe en otra instalación.
