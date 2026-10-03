<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;

final class PublicServicesWorkspace
{
  public function __construct(private Database $db) {}

  public static function tabs(?int $count = null): string
  {
    $html = '<nav class="!sp-flex !sp-flex-wrap !sp-items-center !sp-gap-1 !sp-p-1 !sp-bg-slate-100 !sp-rounded-lg !sp-border-0 !sp-w-fit !sp-max-w-full" aria-label="Servicios públicos">';
    foreach (['pending'=>['Pendientes','clock'],'history'=>['Revisiones realizadas','refresh'],'templates'=>['Plantillas de actas','document']] as $key=>$tab) {
      $html .= '<button type="button" class="sp-group !sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-md !sp-bg-transparent !sp-border-0 !sp-text-service-muted !sp-font-sans !sp-text-[10px] !sp-font-medium !sp-cursor-pointer aria-pressed:!sp-bg-service-navy aria-pressed:!sp-text-white aria-pressed:!sp-font-semibold focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow" data-services-tab="' . $key . '" aria-pressed="' . ($key==='pending'?'true':'false') . '">'
        . PublicServicesUi::icon($tab[1], '!sp-w-3 !sp-h-3 group-aria-pressed:!sp-text-service-yellow') . '<span>' . $tab[0] . '</span>';
      if ($key==='pending' && $count!==null) $html .= '<span class="!sp-rounded-full !sp-bg-slate-200 !sp-text-service-navy !sp-text-[8px] !sp-font-semibold !sp-px-1.5 !sp-py-0.5 group-aria-pressed:!sp-bg-white/20 group-aria-pressed:!sp-text-white" data-services-tab-count>' . max(0,$count) . '</span>';
      $html .= '<span class="!sp-hidden group-aria-pressed:!sp-block !sp-w-1 !sp-h-1 !sp-rounded-full !sp-bg-service-yellow" aria-hidden="true"></span></button>';
    }
    return $html . '</nav>';
  }

  public function templates(string $type = 'al_dia'): string
  {
    if (!isset(PublicServicesActTemplates::TYPES[$type])) $type = 'al_dia';
    $template = (new PublicServicesActTemplates($this->db))->all()[$type];
    $html = self::templateEditor($type, $template);
    $employee = (new PendingRepository($this->db))->getFuncionarioByUserId(\SCM\Core\Auth::userId());
    if (PublicServicesCritical::admin($employee ?? [])) $html .= (new PublicServicesCritical($this->db))->configHtml();
    return $html;
  }

  public static function templateEditor(string $type, array $template): string
  {
    return PublicServicesUi::render('editor', compact('type','template'));
  }

  public function history(array $input): string
  {
    $e = [PublicServicesDocument::class, 'e'];
    $where = ['1=1']; $args = [];
    foreach (['contrato','inmueble','arrendatario','propietario'] as $field) {
      $term = trim((string) ($input[$field] ?? ''));
      if ($term !== '') { $where[] = '`' . $field . '` LIKE ?'; $args[] = '%' . $this->db->escapeLike($term) . '%'; }
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
      if (empty($input[$key])) continue;
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input[$key], new \DateTimeZone('America/Bogota'));
      if (!$date || $date->format('Y-m-d') !== $input[$key]) throw new \DomainException('Fecha del filtro no válida.');
      $where[] = 'fecha ' . $op . ' ?'; $args[] = $date->getTimestamp() + ($key === 'to' ? 86399 : 0);
    }
    $table = $this->db->table('jet_cct_revisiones_servicios');
    $sqlWhere = implode(' AND ', $where);
    $total = (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $sqlWhere, $args);
    $pages = max(1, (int) ceil($total / 30));
    $page = min($pages, max(1, (int) ($input['page'] ?? 1)));
    $order = ($input['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $rows = $this->db->getResults('SELECT * FROM `' . $table . '` WHERE ' . $sqlWhere . ' ORDER BY fecha ' . $order . ', _ID ' . $order . ' LIMIT 30 OFFSET ' . (($page - 1) * 30), $args);
    $critical = new PublicServicesCritical($this->db);
    $criticalReviewIds = $critical->available() ? array_fill_keys($this->db->getCol('SELECT review_id FROM `' . $critical->table() . '`'), true) : [];
    return self::historyList($rows, $input, $total, $page, $pages, $criticalReviewIds);
  }

  public static function historyList(array $rows, array $input, int $total, int $page, int $pages, array $criticalReviewIds = []): string
  {
    return PublicServicesUi::render('history', compact('rows','input','total','page','pages','criticalReviewIds'));
  }

  public function review(int $id): array
  {
    $row = $this->db->getRow('SELECT * FROM `' . $this->db->table('jet_cct_revisiones_servicios') . '` WHERE _ID = ?', [$id]);
    if (!$row) throw new \DomainException('Revisión no encontrada.');
    $snapshot = (new PublicServicesReviewStorage($this->db))->snapshot($id);
    $context = $snapshot['context'] ?? $row;
    $context['fecha'] = self::timestamp($row['fecha'] ?? $row['cct_created'] ?? '');
    $services = $snapshot['services'] ?? [];
    if (!$snapshot) {
      $repo = new PendingRepository($this->db);
      $employee = $repo->getFuncionarioById((string) ($row['id_empleado'] ?? ''));
      $employee = $employee ? $repo->getFuncionarioByUserId((int) $employee['_ID']) : null;
      $context['realizado_por'] = trim((string) ($row['realizado_por'] ?? '')) ?: ($employee['nombre'] ?? 'Sin registrar');
      $context['realizado_por_cargo'] = $employee['nombre_cargo'] ?? '';
      $context['realizado_por_telefono'] = $employee['telefono'] ?? '';
      foreach (['energia'=>['luz','nic','Energía eléctrica'],'agua'=>['agua','poliza','Acueducto y alcantarillado'],'gas'=>['gas','numero_contrato','Gas natural']] as $key=>$map) {
        [$suffix,$account,$label]=$map;
        if (trim((string) ($row['resultado_tiempo_'.$suffix] ?? '')) === '' && self::timestamp($row['fecha_revision_'.$suffix] ?? 0) === 0) continue;
        $services[$key] = ['display_label'=>$label,'label'=>$label,'account'=>$row[$account]??'','meter'=>$row['medidor_'.$suffix]??'', 'status'=>$row['resultado_tiempo_'.$suffix]??'', 'amount'=>(int) ($row['resultado_valores_'.$suffix]??0)];
      }
    }
    foreach ($services as $key=>&$service) {
      $suffix = $key === 'energia' ? 'luz' : $key;
      $service['reviewed_at'] = self::timestamp($row['fecha_revision_'.$suffix] ?? $context['fecha']);
      $service['cutoff_at'] = self::timestamp($row['fecha_corte_'.$suffix] ?? 0);
    }
    unset($service);
    return ['review'=>$row,'context'=>$context,'services'=>$services,'documents'=>self::documents($row)];
  }

  public static function documents(array $row): array
  {
    $documents = [];
    foreach (['energia'=>['luz','Energía'],'agua'=>['agua','Agua'],'gas'=>['gas','Gas']] as $key=>$map) {
      foreach (['felicitaciones'=>'Reconocimiento','mora'=>'Mora'] as $kind=>$label) {
        $raw = $row['acta_'.$kind.'_'.$map[0]] ?? '';
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        $decoded = json_decode($value, true);
        if (is_array($decoded)) $value = (string) ($decoded['url'] ?? '');
        if (!filter_var($value,FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($value,PHP_URL_SCHEME)),['https','http'],true)) continue;
        $documents[$key] = ['url'=>$value,'title'=>'Acta de '.$label.' · '.$map[1]];
      }
    }
    return $documents;
  }

  public static function timestamp(mixed $value): int
  {
    if (is_numeric($value)) { $ts=(int)$value; return $ts>9999999999 ? (int)floor($ts/1000) : max(0,$ts); }
    return strtotime((string)$value) ?: 0;
  }

  public static function preview(array $template, string $type): string
  {
    $status = ['al_dia'=>'Al dia','30_dias'=>'30 dias','60_dias'=>'60 dias','critico'=>'Estado critico'][$type];
    return PublicServicesDocument::act(['fecha'=>time(),'contrato'=>'123','inmueble'=>'10156','direccion'=>'Dirección del inmueble de ejemplo','arrendatario'=>'Arrendatario de ejemplo','propietario'=>'Propietario de ejemplo','realizado_por'=>'Funcionario de ejemplo','realizado_por_cargo'=>'Coordinador de servicios','realizado_por_telefono'=>'Teléfono registrado','representante_legal'=>'Representante legal configurado'],
      ['label'=>'energía','display_label'=>'Energía eléctrica','account'=>'123456','meter'=>'987654','status'=>$status,'amount'=>$type==='al_dia'?0:350000],$template);
  }
}
