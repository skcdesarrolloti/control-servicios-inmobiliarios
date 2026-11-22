# Popups y avisos

Se configura en **Configuración › Popups y avisos**. Es la pestaña de mismo nombre de Permisos por cargo, abierta sola.

## Qué popup ve cada cargo

| Popup | Sale si |
|---|---|
| Vencimientos administrativos (general) | La casilla está marcada y el cargo atiende algún tipo de vencimiento («Vencimientos que atiende»). |
| Popups por tema | La casilla está marcada y el cargo atiende ese tema. Si el popup general sale en ese mismo momento (Inicio o la misma pestaña), no se repiten. |
| Créditos a propietarios | La casilla está marcada y el cargo ve Créditos a propietarios. Sin estas casillas, los créditos salen dentro del popup general. |
| Contratos por desocupar | La casilla está marcada y hay casos de recibo abiertos en el mes. |

«También mostrar en» agrega pestañas donde el popup sale al entrar.

## Cómo salen (por cargo)

- **Qué muestran:** lo asignado a la persona más lo del cargo sin responsable, o todo lo del cargo. Los conteos siempre
  son del cargo.
- **Cada cuánto:** al entrar a Inicio y cada 1, 2 o 4 horas; solo al entrar a Inicio; o una vez al día.
- **Al cerrar:** con confirmación, directo, o directo con «No mostrar más hoy» (se guarda en ese navegador).
- **Solo en horario laboral:** fuera del horario general no sale ningún popup automático.
- **Recordatorio diario incluye:** lo asignado (más lo sin responsable) o todo lo del cargo.

Un cargo sin guardar se comporta como antes: lista lo asignado, cada hora, con confirmación al cerrar.

## Horarios generales

- Horario laboral (desde, hasta y días), para los cargos con «Solo en horario laboral».
- Recordatorio diario por correo o WhatsApp: hora y días. Nunca sale en festivo y, si el proceso estuvo caído, sale como
  máximo tres horas después de la hora elegida. Incluye también preventivas y servicios públicos pendientes.

## Piezas

- `src/Support/DashboardPopupSettings.php`: valores por defecto, saneo, horario del recordatorio y alcance de las listas.
- Ajustes: `dashboard_popup_behavior` (por cargo) y `dashboard_popup_general`.
- `tests/dashboard-popup-settings-check.php`.
