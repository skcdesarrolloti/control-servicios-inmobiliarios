<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Core\Settings;

final class PublicServicesActTemplates
{
  public const KEY = 'public_services_act_templates';
  public const TYPES = ['al_dia' => 'Al día', '30_dias' => 'Mora de 30 días', '60_dias' => 'Mora de 60 días', 'critico' => 'Crítico / superior a 90 días'];
  public const VARIABLES = ['arrendatario','propietario','contrato','inmueble','direccion','servicio','referencia','medidor','valor','estado','fecha','empresa'];

  public function __construct(private Database $db) {}

  public static function type(string $status): string
  {
    return match ($status) { 'Al dia' => 'al_dia', '30 dias' => '30_dias', '60 dias' => '60_dias', default => 'critico' };
  }

  public static function defaults(): array
  {
    return [
      'al_dia' => ['title' => 'Acta de reconocimiento por pago oportuno', 'body' => "Apreciado(a) {{arrendatario}}:\n\nLa revisión periódica realizada al inmueble ubicado en {{direccion}}, contrato #{{contrato}}, evidenció que la cuenta del servicio de {{servicio}}, referencia {{referencia}}, se encuentra al día, sin saldo vencido reportado a la fecha de esta acta.\n\nReconocemos su responsabilidad y compromiso con el cumplimiento oportuno de las obligaciones del contrato de arrendamiento. Mantener los servicios públicos al día contribuye al cuidado del inmueble y a una relación contractual clara y ordenada.\n\nAgradecemos su buena gestión. Nuestro compromiso es acompañarle con información oportuna y brindar una experiencia de servicio memorable.\n\nCordialmente,"],
      '30_dias' => ['title' => 'Requerimiento de pago por mora de 30 días', 'body' => "Apreciado(a) {{arrendatario}}:\n\nLa revisión del servicio de {{servicio}}, referencia {{referencia}}, del inmueble ubicado en {{direccion}}, contrato #{{contrato}}, reportó un saldo pendiente de {{valor}} y un estado de mora de {{estado}}.\n\nLe invitamos a cancelar el valor adeudado en el menor tiempo posible y remitir el soporte a los canales establecidos por la inmobiliaria. Esta comunicación tiene el carácter de recordatorio preventivo.\n\nUna atención oportuna evita costos de reconexión y otras consecuencias asociadas a la suspensión del servicio. Quedamos a su disposición para aclarar la información registrada.\n\nCordialmente,"],
      '60_dias' => ['title' => 'Requerimiento de pago por mora de 60 días', 'body' => "Apreciado(a) {{arrendatario}}:\n\nLa revisión del servicio de {{servicio}}, referencia {{referencia}}, del inmueble ubicado en {{direccion}}, contrato #{{contrato}}, reportó un saldo pendiente de {{valor}} y un estado de mora de {{estado}}.\n\nSolicitamos realizar el pago de manera inmediata y remitir el soporte correspondiente. La permanencia de la mora constituye un incumplimiento de las obligaciones asumidas en el contrato de arrendamiento.\n\nDe continuar el saldo pendiente, se informará a las personas solidariamente responsables y el caso podrá ser trasladado al área contractual para las actuaciones que correspondan.\n\nCordialmente,"],
      'critico' => ['title' => 'Requerimiento formal de pago – mora superior a 90 días en servicio público', 'body' => "Señor(a) {{arrendatario}}\n{{direccion}} · Inmueble {{inmueble}}\n\nCordial saludo,\n\nSOLUCIONES COMERCIALES Y CONSTRUCTIVAS S.A.S. – {{empresa}}, en calidad de arrendador del inmueble anteriormente identificado, se permite REQUERIR FORMALMENTE la normalización inmediata de la obligación pendiente correspondiente al siguiente servicio público:\n\nServicio: {{servicio}}\nNIC / Contrato / Referencia: {{referencia}}\nVALOR ADEUDADO: {{valor}}\nESTADO: Superior a noventa (90) días – aproximadamente tres (3) facturas pendientes.\n\nLa situación resulta especialmente preocupante, teniendo en cuenta el tiempo de mora acumulado y las consecuencias que pueden derivarse de la falta de pago, entre ellas la suspensión del servicio, intereses, costos de reconexión y demás cargos que pueda generar la empresa prestadora.\n\nAdicionalmente, recordamos que la CLÁUSULA NOVENA – SERVICIOS del contrato de arrendamiento establece expresamente que los servicios públicos domiciliarios se encuentran a cargo del arrendatario y que la mora en su pago constituye causal de terminación del contrato de arrendamiento.\n\nEn consecuencia, se concede un término máximo e improrrogable de cuarenta y ocho (48) horas contadas a partir del recibo de la presente comunicación para:\n\n1. Efectuar el pago total de la obligación y remitir el respectivo soporte; o\n2. Acreditar documentalmente que la obligación fue cancelada, se encuentra sometida a reclamación formal o existe alguna situación debidamente soportada que requiera verificación.\n\nDe persistir la deuda una vez vencido el término señalado, la inmobiliaria procederá a dar inicio a las actuaciones contractuales y judiciales correspondientes, incluyendo las acciones dirigidas a la terminación del contrato cuando resulte procedente y al cobro de las obligaciones a cargo del ARRENDATARIO y de los DEUDORES SOLIDARIOS vinculados al contrato, sin perjuicio de las demás acciones que correspondan.\n\nEl presente requerimiento se realiza con el propósito de obtener la normalización inmediata de la obligación y evitar mayores consecuencias contractuales y económicas.\n\nAgradecemos remitir el soporte de pago dentro del término indicado a los canales establecidos por la inmobiliaria.\n\nCordialmente,"],
    ];
  }

  public function all(): array
  {
    $saved = (new Settings($this->db))->get(self::KEY, []);
    $templates = self::defaults();
    foreach ($templates as $key => $default) {
      if (is_array($saved[$key] ?? null) && isset($saved[$key]['title'], $saved[$key]['body'])) $templates[$key] = $saved[$key];
      $templates[$key]['version'] = self::version($templates[$key]);
    }
    return $templates;
  }

  public static function version(array $template): string { return hash('sha256', $template['title'] . "\n" . $template['body']); }

  public function save(string $type, array $input, int $employeeId, string $name): void
  {
    $template = self::validate($type, $input);
    $pdo = $this->db->pdo();
    if ($pdo->inTransaction()) throw new \DomainException('Ya existe una operación en curso.');
    $pdo->beginTransaction();
    try {
      $this->db->getRow('SELECT _ID FROM `' . $this->db->table('jet_cct_confi_sistema') . '` WHERE funcion = ? FOR UPDATE', [Settings::FUNCTION_KEY]);
      $settings = new Settings($this->db);
      $current = $this->all()[$type];
      if (!hash_equals($current['version'], (string) ($input['version'] ?? ''))) throw new \DomainException('La plantilla cambió mientras la editabas. Recárgala antes de guardar.');
      $all = $settings->get(self::KEY, []);
      $all[$type] = $template + ['updated_at' => time(), 'updated_by' => $employeeId, 'updated_name' => $name];
      $settings->set(self::KEY, $all, $employeeId);
      $history = $settings->get('public_services_template_history', []);
      $history[] = ['type' => $type, 'at' => time(), 'id_empleado' => $employeeId, 'name' => $name, 'before' => $current, 'after' => $all[$type]];
      $settings->set('public_services_template_history', array_slice($history, -100), $employeeId);
      $pdo->commit();
      \SCM\Core\App::settings()->refresh();
    } catch (\Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
  }

  public static function validate(string $type, array $input): array
  {
    if (!isset(self::TYPES[$type])) throw new \DomainException('Tipo de acta no válido.');
    $result = ['title' => trim((string) ($input['title'] ?? '')), 'body' => trim(str_replace("\r\n", "\n", (string) ($input['body'] ?? '')))];
    if ($result['title'] === '' || $result['body'] === '' || mb_strlen($result['title']) > 240 || mb_strlen($result['body']) > 20000) throw new \DomainException('Completa título y contenido (máximo 240 y 20.000 caracteres).');
    foreach ($result as $value) {
      preg_match_all('/\{\{([^{}]+)\}\}/', $value, $matches);
      foreach ($matches[1] as $variable) if (!in_array($variable, self::VARIABLES, true)) throw new \DomainException('Variable no válida: ' . $variable);
    }
    return $result;
  }

  public static function expand(string $text, array $context, array $service): string
  {
    $values = $context + ['empresa' => 'SKC SuCasa Inmobiliaria'];
    $values['servicio'] = $service['display_label'] ?? $service['label'] ?? '';
    $values['referencia'] = $service['account'] ?? '';
    $values['medidor'] = $service['meter'] ?? '';
    $values['valor'] = '$' . number_format((int) ($service['amount'] ?? 0), 0, ',', '.') . ' COP';
    $values['estado'] = PublicServicesDocument::status($service['status'] ?? '');
    $values['fecha'] = date('d/m/Y', (int) ($context['fecha'] ?? time()));
    $replace = [];
    foreach (self::VARIABLES as $key) $replace['{{' . $key . '}}'] = (string) ($values[$key] ?? '');
    return strtr($text, $replace);
  }
}
