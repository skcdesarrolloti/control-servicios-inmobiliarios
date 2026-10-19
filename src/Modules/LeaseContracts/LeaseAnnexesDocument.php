<?php

declare(strict_types=1);

namespace SCM\Modules\LeaseContracts;

use SCM\Modules\Pending\PublicServicesDocument as Doc;

/** Actas de bienvenida y vista pública de anexos del contrato, con el diseño de servicios públicos. */
final class LeaseAnnexesDocument
{
  public const ROLES = ['propietario' => 'Propietario', 'arrendatario' => 'Arrendatario', 'codeudor' => 'Codeudor'];

  public static function url(int $contractId, string $role, int $index = 0, ?int $expires = null): string
  {
    $expires ??= time() + 400 * 86400;
    return rtrim((string) SCM_BASE_URL, '/') . '/anexos-contrato.php?numero=' . $contractId . '&tipo=' . rawurlencode($role)
      . ($role === 'codeudor' ? '&codeudor=' . $index : '') . '&expires=' . $expires . '&sig=' . self::signature($contractId, $role, $index, $expires);
  }

  public static function signature(int $contractId, string $role, int $index, int $expires): string
  {
    return hash_hmac('sha256', 'lease-annexes|' . $contractId . '|' . $role . '|' . $index . '|' . $expires, (string) SCM_APP_SECRET);
  }

  public static function valid(int $contractId, string $role, int $index, int $expires, string $signature): bool
  {
    return $contractId > 0 && isset(self::ROLES[$role]) && $expires > time()
      && hash_equals(self::signature($contractId, $role, $index, $expires), $signature);
  }

  /**
   * Acta de bienvenida para un actor del contrato.
   *
   * @param array<string,mixed> $context contrato, sucursal y funcionario que envía
   * @param array{role:string,index:int,name:string} $actor
   */
  public static function welcome(array $context, array $actor, string $annexesUrl, bool $pdf = false): string
  {
    $role = $actor['role'];
    $name = trim($actor['name']) !== '' ? $actor['name'] : self::ROLES[$role];
    $contract = (string) ($context['contrato'] ?? '');
    $propertyCode = (string) ($context['id_inmueble'] ?? $context['inmueble'] ?? '');
    $start = (int) ($context['inicio_contrato'] ?? 0) > 0 ? self::longDate((int) $context['inicio_contrato']) : 'la fecha pactada';
    $paragraphs = match ($role) {
      'propietario' => [
        'Por medio de la presente le anunciamos que su propiedad identificada con el código **#' . $propertyCode . '**, ha sido ocupada con contrato número **#' . $contract . '** con fecha de inicio **' . $start . '**, día en el cual se ha perfeccionado el contrato. Lo hemos incorporado en calidad de **PROPIETARIO**.',
        'Buscamos potencializar nuestros servicios. Para tal efecto, lo invitamos a que visite nuestra página web www.sucasainmobiliaria.com.co, donde podrá visualizar nuestro portafolio ampliado de servicios y todo lo relacionado con la administración de su propiedad.',
        '**Queremos manifestarle que tiene un cupo de crédito preaprobado hasta tres (3) veces el valor del canon recibido*, con el fin de que pueda disponer de él para atender sus requerimientos económicos.**',
        '*Aplican términos y condiciones.',
        'Para poder darle un trato personalizado, le informamos los funcionarios que podrán colaborarle en todos sus requerimientos:',
      ],
      'arrendatario' => [
        'Por medio de la presente le damos la más cordial bienvenida a **' . (string) ($context['razon_social'] ?? 'SOLUCIONES COMERCIALES Y CONSTRUCTIVAS SAS') . '**, donde lo hemos incorporado en calidad de **ARRENDATARIO**, ocupando el inmueble identificado con el código **#' . (string) ($context['inmueble'] ?? $propertyCode) . '** y número de contrato **#' . $contract . '** con fecha de inicio **' . $start . '**, día en el cual se ha perfeccionado el mismo.',
        'Nuestra misión se mantiene: hacer todo lo que esté a nuestro alcance para atenderlo y prestarle un servicio para que su permanencia en el inmueble sea memorable.',
        'Por tanto, los funcionarios que a continuación relacionamos serán los responsables directos de atender todos sus requerimientos:',
      ],
      default => [
        'Por medio de la presente le damos la más cordial bienvenida a nuestra empresa, donde lo hemos incorporado como **CLIENTE ESPECIAL**, debido a que figura como **CODEUDOR** de la propiedad identificada con el código **#' . $propertyCode . '**, ocupada con contrato número **#' . $contract . '** con fecha de inicio **' . $start . '**, día en el cual se ha perfeccionado el contrato.',
        'Por tanto, el funcionario que a continuación relacionamos será el responsable directo de atender todos sus requerimientos en relación con el contrato:',
      ],
    };
    $contacts = [['Contractual', $context['nombre_contractual'] ?? '', $context['celular_contractual'] ?? '', $context['correo_contractual'] ?? '']];
    if ($role === 'propietario') {
      $contacts[] = ['Pagos', $context['nombre_propietarios'] ?? '', $context['celular_propietarios'] ?? '', $context['correo_propietarios'] ?? ''];
    } elseif ($role === 'arrendatario') {
      $contacts[] = ['Servicio al arrendatario', $context['nombre_arrendatarios'] ?? '', $context['celular_arrendatarios'] ?? '', $context['correo_arrendatarios'] ?? ''];
    }

    $letterhead = $letterheadFooter = '';
    if ($pdf) {
      $assets = dirname(__DIR__, 3) . '/resources/assets/';
      $top = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-header.jpg'));
      $bottom = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($assets . 'membrete-sucasa-footer.jpg'));
      $letterhead = '<style>@page{size:letter;margin:12mm 18mm 14mm;@bottom-left{content:none}@bottom-center{content:none}}</style><div class="scm-services-letterhead-top"><img src="' . $top . '" alt="Membrete institucional"></div>';
      $letterheadFooter = '<div class="scm-services-letterhead-bottom"><img src="' . $bottom . '" alt=""></div>';
    }

    $html = '<article class="scm-acta-receipt scm-services-document scm-services-act scm-lease-act">' . $letterhead
      . Doc::header('Acta de bienvenida · ' . self::ROLES[$role], false)
      . '<p class="scm-acta-receipt-date">' . Doc::e($context['ciudad'] ?? 'Cartagena de Indias') . ', ' . Doc::e(self::longDate((int) ($context['fecha'] ?? time()))) . '</p>'
      . '<section class="scm-acta-receipt-section scm-services-letter"><h2>Referencia: reciba una cálida bienvenida</h2>'
      . '<p><strong>Apreciado(a) ' . Doc::e($name) . '</strong></p>';
    foreach ($paragraphs as $paragraph) {
      $html .= '<p>' . Doc::paragraph($paragraph) . '</p>';
    }
    $html .= '</section><section class="scm-acta-receipt-section"><h2>Funcionarios de contacto</h2><div class="scm-lease-contacts">';
    foreach ($contacts as [$label, $contactName, $phone, $email]) {
      $html .= '<div class="scm-lease-contact"><span>' . Doc::e($label) . '</span><strong>' . Doc::e($contactName !== '' ? $contactName : 'Por asignar') . '</strong><p>' . Doc::e(implode(' · ', array_filter([(string) $phone, (string) $email]))) . '</p></div>';
    }
    $html .= '</div>';
    if ($role === 'codeudor') {
      $html .= '<p class="scm-lease-note">De todas formas, le anunciamos que el funcionario que a continuación se relaciona estará listo para apoyarlo como <strong>CLIENTE ESPECIAL</strong> en caso de tener algún requerimiento en el tema inmobiliario:</p>'
        . '<div class="scm-lease-contacts"><div class="scm-lease-contact"><span>Gerencia comercial</span><strong>' . Doc::e(($context['gerente_comercial'] ?? '') !== '' ? $context['gerente_comercial'] : 'Por asignar') . '</strong><p>' . Doc::e(implode(' · ', array_filter([(string) ($context['celular_ge_comercial'] ?? ''), (string) ($context['correo_ge_comercial'] ?? '')]))) . '</p></div></div>'
        . '<p class="scm-lease-note">Nuestra promesa es servirle en todo lo referente a la categoría que en nuestra empresa usted ostenta.</p>';
    }
    $html .= '</section><section class="scm-acta-receipt-section"><h2>Anexos digitales</h2><p class="scm-lease-note">Consulte los anexos de su contrato en el siguiente enlace seguro:</p><ul class="scm-lease-annex-list">';
    foreach (self::annexLabels($role) as $label) {
      $html .= '<li>' . Doc::e($label) . '</li>';
    }
    $html .= '</ul><p class="scm-lease-link"><a href="' . Doc::e($annexesUrl) . '">Ver anexos del contrato</a></p></section>'
      . '<footer class="scm-acta-receipt-footer scm-services-signatures"><div><span>Firmado original</span><strong>' . Doc::e(($context['representante_legal'] ?? '') !== '' ? $context['representante_legal'] : 'Representante legal') . '</strong><p>Representante legal' . (($context['celular_legal'] ?? '') !== '' ? ' · ' . Doc::e($context['celular_legal']) : '') . '</p></div>'
      . '<div><span>Realizado por</span><strong>' . Doc::e($context['realizado_por'] ?? 'SKC SuCasa Inmobiliaria') . '</strong><p>' . Doc::e(implode(' · ', array_filter([(string) ($context['realizado_por_cargo'] ?? ''), (string) ($context['realizado_por_telefono'] ?? '')]))) . '</p></div>'
      . '<p class="scm-services-company">SKC SuCasa Inmobiliaria · NIT 900623242-4</p></footer>'
      . $letterheadFooter . '</article>';
    return $html;
  }

  /** @return string[] Anexos que ve cada actor: el propietario no recibe el contrato de arrendamiento y los demás no reciben el mandato. */
  public static function annexLabels(string $role): array
  {
    return $role === 'propietario'
      ? ['Acta de bienvenida', 'Registro fotográfico', 'Inventario del inmueble', 'Acta de entrega', 'Contrato de mandato']
      : ['Acta de bienvenida', 'Contrato de arrendamiento', 'Registro fotográfico', 'Inventario del inmueble', 'Acta de entrega'];
  }

  /**
   * @param array<string,mixed> $contract
   * @return array<int,array{label:string,url:string,preview:bool}>
   */
  public static function annexes(array $contract, string $role, int $index): array
  {
    $wp = 'https://sucasainmobiliaria.com.co/';
    $act = $role === 'codeudor' ? (string) ($contract['acta_codeudor_' . $index] ?? '') : (string) ($contract['acta_' . $role] ?? '');
    $links = [
      'Acta de bienvenida' => [$act, true],
      'Contrato de arrendamiento' => [(string) ($contract['contrato_arrendamiento'] ?? ''), true],
      'Registro fotográfico' => [(string) ($contract['registro_fotografico'] ?? ''), false],
      'Inventario del inmueble' => [self::idUrl($wp . 'inventario-inmueble/?numero=', $contract['id_inventario'] ?? ''), true],
      'Acta de entrega' => [self::idUrl($wp . 'acta-de-entrega/?numero=', $contract['id_acta_entrega'] ?? ''), true],
      'Contrato de mandato' => [self::idUrl($wp . 'contrato-de-mandato/?numero=', $contract['id_contrato_mandato'] ?? ''), true],
    ];
    $out = [];
    foreach (self::annexLabels($role) as $label) {
      [$url, $preview] = $links[$label];
      $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
      $out[] = ['label' => $label, 'url' => filter_var($url, FILTER_VALIDATE_URL) ? $url : '', 'preview' => $preview];
    }
    return $out;
  }

  /** @param array<string,mixed> $contract @param array<string,string> $sender */
  public static function publicPage(array $contract, string $role, int $index, array $sender): string
  {
    $name = $role === 'codeudor' ? (string) ($contract['codeudor_' . $index] ?? '') : (string) ($contract[$role] ?? '');
    $html = '<article class="scm-acta-receipt scm-services-document scm-lease-public">' . Doc::header('Anexos del contrato de arrendamiento #' . (string) ($contract['contrato'] ?? ''))
      . '<p class="scm-acta-receipt-date">Documentos para ' . Doc::e(self::ROLES[$role] . ($name !== '' ? ': ' . $name : '')) . '</p>'
      . '<section class="scm-acta-receipt-section"><h2>1. Datos del contrato</h2>' . Doc::table([
        'Contrato / Inmueble SIMI' => '#' . (string) ($contract['contrato'] ?? '') . ' · ' . (string) ($contract['inmueble'] ?? ''),
        'Código del inmueble' => (string) ($contract['id_inmueble'] ?? ''),
        'Dirección' => (string) ($contract['direccion'] ?? ''),
        'Propietario' => (string) ($contract['propietario'] ?? ''),
        'Arrendatario' => (string) ($contract['arrendatario'] ?? ''),
        'Inicio del contrato' => (int) ($contract['inicio_contrato'] ?? 0) > 0 ? date('d/m/Y', (int) $contract['inicio_contrato']) : '',
        'Fin del contrato' => (int) ($contract['fin_contrato'] ?? 0) > 0 ? date('d/m/Y', (int) $contract['fin_contrato']) : '',
      ]) . '</section><section class="scm-acta-receipt-section"><h2>2. Anexos</h2><div class="scm-lease-annex-grid">';
    foreach (self::annexes($contract, $role, $index) as $annex) {
      $html .= '<section class="scm-lease-annex"><h3>' . Doc::e($annex['label']) . '</h3>';
      if ($annex['url'] === '') {
        $html .= '<p class="scm-lease-note">Aún no está disponible.</p>';
      } elseif ($annex['preview']) {
        $html .= '<button type="button" class="scm-services-button" data-services-preview="' . Doc::e($annex['url']) . '" data-services-preview-title="' . Doc::e($annex['label']) . '">Ver documento</button>';
      } else {
        // Las carpetas de Google Drive no permiten mostrarse dentro de otra página.
        $html .= '<a class="scm-services-button" href="' . Doc::e($annex['url']) . '" target="_blank" rel="noopener noreferrer">Abrir carpeta</a>';
      }
      $html .= '</section>';
    }
    return $html . '</div></section>'
      . Doc::footer(['realizado_por' => $sender['name'] ?? 'SKC SuCasa Inmobiliaria', 'realizado_por_cargo' => $sender['cargo'] ?? '', 'realizado_por_telefono' => $sender['phone'] ?? ''])
      . '</article>';
  }

  public static function longDate(int $timestamp): string
  {
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $timestamp = $timestamp > 0 ? $timestamp : time();
    return (int) date('j', $timestamp) . ' de ' . $months[(int) date('n', $timestamp)] . ' de ' . date('Y', $timestamp);
  }

  private static function idUrl(string $base, mixed $id): string
  {
    $id = trim((string) $id);
    return $id !== '' && $id !== '0' ? $base . rawurlencode($id) : '';
  }
}
