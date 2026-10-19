<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Modules\Pending\PublicServicesDocument as Doc;

/**
 * Actas que genera "Crear contrato de arrendamiento" cuando el inmueble pertenece a una copropiedad,
 * con el mismo diseño de las actas de servicios públicos y de bienvenida.
 */
final class LeaseContractActs
{
  /** Acta de notificación de ingreso para el arrendatario. @param array<string,mixed> $c */
  public static function tenantNotice(array $c, bool $pdf = false): string
  {
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $html = '<article class="scm-acta-receipt scm-services-document scm-services-act scm-lease-act">' . $top
      . Doc::header('Acta de notificación de ingreso', false)
      . self::date($c)
      . '<section class="scm-acta-receipt-section scm-services-letter"><h2>Referencia: notificación de ingreso</h2>'
      . '<p><strong>Apreciado(a) ' . Doc::e((string) ($c['arrendatario'] ?? '')) . '</strong></p>'
      . '<p>Por medio de la presente le comunicamos algunos detalles correspondientes a la copropiedad <strong>' . Doc::e((string) ($c['copropiedad'] ?? '')) . '</strong> de su nuevo hogar ubicado en <strong>' . Doc::e((string) ($c['direccion'] ?? '')) . '</strong>, que son importantes para que su traslado, uso y goce sean de su mayor agrado y satisfacción.</p></section>';
    if (($c['tiene_parqueadero'] ?? '') === 'Si') {
      $html .= '<section class="scm-acta-receipt-section"><h2>Parqueadero</h2>' . Doc::table([
        'Número' => (string) ($c['numero_parqueadero'] ?? ''),
        'Tipo' => (string) ($c['tipo_parqueadero'] ?? ''),
        'Cantidad' => (string) ($c['parqueaderos'] ?? ''),
      ]) . '</section>';
    }
    $zones = (array) ($c['zonas_sociales'] ?? []);
    if ($zones !== []) {
      $html .= '<section class="scm-acta-receipt-section"><h2>Amenidades disponibles</h2><p class="scm-lease-note">' . Doc::e(implode(', ', $zones)) . '</p></section>';
    }
    $schedules = [
      'Mudanza · franja 1 (lunes a viernes)' => self::range($c, 'horario_mudanza_inicio_1', 'horario_mudanza_final_1'),
      'Mudanza · franja 2 (lunes a viernes)' => self::range($c, 'horario_mudanza_inicio_2', 'horario_mudanza_final_2'),
      'Mudanza · sábado' => self::range($c, 'horario_mudanza_inicio_sabado', 'horario_mudanza_final_sabado'),
      'Zonas sociales' => self::range($c, 'horario_zonas_inicio', 'horario_zonas_final'),
      'Gimnasio' => self::range($c, 'horario_gym_inicio', 'horario_gym_final'),
      'Piscina' => self::range($c, 'horario_piscina_inicio', 'horario_piscina_final'),
      'Canchas' => self::range($c, 'horario_canchas_inicio', 'horario_canchas_final'),
    ];
    $schedules = array_filter($schedules, static fn(string $value): bool => $value !== '');
    if ($schedules !== []) {
      $html .= '<section class="scm-acta-receipt-section"><h2>Horarios de la copropiedad</h2>' . Doc::table($schedules)
        . '<p class="scm-lease-note">Le recordamos que es importante respetar los horarios establecidos con el fin de garantizar una convivencia armoniosa entre todos los residentes.</p></section>';
    }
    $html .= '<section class="scm-acta-receipt-section"><h2>Funcionarios de contacto</h2><p class="scm-lease-note">Para poder darle un trato personalizado, le informamos los funcionarios que podrán atender todos los requerimientos que se presenten durante la ocupación y antes de la desocupación del mismo:</p>'
      . LeaseAnnexesDocument::contacts([
        ['Contractual, servicios públicos y mantenimiento', $c['nombre_contractual'] ?? '', $c['celular_contractual'] ?? '', $c['correo_contractual'] ?? ''],
        ['Servicio al arrendatario', $c['nombre_arrendatarios'] ?? '', $c['celular_arrendatarios'] ?? '', $c['correo_arrendatarios'] ?? ''],
      ]) . '</section>';
    return $html . LeaseAnnexesDocument::signatureFooter($c) . $bottom . '</article>';
  }

  /** Carta de presentación del nuevo inquilino para la administración de la copropiedad. @param array<string,mixed> $c */
  public static function buildingLetter(array $c, bool $pdf = false): string
  {
    [$top, $bottom] = LeaseAnnexesDocument::letterhead($pdf);
    $html = '<article class="scm-acta-receipt scm-services-document scm-services-act scm-lease-act">' . $top
      . Doc::header('Presentación del nuevo inquilino', false)
      . self::date($c)
      . '<section class="scm-acta-receipt-section scm-services-letter"><h2>Referencia: presentación nuevo inquilino</h2>'
      . '<p><strong>Señores ' . Doc::e((string) ($c['copropiedad'] ?? '')) . '</strong><br>Apreciado(a) ' . Doc::e((string) ($c['administrador'] ?? 'administrador(a)')) . '</p>'
      . '<p>Por medio de la presente le anunciamos que el inmueble <strong>' . Doc::e((string) ($c['direccion'] ?? '')) . '</strong> será ocupado por el inquilino <strong>' . Doc::e((string) ($c['arrendatario'] ?? '')) . '</strong>. La fecha de inicio <strong>será la correspondiente a la entrega material del inmueble</strong>, día en el cual se ha perfeccionado el contrato.</p></section>'
      . '<section class="scm-acta-receipt-section"><h2>Información adicional del inquilino</h2>' . Doc::table([
        '¿Tiene mascotas?' => self::withDetail($c['tiene_mascotas'] ?? '', array_map(static fn(array $pet): string => trim(($pet['tipo'] ?? '') . ' ' . ($pet['raza'] ?? '')), (array) ($c['mascotas'] ?? []))),
        '¿Tiene vehículo?' => self::withDetail($c['tiene_vehiculo'] ?? '', array_map(static fn(array $car): string => trim(($car['tipo'] ?? '') . ' · ' . ($car['placa'] ?? ''), ' ·'), (array) ($c['vehiculos'] ?? []))),
        '¿Convive con personas?' => self::withDetail($c['convive_personas'] ?? '', array_map(static fn(array $person): string => trim((string) ($person['nombre'] ?? '')), (array) ($c['personas'] ?? []))),
      ]) . '</section>';
    $people = (array) ($c['personas'] ?? []);
    if (($c['convive_personas'] ?? '') === 'Si' && $people !== []) {
      $html .= '<section class="scm-acta-receipt-section"><h2>Personas que conviven en el inmueble</h2><table class="scm-acta-receipt-data scm-lease-people"><thead><tr><th>Nombre</th><th>Identificación</th><th>Edad</th><th>Parentesco</th><th>Ocupación</th></tr></thead><tbody>';
      foreach ($people as $person) {
        $html .= '<tr><td>' . Doc::e($person['nombre'] ?? '') . '</td><td>' . Doc::e($person['identificacion'] ?? '') . '</td><td>' . Doc::e($person['edad'] ?? '') . '</td><td>' . Doc::e($person['parentesco'] ?? '') . '</td><td>' . Doc::e($person['ocupacion'] ?? '') . '</td></tr>';
      }
      $html .= '</tbody></table></section>';
    }
    $html .= '<section class="scm-acta-receipt-section scm-services-letter"><p>Le agradecemos de antemano todo su apoyo y valiosa colaboración para que la permanencia de nuestro arrendatario en el inmueble a ocupar sea de la más placentera posible.</p></section>'
      . '<section class="scm-acta-receipt-section"><h2>Funcionarios de contacto</h2><p class="scm-lease-note">Para poder darle un trato personalizado le informamos los funcionarios que podrán atender todos los requerimientos que se presenten durante la ocupación y antes de la desocupación del mismo:</p>'
      . LeaseAnnexesDocument::contacts([
        ['Contractual, servicios públicos y mantenimiento', $c['nombre_contractual'] ?? '', $c['celular_contractual'] ?? '', $c['correo_contractual'] ?? ''],
        ['Servicio al propietario', $c['nombre_propietarios'] ?? '', $c['celular_propietarios'] ?? '', $c['correo_propietarios'] ?? ''],
      ]) . '</section>';
    return $html . LeaseAnnexesDocument::signatureFooter($c) . $bottom . '</article>';
  }

  /** @param array<string,mixed> $c */
  private static function date(array $c): string
  {
    return '<p class="scm-acta-receipt-date">' . Doc::e($c['ciudad'] ?? 'Cartagena de Indias') . ', ' . Doc::e(LeaseAnnexesDocument::longDate((int) ($c['fecha'] ?? time()))) . '</p>';
  }

  /** @param array<string,mixed> $c */
  private static function range(array $c, string $start, string $end): string
  {
    $from = trim((string) ($c[$start] ?? ''));
    $to = trim((string) ($c[$end] ?? ''));
    return $from !== '' || $to !== '' ? trim(self::hour($from) . ' a ' . self::hour($to), ' a') : '';
  }

  private static function hour(string $value): string
  {
    $ts = $value !== '' ? strtotime('2000-01-01 ' . $value) : false;
    return $ts !== false && $value !== '' ? date('g:i a', $ts) : $value;
  }

  /** @param string[] $details */
  private static function withDetail(mixed $answer, array $details): string
  {
    $answer = (string) $answer;
    $details = array_filter(array_map('trim', $details));
    return $answer === 'Si' && $details !== [] ? 'Sí · ' . implode(', ', $details) : ($answer === 'Si' ? 'Sí' : $answer);
  }
}
