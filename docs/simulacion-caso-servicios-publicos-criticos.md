# Simulación: revisión crítica → caso → vencimiento → cierre

Ejemplo ficticio del nuevo flujo de la versión **3.3.372**. Los números y nombres ilustran el proceso; no representan casos creados en producción.

## Datos del ejemplo

| Dato | Valor |
| --- | --- |
| Contrato | #2000 |
| Inmueble SIMI | 204578 |
| Arrendatario | Juan Pérez |
| Funcionario creador | Carlos Ramírez |
| Revisión | #123 |
| Caso generado | #456 |
| Registro | 09/10/2026, 10:00, Colombia |
| Vencimiento | 12/10/2026, 10:00, Colombia |
| Servicios críticos | Energía: $350.000 COP; agua: $50.000 COP |

## Recorrido

1. **Carlos guarda la revisión #123.** Marca energía y agua como críticas, con sus referencias, medidores y valores. El sistema genera las actas de ambos servicios.
2. **Se crea un solo caso #456.** Tema exacto: `Servicio publico critico`. Estado inicial: Nuevo. Prioridad urgente. Responsable inicial: Carlos. La descripción identifica la revisión, el contrato, los servicios, sus valores y el vencimiento.
3. **Queda registrado en el reporte del inmueble.** El historial identifica el caso #456, la revisión #123, el plazo y el funcionario real. El historial del caso registra su creación y sus actas.
4. **Se encolan los avisos.** Juan, Carlos y los funcionarios configurados reciben la revisión. WhatsApp adjunta cada acta crítica como PDF y muestra el botón **Ver revisión**. Con dos actas y tres destinatarios distintos, se preparan seis WhatsApp; no se crean seis casos. El botón abre la revisión pública firmada, sin nombre del propietario y sin formulario de pago.
5. **Aparece en vencimientos y popup.** El grupo es **Servicios críticos · 72 horas**. El acceso abre el caso #456 dentro del panel. A las personas seleccionadas se les agenda el recordatorio de las 10:00 del 12 de octubre; Google Calendar se solicita si tienen cuenta conectada.
6. **Se atiende dentro del caso.** El responsable registra contactos, respuestas y evidencias con las acciones normales del caso. Adjuntar archivos o responder no reinicia el plazo.
7. **Se comprueba el vencimiento.** Si el caso sigue abierto después de las 10:00 del 12/10/2026, se muestra vencido, incluso durante ese mismo día. No se cierra automáticamente al cumplir 72 horas.
8. **Se cierra al terminar la gestión.** Al finalizar/cerrar/anular el caso con el flujo normal, desaparece de vencimientos pendientes y del popup. El procesador solicita cancelar el recordatorio. El inmueble conserva el historial, la revisión y las actas.

## Comprobaciones ejecutadas

Las pruebas utilizan tablas temporales y destinatarios ficticios, con transportes simulados. No envían WhatsApp ni correo reales y no crean eventos reales de Google.

- Una revisión crítica crea un caso real en la tabla nativa de casos y registra ambos historiales.
- Dos servicios críticos dentro de la misma revisión generan un solo caso.
- Reenviar el formulario devuelve la misma revisión y el mismo caso, sin duplicarlos.
- El vencimiento se calcula a las 72 horas y cambia a vencido a la hora exacta.
- Los avisos usan la cola compartida, el PDF y el enlace firmado de revisión.
- La apertura desde Servicios públicos utiliza el popup completo del caso nativo.
- Cerrar el caso retira el vencimiento y prepara la cancelación del recordatorio, sin que esta integración cambie su estado administrativo.
- Fallar el historial después de insertar el caso deshace la operación.
- Un seguimiento anterior abierto se convierte una sola vez, sin ampliar su plazo.
- Las cargas nuevas al endpoint público de pago se rechazan.

## Pendiente para activarlo en producción

Desplegar el código, ejecutar `php bin/migrate-public-services.php` y activar la nueva plantilla aprobada **scm_servicios_revision_critica**, con encabezado Documento y botón Ver revisión. La migración convierte las tablas de casos e historial a InnoDB cuando sea necesario; debe ejecutarse con respaldo y ventana de mantenimiento. Ver [guía de configuración](guia-plantillas-y-proceso-servicios-publicos.md).
