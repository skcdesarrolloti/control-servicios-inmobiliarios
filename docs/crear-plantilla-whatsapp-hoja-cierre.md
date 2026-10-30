# Plantillas de WhatsApp de la hoja de cierre

Son cuatro plantillas: tres para funcionarios y una para el propietario. El panel envía estos WhatsApp siempre, junto con los correos. Mientras Meta no apruebe una plantilla, sus mensajes fallan en la cola; los correos salen igual. Por eso conviene crearlas antes de usar la hoja de cierre nativa.

Los funcionarios se eligen en **Configuración › Notificaciones internas**, en tres eventos:

| Evento | Quién lo recibe | Plantilla |
| --- | --- | --- |
| Hoja de cierre creada | Quienes hacen la revisión de servicios | `scm_hoja_cierre_revision_servicios_v1` |
| Hoja de cierre por responder | Quienes aprueban o no la hoja | `scm_hoja_cierre_por_responder_v1` |
| Hoja de cierre respondida | Quienes deben enterarse del resultado. Quien hizo la hoja lo recibe siempre. | `scm_hoja_cierre_respuesta_v1` |

El celular de cada funcionario es el `celular` de su ficha en Funcionarios. El del propietario es el celular de la hoja de cierre (y el de cada copropietario).

## Cómo crear cada plantilla en Meta

1. En Meta Business Suite abre **Administrador de WhatsApp → Plantillas de mensajes → Crear plantilla**. Usa la misma cuenta de WhatsApp que usa el panel.
2. Categoría: **Utilidad**.
3. Nombre: el nombre exacto de la plantilla, por ejemplo `scm_hoja_cierre_revision_servicios_v1`.
4. Idioma: **Español (COL)**, no English (el panel envía `es_CO`).
5. Tipo de variables: **Número** (`{{1}}`, `{{2}}`…).
6. Encabezado: ninguno.
7. Cuerpo: copia el texto de abajo tal cual, con sus saltos de línea. Meta pide un ejemplo para cada variable: usa los de la tabla.
8. Pie de página: `SKC SuCasa Inmobiliaria`.
9. Botón: **Visitar sitio web**.
   - Texto: el indicado en cada plantilla.
   - Tipo de URL: **Dinámica**.
   - URL: `https://sucasainmobiliaria.com.co/{{1}}`.
   - Ejemplo: el indicado en cada plantilla.
10. Enviar a revisión. Cuando Meta la apruebe, el panel la empieza a usar sola.

No le agregues frases comerciales, porque Meta la reclasificaría como marketing. El cuerpo no puede terminar en una variable, por eso cada uno cierra con una frase fija.

Las definiciones para la API están en `docs/whatsapp-hoja-cierre-*-template.json` y `docs/whatsapp-revision-servicios-entrega-propietario-template.json`.

## 1. Revisión de servicios pendiente (funcionarios)

Se envía a los funcionarios de **Hoja de cierre creada**. El botón abre la revisión de servicios antes de la ocupación con el usuario de ese funcionario. No necesita iniciar sesión y el enlace vence en 30 días.

- Nombre: `scm_hoja_cierre_revision_servicios_v1`.
- Botón: `Hacer revisión`.
- Ejemplo de la URL: `control-servicios-inmobiliarios/public/revision-servicios-cierre.php?numero=430&p=revision&f=2871&expires=1795900000&sig=0a1b2c`.

```text
Hola, {{1}}. Se creó la hoja de cierre {{2}} y sigue la revisión de servicios públicos antes de la ocupación.

Inmueble: {{3}}
Arrendatario: {{4}}
Hizo la hoja: {{5}}

Realiza la revisión desde el botón. El enlace es personal y vence en 30 días.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Elsie Perez |
| 2 | Número de la hoja | #430 |
| 3 | Inmueble y dirección | Inmueble 151187 · Carrera 32B #80-624 Apto 308 |
| 4 | Arrendatario | Juan Alberto Palacio Henry |
| 5 | Quien hizo la hoja | Kellys Roqueme Camacho |

## 2. Hoja por responder (funcionarios)

Se envía a los funcionarios de **Hoja de cierre por responder** en dos casos:

- Cuando se registra la revisión de servicios.
- Cuando se corrige una hoja que no se aprobó.

El botón abre la hoja para aprobarla o no, sin iniciar sesión. El enlace vence en 30 días.

- Nombre: `scm_hoja_cierre_por_responder_v1`.
- Botón: `Responder hoja`.
- Ejemplo de la URL: `control-servicios-inmobiliarios/public/hoja-cierre-ver.php?numero=430&p=respuesta&f=1001&expires=1795900000&sig=0a1b2c`.

```text
Hola, {{1}}. La hoja de cierre {{2}} está lista para tu respuesta.

Inmueble: {{3}}
Último registro de: {{4}}
Detalle: {{5}}

Apruébala o no desde el botón. El enlace es personal y vence en 30 días.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Said Abuita |
| 2 | Número de la hoja | #430 |
| 3 | Inmueble y dirección | Inmueble 151187 · Carrera 32B #80-624 Apto 308 |
| 4 | Quien hizo la revisión o corrigió la hoja | Elsie Johana Perez Kelly |
| 5 | El resultado de la revisión, o "Hoja corregida después de no aprobarse" | Energía: Al día · Acueducto y alcantarillado: 30 días ($32.295) |

## 3. Respuesta de la hoja (funcionarios y quien la hizo)

Se envía a los funcionarios de **Hoja de cierre respondida** y a quien hizo la hoja. El botón abre la hoja en solo lectura y el enlace vence en 180 días. Si la hoja no se aprobó, quien la hizo la corrige desde el panel, con el botón del correo.

- Nombre: `scm_hoja_cierre_respuesta_v1`.
- Botón: `Ver hoja`.
- Ejemplo de la URL: `control-servicios-inmobiliarios/public/hoja-cierre-ver.php?numero=430&p=ver&f=1508&expires=1808000000&sig=0a1b2c`.

```text
Hola, {{1}}. La hoja de cierre {{2}} quedó {{3}}.

Inmueble: {{4}}
Respondió: {{5}}
Observación: {{6}}

Consulta la hoja desde el botón.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del funcionario | Kellys Roqueme |
| 2 | Número de la hoja | #430 |
| 3 | `aprobada` o `no aprobada` | no aprobada |
| 4 | Inmueble y dirección | Inmueble 151187 · Carrera 32B #80-624 Apto 308 |
| 5 | Quien respondió | Said Abuita Correa |
| 6 | Observación, o "Sin observación" | Falta el certificado de tradición. |

## 4. Revisión de servicios al propietario

Se envía al propietario y a cada copropietario con celular cuando se registra la revisión antes de la ocupación. El botón abre la revisión pública firmada, que vence en 180 días.

- Nombre: `scm_revision_servicios_entrega_propietario_v1`.
- Botón: `Ver revisión`.
- Ejemplo de la URL: `control-servicios-inmobiliarios/public/revision-servicios-publicos.php?numero=1047&expires=1808000000&sig=0a1b2c`.

```text
Hola, {{1}}.

En el proceso de entrega de su inmueble ubicado en {{2}} realizamos la revisión de los servicios públicos. Resultado: {{3}}.

Puede consultar el detalle desde el botón.

Atentamente,
{{4}}

Gracias por confiar en nosotros.
```

| Variable | Qué envía el panel | Ejemplo |
| --- | --- | --- |
| 1 | Nombre del propietario | Yesenis Morales |
| 2 | Dirección del inmueble | Carrera 32B #80-624 Apto 308 Torre 2 |
| 3 | Resultado por servicio | Energía: Al día · Acueducto y alcantarillado: 30 días ($32.295) |
| 4 | Firma de quien hizo la revisión: nombre - cargo - Cel. celular | Elsie Perez - Coordinadora de servicios - Cel. 3001112233 |
