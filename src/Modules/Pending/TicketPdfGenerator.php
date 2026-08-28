<?php

namespace SCM\Modules\Pending;

use SCM\Support\SimplePdf;
use SCM\Support\StoredFileService;

final class TicketPdfGenerator
{
  /**
   * @param array<string,mixed> $ticket
   * @return array<string,array{key:string,title:string,url:string,path:string}>
   */
  public function generate(string $mode, string $tema, int $ticketId, array $ticket): array
  {
    $temaNorm = strtolower(trim($tema));
    $out = [];

    if ($mode === 'preventiva') {
      $out['acta_revision_preventiva_arrendatario'] = $this->save(
        $this->buildPreventiva($ticket, 'arrendatario'),
        'ofrecimiento_revision_preventiva_arrendatario_' . $ticketId,
        'Ofrecimiento de revision preventiva para arrendatario',
        'acta_revision_preventiva_arrendatario'
      );
      $out['acta_revision_preventiva_propietario'] = $this->save(
        $this->buildPreventiva($ticket, 'propietario'),
        'ofrecimiento_revision_preventiva_propietario_' . $ticketId,
        'Ofrecimiento de revision preventiva para propietario',
        'acta_revision_preventiva_propietario'
      );
    }

    if ($temaNorm === 'recibo de inmuebles') {
      $out['acta_desocupacion'] = $this->save(
        $this->buildActaDesocupacion($ticket),
        'acta_desocupacion_' . $ticketId,
        'Acta de desocupacion',
        'acta_desocupacion'
      );
    }

    return $out;
  }

  /**
   * @param array<string,mixed> $ticket
   * @return array{key:string,title:string,url:string,path:string}
   */
  public function generatePreventivaNoAccessNotice(int $ticketId, array $ticket, int $attempt = 1): array
  {
    return $this->save(
      $this->buildPreventivaNoAccessNotice($ticket, max(1, $attempt)),
      'comunicacion_no_permitir_acceso_preventiva_' . $ticketId . '_' . max(1, $attempt),
      'Comunicacion preventiva por no autorizacion de acceso #' . max(1, $attempt),
      'acta_no_acceso_preventiva'
    );
  }

  /** @param array<string,mixed> $ticket */
  private function buildPreventiva(array $ticket, string $destinatario): SimplePdf
  {
    $pdf = new SimplePdf();
    $pdf->backgroundImage($this->letterheadPath());
    $pdf->layout(58, 170, 118);

    $isOwner = $destinatario === 'propietario';
    $nombreDest = $isOwner
      ? $this->value($ticket, 'propietario', 'propietario')
      : $this->value($ticket, 'arrendatario', 'arrendatario');
    $contrato = $this->value($ticket, 'contrato', '-');
    $inmueble = $this->value($ticket, 'id_inmueble', $this->value($ticket, 'inmueble', '-'));
    $direccion = $this->value($ticket, 'direccion', '');
    $ciudad = $this->value($ticket, 'ciudad', 'Cartagena de Indias');
    $repairLabel = $isOwner
      ? 'Reparaciones Necesarias, Utiles y/o Voluntarias'
      : 'Reparaciones Locativas';
    $recipientLabel = $isOwner ? 'propietario(a)' : 'arrendatario(a)';
    $creator = $this->contactParts($ticket, 'creador');
    $checker = $this->contactParts($ticket, 'empleado');

    $pdf->title('Ofrecimiento de revision anual preventiva gratuita');
    $pdf->line($ciudad . ', ' . date('d/m/Y'), 8);
    $pdf->spacer(5);
    $pdf->line('Apreciado(a) ' . $recipientLabel . ': ' . $nombreDest, 10, 'F2');
    $pdf->line('Contrato: ' . $contrato . ' | Inmueble SIMI: ' . $inmueble, 8, 'F2');
    if ($direccion !== '') {
      $pdf->line('Direccion: ' . $direccion, 8);
    }
    $pdf->spacer(12);
    $pdf->paragraph('En SuCasa Inmobiliaria sabemos que la conservacion preventiva del inmueble evita novedades mayores y facilita una gestion mas clara entre las partes. Por eso, nos permitimos ofrecer la realizacion de una revision anual preventiva gratuita.');
    $pdf->paragraph('Esta visita permite evaluar el estado general del inmueble, detectar oportunamente posibles danos y dejar un diagnostico sobre las ' . $repairLabel . ' que puedan presentarse por el uso normal del bien despues de un periodo de ocupacion.');

    $pdf->line('Beneficios de la revision preventiva:', 9, 'F2');
    $pdf->bullets([
      'Facilitar el cumplimiento de las obligaciones de conservacion del inmueble conforme al Codigo Civil Colombiano.',
      'Detectar deterioros a tiempo y evitar que se conviertan en reparaciones futuras de mayor costo.',
      'Revisar la funcionalidad de los servicios publicos e instalaciones del inmueble.',
      'Contar con evidencia del resultado de la visita mediante el informe correspondiente.',
      'Coordinar oportunamente las acciones que deban ser autorizadas por las partes responsables.',
    ]);

    $pdf->paragraph('Nuestro proposito es acompanar la gestion del inmueble de manera preventiva, ordenada y transparente, procurando una experiencia de servicio clara, oportuna y memorable.');
    $pdf->spacer(8);
    $pdf->signatureBlock('Creado por', $creator['name'], $creator['details']);
    $pdf->signatureBlock('Verificador asignado', $checker['name'], $checker['details']);

    return $pdf;
  }

  /** @param array<string,mixed> $ticket */
  private function buildPreventivaNoAccessNotice(array $ticket, int $attempt): SimplePdf
  {
    $pdf = new SimplePdf();
    $pdf->backgroundImage($this->letterheadPath());
    $pdf->layout(58, 170, 118);

    $tenantName = $this->value($ticket, '_scm_arrendatario_nombre_resuelto', $this->value($ticket, 'arrendatario', $this->value($ticket, 'solicitante', 'arrendatario(a)')));
    $contract = $this->value($ticket, 'contrato', '-');
    $property = $this->value($ticket, 'inmueble', $this->value($ticket, 'id_inmueble', '-'));
    $address = $this->value($ticket, 'direccion', '');
    $city = $this->value($ticket, 'ciudad', 'Cartagena de Indias');
    $creator = $this->contactParts($ticket, 'creador');
    $checker = $this->contactParts($ticket, 'empleado');

    $pdf->title('Comunicacion por no autorizacion de revision preventiva');
    $pdf->line($city . ', ' . date('d/m/Y'), 8);
    $pdf->spacer(5);
    $pdf->line('Senor(a): ' . $tenantName, 10, 'F2');
    $pdf->line('Contrato: ' . $contract . ' | Inmueble SIMI: ' . $property . ' | Comunicacion No. ' . $attempt, 8, 'F2');
    $pdf->line('Cantidad acumulada de comunicaciones preventivas: ' . $attempt, 8);
    $pdf->line('Realizada por: ' . trim($creator['name'] . ' - ' . $creator['details'], ' -'), 8);
    if ($address !== '') {
      $pdf->line('Direccion: ' . $address, 8);
    }
    $pdf->spacer(12);
    $pdf->paragraph('Cordial saludo,');
    $pdf->paragraph('Teniendo en cuenta que se le notifico previamente la realizacion de la revision preventiva del inmueble que actualmente ocupa, nos permitimos dejar constancia de que, al momento de coordinar la respectiva cita, usted manifesto no requerir, no necesitar o no autorizar la realizacion de dicha revision.');
    $pdf->paragraph('La revision preventiva tiene como finalidad verificar el estado general del inmueble, identificar oportunamente posibles necesidades de mantenimiento y prevenir que situaciones menores puedan generar danos de mayor consideracion.');
    $pdf->paragraph('En consecuencia, al no permitirse la realizacion de la revision previamente informada, cualquier dano, deterioro, agravacion o mayor costo de reparacion que posteriormente se presente y que razonablemente hubiera podido ser identificado, prevenido o atendido oportunamente mediante dicha inspeccion sera imputable a quien impidio su realizacion.');
    $pdf->paragraph('La presente comunicacion se remite para dejar constancia de lo anterior dentro del historial del caso.');
    $pdf->spacer(8);
    $pdf->signatureBlock('Creado por', $creator['name'], $creator['details']);
    $pdf->signatureBlock('Verificador asignado', $checker['name'], $checker['details']);

    return $pdf;
  }

  /** @param array<string,mixed> $ticket */
  private function buildActaDesocupacion(array $ticket): SimplePdf
  {
    $pdf = new SimplePdf();
    $pdf->title('Acta de desocupacion');
    $pdf->logo();
    $pdf->heading('Encabezado');
    $pdf->heading('Encabezado');
    $pdf->line($this->value($ticket, 'ciudad', 'Cartagena de Indias') . ', ' . date('d-m-Y'));
    $pdf->line('Apreciado(a) ' . $this->value($ticket, 'arrendatario', 'arrendatario'));
    $pdf->spacer(8);
    $pdf->heading('Encabezado');
    $pdf->heading('Encabezado');
    $pdf->paragraph('Por medio de la presente le notificamos que su contrato #' . $this->value($ticket, 'contrato', '-') . ' de arrendamiento esta proximo a culminar, es por ello por lo que, con anticipacion, le invitamos a realizar las reparaciones que se encuentren pendientes en el inmueble; lo anterior, toda vez que tal como lo estipula el contrato de arrendamiento en su CLAUSULA DECIMA NOVENA, la cual establece:');
    $pdf->paragraph('"DECIMA NOVENA: RECIBO Y ESTADO. El arrendatario declara que ha recibido el inmueble objeto de este contrato en buen estado, conforme al inventario que hace parte de este, y que en el mismo estado lo restituira al arrendador a la terminacion del arrendamiento, o cuando este haya de cesar por alguna de las causales previstas, salvo el deterioro proveniente del tiempo y del uso legitimo."');
    $pdf->paragraph('Asi mismo, destacamos que para recibir el bien inmueble, usted debera estar a paz y salvo de canon de arrendamiento, administracion (si aplica), y servicios publicos con su respectivo deposito.');
    $pdf->paragraph('Nota: En caso de que el inmueble tenga reparaciones pendientes, o presente deudas de canon de arrendamiento, administracion y/o servicios publicos, los valores adeudados seguiran contando hasta el dia en que se reciba formalmente el inmueble y el mismo se encuentre totalmente a paz y salvo.');
    $pdf->line('Anexos:', 8, 'F2');
    $registro = $this->value($ticket, 'registro_fotografico', '');
    if ($registro !== '') {
      $pdf->linkText('Registro fotografico', $registro);
    }
    $pdf->heading('Encabezado');
    $pdf->line('Coordinador Contractual, Mantenimiento y Servicios Publicos:', 8, 'F2');
    $pdf->line($this->contactLine($ticket, 'contractual'));
    $pdf->spacer(8);
    $pdf->line('Verificador de inmuebles:', 8, 'F2');
    $pdf->line($this->contactLine($ticket, 'empleado'));
    return $pdf;
  }

  /**
   * @return array{key:string,title:string,url:string,path:string}
   */
  private function save(SimplePdf $pdf, string $slug, string $title, string $key): array
  {
    $basename = bin2hex(random_bytes(12)) . '_' . time() . '.pdf';
    $uploadDir = (string) SCM_UPLOAD_PATH;
    $path = $uploadDir . '/' . $basename;
    $pdf->save($path);
    $files = StoredFileService::fromRuntime();

    return [
      'key' => $key,
      'title' => $title,
      'url' => $files->urlFor($basename),
      'path' => $path,
    ];
  }

  /** @param array<string,mixed> $ticket */
  private function contactLine(array $ticket, string $prefix): string
  {
    $parts = $this->contactParts($ticket, $prefix);
    return trim($parts['name'] . ' | ' . $parts['details'], ' |-');
  }

  /** @param array<string,mixed> $ticket @return array{name:string,details:string} */
  private function contactParts(array $ticket, string $prefix): array
  {
    if ($prefix === 'contractual') {
      $name = $this->value($ticket, 'nombre_contractual', 'Control Servicios Inmobiliarios');
      $phone = $this->value($ticket, 'celular_contractual', '');
      $email = $this->value($ticket, 'correo_contractual', '');
      $cargo = 'Coordinacion contractual';
    } elseif ($prefix === 'creador') {
      $name = $this->value($ticket, 'nombre_creador_ticket', $this->value($ticket, 'creador_nombre', 'Funcionario'));
      $phone = $this->value($ticket, 'celular_creador_ticket', $this->value($ticket, 'creador_celular', ''));
      $email = $this->value($ticket, 'correo_creador_ticket', $this->value($ticket, 'creador_correo', ''));
      $cargo = $this->value($ticket, 'cargo_creador_ticket', $this->value($ticket, 'creador_cargo', 'Control Servicios Inmobiliarios'));
    } else {
      $name = $this->value($ticket, 'nombre_empleado', $this->value($ticket, 'empleado', 'Funcionario asignado'));
      $phone = $this->value($ticket, 'celular_empleado', '');
      $email = $this->value($ticket, 'correo_empleado', '');
      $cargo = 'Verificador de inmuebles';
    }

    $details = [];
    if ($cargo !== '') {
      $details[] = $cargo;
    }
    if ($phone !== '') {
      $details[] = 'Cel. ' . $phone;
    }
    if ($email !== '') {
      $details[] = $email;
    }
    return [
      'name' => $name,
      'details' => implode(' | ', $details),
    ];
  }

  private function letterheadPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/assets/membrete-sucasa.jpg';
  }

  /** @param array<string,mixed> $row */
  private function value(array $row, string $key, string $default): string
  {
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $default;
  }
}
