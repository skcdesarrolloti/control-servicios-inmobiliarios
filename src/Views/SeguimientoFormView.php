<?php

namespace SCM\Views;

final class SeguimientoFormView
{
  public function render(int $ticketPk, bool $canSeguimiento, bool $hasCotizacion = false): string
  {
    if (!$canSeguimiento) {
      return '<div class="scm-seg-readonly">Inicia sesi&oacute;n para registrar seguimiento desde este panel.</div>';
    }
    if ($ticketPk <= 0) {
      return '<div class="scm-seg-readonly">No se puede registrar seguimiento: el ticket no tiene identificador interno.</div>';
    }

    $html = '';
    $html .= '<form class="scm-seg-form" method="post" enctype="multipart/form-data" autocomplete="off">';
    $html .= '<input type="hidden" name="ticket_pk" value="' . htmlspecialchars((string) $ticketPk, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="scm-seg-grid">';
    $html .= '<label class="scm-seg-field"><span>Observaci&oacute;n</span><textarea name="observacion" rows="3" required placeholder="Escribe el seguimiento..."></textarea></label>';
    $html .= '<label class="scm-seg-field"><span>Imagenes / Evidencias <em style="font-weight:normal;font-size:0.85em;">(opcional, jpg, png, gif, webp, bmp, heic, tiff)</em></span><input type="file" name="evidencia[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/heic,image/heif,image/tiff" multiple></label>';
    $html .= '<div class="scm-paste-evidence" tabindex="0" role="button" data-scm-paste-evidence data-file-input-name="evidencia[]"><strong>Pegar captura</strong><span>Haz clic aqui y presiona Ctrl+V para adjuntar una imagen copiada.</span><ul data-scm-paste-list></ul></div>';
    $html .= '<input type="hidden" name="estado_ticket" value="__keep__">';
    $html .= '<label class="scm-seg-field"><span>Estado administrativo</span><select name="estado_administrativo">'
      . '<option value="__keep__">Sin cambio</option>'
      . '<option value="Nuevo">Nuevo</option>'
      . '<option value="Por inspeccionar">Por inspeccionar</option>'
      . '<option value="Inspeccionado">Inspeccionado</option>'
      . '<option value="Cotizado">Cotizado</option>'
      . '<option value="En ejecucion por inmobiliaria">En ejecucion por inmobiliaria</option>'
      . '<option value="En ejecucion por propietario">En ejecucion por propietario</option>'
      . '<option value="En ejecucion por arrendatario">En ejecucion por arrendatario</option>'
      . '<option value="En ejecucion por copropiedad">En ejecucion por copropiedad</option>'
      . '<option value="Finalizado">Finalizado</option>'
      . '<option value="Trasladado">Trasladado</option>'
      . '<option value="Entregado">Entregado</option>'
      . '<option value="Recibido">Recibido</option>'
      . '<option value="Desistido">Desistido</option>'
      . '</select></label>';

    if ($hasCotizacion) {
      $html .= '<section class="scm-cotizacion-response-inline" data-scm-cotizacion-response-fields>';
      $html .= '<div class="scm-cotizacion-response-head"><strong>Respuesta de cotizaci&oacute;n</strong><span>Opcional: si respondes aqu&iacute;, tambi&eacute;n se actualiza la cotizaci&oacute;n asociada.</span></div>';
      $html .= '<label class="scm-seg-field"><span>Estado cotizaci&oacute;n</span><select name="estado_cotizacion"><option value="__keep__">Sin cambio</option><option value="Aprobada">Aprobada</option><option value="Desaprobada">Desaprobada</option></select></label>';
      $html .= '<label class="scm-seg-field scm-cotizacion-motivo" style="display:none;"><span>Motivo</span><select name="motivo_cotizacion"><option value="">Elige un motivo</option><option value="Por costo">Por costo</option><option value="Ejecucción por cuenta propia">Ejecucción por cuenta propia</option></select></label>';
      $html .= '<label class="scm-seg-field scm-cotizacion-financiacion" style="display:none;"><span>Financiaci&oacute;n</span><select name="financiacion_cotizacion"><option value="">No aplica / sin respuesta</option><option value="Si">Si</option><option value="No">No</option></select></label>';
      $html .= '<label class="scm-seg-field"><span>Observaci&oacute;n cotizaci&oacute;n</span><textarea name="observacion_cotizacion" rows="4" placeholder="Escribe la respuesta u observaci&oacute;n de la cotizaci&oacute;n..."></textarea></label>';
      $html .= '</section>';
    } else {
      $html .= '<input type="hidden" name="estado_cotizacion" value="__keep__">';
    }

    $html .= '</div>';
    $html .= '<div class="scm-ticket-documents-zone" data-ticket-documents-zone>';
    $html .= '<div class="scm-ticket-documents-label">Documentos opcionales</div>';
    $html .= '<div class="scm-ticket-documents" data-ticket-documents>';
    $html .= '</div>';
    $html .= '<button type="button" class="btn btn-outline scm-add-ticket-document" data-add-ticket-document>+ Agregar documento</button>';
    $html .= '</div>';
    $html .= '<input type="hidden" name="notify_recipients_present" value="1">';
    $html .= '<fieldset class="scm-notify-targets"><legend>Notificar por correo</legend>';
    foreach ($this->notifyOptions() as $value => $label) {
      $isNone    = ($value === 'none');
      $checked   = $isNone ? '' : ' checked';
      $extraCls  = $isNone ? ' scm-seg-check--none' : '';
      $html .= '<label class="scm-seg-check' . $extraCls . '"><input type="checkbox" name="notify_recipients[]" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $checked . '> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
    }
    $html .= '</fieldset>';
    $html .= '<div class="scm-seg-actions">';
    $html .= '<label class="scm-seg-check"><input type="checkbox" name="cerrar_ticket" value="1"> Cerrar ticket en este seguimiento</label>';
    $html .= '<button type="submit" class="scm-btn-primary">Guardar seguimiento</button>';
    $html .= '<span class="scm-seg-msg" aria-live="polite"></span>';
    $html .= '</div>';
    $html .= '</form>';

    return $html;
  }

  /** @return array<string,string> */
  private function notifyOptions(): array
  {
    return [
      'solicitante'  => 'Solicitante',
      'arrendatario' => 'Arrendatario',
      'propietario'  => 'Propietario',
      'empleado'     => 'Empleado',
      'admin'        => 'Administrativos',
      'none'         => 'Ninguno',
    ];
  }
}
