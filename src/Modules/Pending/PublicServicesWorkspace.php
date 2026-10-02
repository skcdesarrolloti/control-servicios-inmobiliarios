<?php
declare(strict_types=1);

namespace SCM\Modules\Pending;

use SCM\Core\Database;

final class PublicServicesWorkspace
{
  public function __construct(private Database $db) {}

  public static function tabs(): string
  {
    return '<nav class="scm-services-tabs" aria-label="Servicios públicos"><button type="button" data-services-tab="pending" aria-pressed="true">Pendientes</button><button type="button" data-services-tab="templates" aria-pressed="false">Plantillas de actas</button><button type="button" data-services-tab="history" aria-pressed="false">Revisiones realizadas</button></nav>';
  }

  public function templates(string $type = 'al_dia'): string
  {
    if (!isset(PublicServicesActTemplates::TYPES[$type])) $type = 'al_dia';
    $template = (new PublicServicesActTemplates($this->db))->all()[$type];
    return self::templateEditor($type, $template);
  }

  public static function templateEditor(string $type, array $template): string
  {
    $e = [PublicServicesDocument::class, 'e'];
    $html = '<div class="scm-filter-card scm-services-editor"><h3>Contenido de las actas</h3><p>Los cambios se aplican a las próximas revisiones. Las actas ya emitidas conservan su contenido y PDF original.</p><form data-services-template-form>'
      . '<input type="hidden" name="version" value="' . $e($template['version']) . '"><label>Tipo de acta<select name="type" data-services-template-type data-loaded-type="' . $e($type) . '">';
    foreach (PublicServicesActTemplates::TYPES as $key => $label) $html .= '<option value="' . $key . '"' . ($key === $type ? ' selected' : '') . '>' . $e($label) . '</option>';
    $html .= '</select></label><label>Título<input name="title" maxlength="240" required value="' . $e($template['title']) . '"></label><label>Contenido<textarea name="body" rows="20" maxlength="20000" required>' . $e($template['body']) . '</textarea></label><p>Variables disponibles: selecciona una para insertarla en el contenido.</p><div class="scm-services-variables">';
    foreach (PublicServicesActTemplates::VARIABLES as $variable) $html .= '<button type="button" data-services-variable="{{' . $variable . '}}">{{' . $variable . '}}</button>';
    $html .= '</div><div class="scm-actions"><button type="button" class="scm-btn-secondary" data-services-template-preview>Vista previa</button><button type="submit" class="scm-btn-primary">Guardar plantilla</button></div><p data-services-template-status role="status" aria-live="polite"></p></form>';
    if (!empty($template['updated_at'])) $html .= '<small>Última actualización: ' . date('d/m/Y H:i', (int) $template['updated_at']) . ' · ' . $e($template['updated_name'] ?? '') . '</small>';
    return $html . '</div>';
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
    $rows = $this->db->getResults('SELECT * FROM `' . $table . '` WHERE ' . $sqlWhere . ' ORDER BY fecha DESC, _ID DESC LIMIT 30 OFFSET ' . (($page - 1) * 30), $args);
    $html = '<div class="scm-filter-card"><h3>Revisiones realizadas</h3><form data-services-history-form><div class="scm-services-history-filters">';
    foreach (['contrato'=>'Contrato','inmueble'=>'Inmueble SIMI','arrendatario'=>'Arrendatario','propietario'=>'Propietario','from'=>'Desde','to'=>'Hasta'] as $key=>$label) $html .= '<label>' . $label . '<input name="' . $key . '" type="' . (in_array($key,['from','to'],true)?'date':'text') . '" value="' . $e($input[$key] ?? '') . '"></label>';
    $html .= '</div><div class="scm-actions"><button type="submit" class="scm-btn-primary">Filtrar</button><button type="button" class="scm-btn-secondary" data-services-history-clear>Limpiar</button></div></form></div><p>' . $total . ' revisiones · Página ' . $page . ' de ' . $pages . '</p><div class="scm-table-wrap"><table class="scm-table scm-table-prev"><thead><tr><th>Revisión</th><th>Fecha</th><th>Contrato / Inmueble</th><th>Dirección / Arrendatario</th><th>Realizado por</th><th>Acciones</th></tr></thead><tbody>';
    foreach ($rows as $row) {
      $url = PublicServicesDocument::url((int) $row['_ID']);
      $html .= '<tr><td>#' . $e($row['_ID']) . '</td><td>' . date('d/m/Y', self::timestamp($row['fecha'] ?? '')) . '</td><td>#' . $e($row['contrato'] ?? '') . '<br>SIMI ' . $e($row['inmueble'] ?? '') . '</td><td>' . $e($row['direccion'] ?? '') . '<br>' . $e($row['arrendatario'] ?? '') . '</td><td>' . $e($row['realizado_por'] ?? '') . '</td><td><div class="scm-services-history-actions"><button type="button" class="scm-pending-action-btn" data-scm-open-iframe data-iframe-url="' . $e($url) . '" data-iframe-title="Revisión de servicios públicos">Ver revisión y actas</button><button type="button" class="scm-pending-action-btn" data-services-copy-url="' . $e($url) . '">Copiar enlace público</button>';
      foreach (self::documents($row) as $document) $html .= '<button type="button" class="scm-pending-action-btn" data-scm-open-iframe data-iframe-url="' . $e($document['url']) . '" data-iframe-title="' . $e($document['title']) . '">' . $e($document['title']) . '</button>';
      $html .= '</div></td></tr>';
    }
    if (!$rows) $html .= '<tr><td colspan="6">No hay revisiones con estos filtros.</td></tr>';
    $html .= '</tbody></table></div><div class="scm-actions"><button type="button" class="scm-btn-secondary" data-services-history-page="' . ($page-1) . '"' . ($page<=1?' disabled':'') . '>Anterior</button><button type="button" class="scm-btn-secondary" data-services-history-page="' . ($page+1) . '"' . ($page>=$pages?' disabled':'') . '>Siguiente</button></div>';
    return $html;
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
