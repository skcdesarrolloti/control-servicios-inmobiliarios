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
        'Ofrecimiento revision preventiva arrendatario',
        'acta_revision_preventiva_arrendatario'
      );
      $out['acta_revision_preventiva_propietario'] = $this->save(
        $this->buildPreventiva($ticket, 'propietario'),
        'ofrecimiento_revision_preventiva_propietario_' . $ticketId,
        'Ofrecimiento revision preventiva propietario',
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

  /** @param array<string,mixed> $ticket */
  private function buildPreventiva(array $ticket, string $destinatario): SimplePdf
  {
    $pdf = new SimplePdf();
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

    $pdf->logo();
    $pdf->title('Ofrecimiento de revision anual preventiva gratuita');
    $pdf->line($ciudad . ', ' . date('d-m-Y'), 9);
    $pdf->line('Apreciado(a) ' . $nombreDest, 10, 'F2');
    $pdf->line('Contrato: ' . $contrato . ' | Inmueble: ' . $inmueble, 8, 'F2');
    if ($direccion !== '') {
      $pdf->line('Direccion: ' . $direccion, 8);
    }
    $pdf->spacer(8);
    $pdf->paragraph('Conscientes de la importancia de mantener el inmueble en optimas condiciones para el disfrute, goce y satisfaccion de quienes lo habitan y administran, nos permitimos ofrecer la realizacion de una revision anual preventiva gratuita.');
    $pdf->paragraph('Esta revision permite evaluar el estado general del inmueble, detectar oportunamente posibles danos y emitir un diagnostico sobre las ' . $repairLabel . ' que puedan presentarse por el uso normal del bien despues de un periodo de ocupacion.');

    $pdf->line('Beneficios de la revision preventiva gratuita:', 9, 'F2');
    $pdf->bullets([
      'Facilitar el cumplimiento de las obligaciones de conservacion del inmueble conforme al Codigo Civil Colombiano.',
      'Detectar deterioros a tiempo y evitar que se conviertan en reparaciones futuras de mayor costo.',
      'Revisar la funcionalidad de los servicios publicos e instalaciones del inmueble.',
      'Contar con evidencia del resultado de la visita mediante el informe correspondiente.',
      'Coordinar oportunamente las acciones que deban ser autorizadas por las partes responsables.',
    ]);

    $pdf->paragraph('Nuestro proposito es acompanar la gestion del inmueble de manera preventiva, ordenada y transparente, procurando que la permanencia en nuestra empresa sea positiva y memorable.');
    $pdf->line('Coordinador Contractual, Mantenimiento y Servicios Publicos:', 8, 'F2');
    $pdf->line($this->contactLine($ticket, 'contractual'));
    $pdf->spacer(8);
    $pdf->line('Verificador de inmuebles:', 8, 'F2');
    $pdf->line($this->contactLine($ticket, 'empleado'));

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
    if ($prefix === 'contractual') {
      $name = $this->value($ticket, 'nombre_contractual', '');
      $phone = $this->value($ticket, 'celular_contractual', '');
      $email = $this->value($ticket, 'correo_contractual', '');
    } else {
      $name = $this->value($ticket, 'nombre_empleado', '');
      $phone = $this->value($ticket, 'celular_empleado', '');
      $email = $this->value($ticket, 'correo_empleado', '');
    }
    return trim($name . ' | ' . $phone . ' - ' . $email, ' |-');
  }

  /** @param array<string,mixed> $row */
  private function value(array $row, string $key, string $default): string
  {
    $value = trim((string) ($row[$key] ?? ''));
    return $value !== '' ? $value : $default;
  }
}
