<?php
declare(strict_types=1);
namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Core\Settings;
use SCM\Support\FuncionarioOptions;
use SCM\Support\SchemaInspector;
use SCM\Support\SharedNotificationsBridge;

/** Critical reviews create native cases; legacy evidence remains read-only. */
final class PublicServicesCritical
{
  public const HOURS = 72;
  public const WORKFLOW = 'ticket-v1';
  public const EVENT = 'servicios_publicos_critico';
  public const RESPONSE_EVENT = 'servicios_publicos_pago_reportado';
  /** Legacy settings key, retained for compatibility; calendar recipients use EVENT. */
  public const CALENDAR_EVENT = 'servicios_publicos_critico_calendario';
  private ?\Closure $calendarHttp;
  private ?bool $schemaAvailable = null;
  private ?SharedNotificationsBridge $notificationBridge = null;
  private static ?\WeakMap $verifiedSchemas = null;
  public function __construct(private Database $db, ?callable $calendarHttp = null) { $this->calendarHttp=$calendarHttp?\Closure::fromCallable($calendarHttp):null; }
  public function table(string $suffix = 'cases'): string { return $this->db->table('scm_services_critical_' . $suffix); }
  public function schema(): array
  {
    return [
      'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` (review_id BIGINT UNSIGNED PRIMARY KEY, contract_id BIGINT UNSIGNED NOT NULL, created_at BIGINT NOT NULL, deadline_at BIGINT NOT NULL, status VARCHAR(32) NOT NULL DEFAULT \'pending\', planned TINYINT NOT NULL DEFAULT 0, payload_json MEDIUMTEXT NOT NULL, KEY due_status(status,deadline_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
      'CREATE TABLE IF NOT EXISTS `' . $this->table('payments') . '` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, review_id BIGINT UNSIGNED NOT NULL, request_key CHAR(64) NOT NULL UNIQUE, path VARCHAR(500) NOT NULL, sha256 CHAR(64) NOT NULL, size_bytes INT NOT NULL, note TEXT NOT NULL, created_at BIGINT NOT NULL, UNIQUE KEY same_file(review_id,sha256), KEY review_id(review_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
      'CREATE TABLE IF NOT EXISTS `' . $this->table('audit') . '` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, review_id BIGINT UNSIGNED NOT NULL, action VARCHAR(40) NOT NULL, actor VARCHAR(50) NOT NULL, details_json TEXT NOT NULL, created_at BIGINT NOT NULL, KEY review_id(review_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
      'CREATE TABLE IF NOT EXISTS `' . $this->table('jobs') . '` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, review_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, dedupe_key VARCHAR(191) NOT NULL UNIQUE, payload_json MEDIUMTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'pending\', attempts INT NOT NULL DEFAULT 0, available_at BIGINT NOT NULL, locked_until BIGINT NOT NULL DEFAULT 0, last_error TEXT NULL, result_json TEXT NULL, KEY work(status,available_at,locked_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
  }
  public function available(): bool
  {
    if($this->schemaAvailable!==null)return $this->schemaAvailable;
    $schema=new SchemaInspector($this->db);
    foreach(['cases','payments','audit','jobs'] as $suffix)if(!$schema->tableExists($this->table($suffix)))return $this->schemaAvailable=false;
    return $this->schemaAvailable=true;
  }
  public function case(int $id, bool $lock = false): ?array
  {
    if (!$this->available()) return null;
    $case=$this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE review_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
    if($case){
      $p=json_decode($case['payload_json'],true,32,JSON_THROW_ON_ERROR);
      if(!empty($p['ticket_id'])){
        $case['ticket']=$this->db->getRow('SELECT * FROM `'.$this->db->table('jet_cct_tickets').'` WHERE _ID=?',[(int)$p['ticket_id']])??[];
        $case['ticket_id']=(int)$p['ticket_id'];
        $case['status']=PublicServicesCriticalTicket::open($case['ticket'])?'pending':'closed';
      }
    }
    return $case;
  }
  public function requireSchema(): void
  {
    self::$verifiedSchemas??=new \WeakMap();
    if(!empty(self::$verifiedSchemas[$this->db->pdo()]))return;
    if(!$this->available())throw new \DomainException('Ejecuta bin/migrate-public-services.php para preparar el seguimiento crítico.');
    $tables=array_map(fn($suffix)=>$this->table($suffix),['cases','payments','audit','jobs']);
    $engines=$this->db->getCol('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)',$tables);
    if(count($engines)!==4||array_filter($engines,static fn($e)=>strcasecmp((string)$e,'InnoDB')!==0))throw new \DomainException('El seguimiento crítico necesita tablas InnoDB para conservar la operación completa.');
    self::$verifiedSchemas[$this->db->pdo()]=true;
  }
  public function config(): array
  {
    $cfg=(array)(new Settings($this->db,true))->get('public_services_critical',[]);
    if(($cfg['workflow']??'')!==self::WORKFLOW){$cfg['whatsapp_enabled']=false;$cfg['whatsapp_template']='';}
    return $cfg + ['whatsapp_template'=>'','language'=>'es','whatsapp_enabled'=>false,'button_base'=>rtrim((string) SCM_BASE_URL, '/') . '/'];
  }
  public function saveConfig(array $input, array $actor): void
  {
    if(!self::admin($actor))throw new \DomainException('Solo los administradores pueden configurar el seguimiento crítico.');
    $cfg=$this->config();
    foreach(['whatsapp_template','language'] as $key){
      $value=trim((string)($input[$key]??''));
      if($value!==''&&!preg_match('/^[a-zA-Z0-9_]{1,100}$/D',$value))throw new \DomainException('Nombre de plantilla o idioma no válido.');
      $cfg[$key]=$value;
    }
    $cfg['whatsapp_enabled']=!empty($input['whatsapp_enabled']);
    if($cfg['whatsapp_enabled']&&(!$cfg['whatsapp_template']||!$cfg['language']))throw new \DomainException('Configura la plantilla de revisión crítica aprobada y su idioma antes de activar WhatsApp.');
    if($cfg['whatsapp_enabled']&&$cfg['whatsapp_template']==='scm_servicios_critico_72h')throw new \DomainException('Usa una plantilla de revisión con botón Ver revisión; la anterior solicita reportar pagos.');
    $cfg['workflow']=self::WORKFLOW;
    unset($cfg['response_template']);
    $cfg['button_base']=rtrim((string)SCM_BASE_URL,'/').'/';
    $settings=new Settings($this->db);$pdo=$this->db->pdo();$pdo->beginTransaction();
    try{
      $settings->set('public_services_critical',$cfg,(int)$actor['id_empleado']);
      $events=(array)$settings->get('internal_admin_notifications',[]);
      $active=array_column(FuncionarioOptions::panelFuncionarios($this->db,new SchemaInspector($this->db),'primary',[]),'id');
      foreach([self::EVENT] as $event){
        $selected=array_values(array_unique(array_map('strval',(array)($input[$event]??[]))));
        if(array_diff($selected,$active))throw new \DomainException('Selecciona únicamente funcionarios activos.');
        $events[$event]=array_map('intval',$selected);
      }
      $settings->set('internal_admin_notifications',$events,(int)$actor['id_empleado']);
      $settings->set('public_services_critical_updated',['employee_id'=>$actor['id_empleado'],'name'=>$actor['nombre'],'at'=>time()],(int)$actor['id_empleado']);
      $pdo->commit();
      $this->db->pdo()->exec('UPDATE `'.$this->table('jobs').'` SET available_at=0 WHERE status=\'pending\' AND kind=\'whatsapp\'');
      \SCM\Core\App::settings()->refresh();
    }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
  public function configHtml(): string
  {
    $googleEmployeeIds = array_column($this->connectedCalendarContacts(),'employee_id');
    return PublicServicesUi::render('critical-config',['config'=>$this->config(),'contacts'=>FuncionarioOptions::panelFuncionarios($this->db,new SchemaInspector($this->db),'primary',[]),'events'=>(array)(new Settings($this->db,true))->get('internal_admin_notifications',[]),'googleEmployeeIds'=>$googleEmployeeIds]);
  }
  public static function admin(array $employee): bool { return !empty($employee['id_empleado']) && in_array((string)($employee['id_cargo']??''),['11','12','13','14'],true); }
  public function detailHtml(int $id): string
  {
    $case=$this->case($id);if(!$case)throw new \DomainException('Esta revisión no tiene seguimiento crítico.');
    $payload=json_decode($case['payload_json'],true,32,JSON_THROW_ON_ERROR);
    $payments=$this->db->getResults('SELECT * FROM `'.$this->table('payments').'` WHERE review_id=? ORDER BY id DESC',[$id]);
    $audit=$this->db->getResults('SELECT * FROM `'.$this->table('audit').'` WHERE review_id=? ORDER BY id DESC',[$id]);
    $jobs=$this->db->getResults('SELECT kind,status,last_error,attempts FROM `'.$this->table('jobs').'` WHERE review_id=? ORDER BY id',[$id]);
    $actor=(new PendingRepository($this->db))->getFuncionarioByUserId(\SCM\Core\Auth::userId());
    $canVerify=self::admin($actor??[]);
    return PublicServicesUi::render('critical-detail',compact('case','payload','payments','audit','jobs','canVerify'));
  }
  public function contacts(string $event): array
  {
    $settings = (array) (new Settings($this->db, true))->get('internal_admin_notifications', []);
    $ids = array_map('strval', (array) ($settings[$event] ?? []));
    return array_values(array_filter(FuncionarioOptions::panelFuncionarios($this->db, new SchemaInspector($this->db), 'primary', []), static fn($f)=>in_array((string) $f['id'], $ids, true)));
  }
  /** Query connection metadata only; OAuth credentials stay inside the calendar app. */
  public function connectedCalendarContacts(): array
  {
    $schema = new SchemaInspector($this->db);
    if (!$schema->tableExists('calendario_google_accounts')) throw new \RuntimeException('No está disponible la tabla de cuentas Google del calendario.');
    $ids = $this->db->getCol('SELECT id_empleado FROM calendario_google_accounts WHERE COALESCE(google_email,\'\')<>\'\' AND (COALESCE(refresh_token_enc,\'\')<>\'\' OR (COALESCE(access_token_enc,\'\')<>\'\' AND expires_at>NOW()))');
    $connected = array_fill_keys(array_map('strval', $ids), true);
    return array_values(array_filter(FuncionarioOptions::panelFuncionarios($this->db, $schema, 'primary', []), static fn($f)=>isset($connected[(string)$f['employee_id']])));
  }
  /** Called inside the review transaction; all external effects happen after commit. */
  public function record(array $result): ?int
  {
    $services = array_filter($result['_services'], static fn($s)=>$s['status']==='Estado critico');
    if (!$services) return null;
    if (!$this->available()) throw new \DomainException('Prepara el esquema de seguimiento crítico con bin/migrate-public-services.php antes de guardar una revisión crítica.');
    $this->requireSchema();
    $id = (int) $result['review_id']; $created = (int) $result['_context']['fecha'];
    $documents=[];foreach($services as $s){$key=$s['debt_document_field'];if(isset($result['_documents'][$key]))$documents[$key]=$result['_documents'][$key];}
    $payload = ['contract'=>$result['_contract'], 'employee'=>$result['_employee'], 'services'=>$services,'documents'=>$documents];
    $payload['workflow']=self::WORKFLOW;
    $payload['ticket_id']=(new PublicServicesCriticalTicket($this->db))->create($id,$payload,$created,$created+self::HOURS*3600);
    $this->db->insert($this->table(), ['review_id'=>$id,'contract_id'=>(int)$result['_contract']['_ID'],'created_at'=>$created,'deadline_at'=>$created+self::HOURS*3600,'payload_json'=>self::json($payload)]);
    $this->audit($id, 'critical_ticket_created', (string)$result['_employee']['id_empleado'], ['ticket_id'=>$payload['ticket_id'],'deadline_at'=>$created+self::HOURS*3600,'services'=>array_keys($services)]);
    return $payload['ticket_id'];
  }
  public static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
  private function audit(int $id, string $action, string $actor, array $details): void
  {
    $this->db->insert($this->table('audit'), ['review_id'=>$id,'action'=>$action,'actor'=>$actor,'details_json'=>self::json($details),'created_at'=>time()]);
  }
  public static function url(int $id, ?int $expires = null): string
  {
    return PublicServicesDocument::url($id,$expires);
  }
  public static function signature(int $id,int $expires): string { return hash_hmac('sha256','services-payment|'.$id.'|'.$expires,(string)SCM_APP_SECRET); }
  public static function valid(int $id,int $expires,string $sig): bool { return $id>0 && $expires>time() && hash_equals(self::signature($id,$expires),$sig); }
  private function internalRecipients(array $payload, string $event): array
  {
    $contacts = $this->contacts($event);
    if($event===self::RESPONSE_EVENT)$contacts=array_merge($contacts,$this->contacts(self::EVENT));
    $e = $payload['employee'];
    $contacts[] = ['name'=>$e['nombre'],'email'=>$e['correo']??'','phone'=>$e['celular']??$e['telefono']??'','employee_id'=>$e['id_empleado']];
    return $contacts;
  }
  public function summary(array $payload): string
  {
    $parts=[];
    foreach ($payload['services'] as $s) $parts[]=($s['display_label']??$s['label']).' · referencia '.$s['account'].' · $'.number_format((int)$s['amount'],0,',','.').' COP';
    return implode('; ', $parts);
  }
  private function job(int $id,string $kind,string $key,array $payload,int $at=0): void
  {
    $this->db->pdo()->prepare('INSERT IGNORE INTO `'.$this->table('jobs').'` (review_id,kind,dedupe_key,payload_json,available_at) VALUES (?,?,?,?,?)')->execute([$id,$kind,$key,self::json($payload),$at?:time()]);
  }
  public function plan(int $id): void
  {
    $pdo=$this->db->pdo(); $pdo->beginTransaction();
    try {
      $case=$this->case($id,true);
      if (!$case || empty($case['ticket_id']) || $case['planned'] || $case['status']==='closed' || $case['status']==='verified') { $pdo->commit(); return; }
      $p=json_decode($case['payload_json'],true,32,JSON_THROW_ON_ERROR); $contract=$p['contract'];
      $recipients=$this->internalRecipients($p,self::EVENT);
      $recipients[]=['name'=>$contract['arrendatario'],'email'=>$contract['correo_arrendatario']??'','phone'=>$contract['celular_arrendatario']??$contract['telefono_arrendatario']??''];
      $details='Caso #'.(int)($p['ticket_id']??0).' · '.$this->summary($p); $deadline=date('d/m/Y H:i',(int)$case['deadline_at']).' (Colombia)'; $link=self::url($id, (int)$case['created_at']+180*86400);
      foreach ($recipients as $r) {
        $body='Revisión crítica #'.$id.' · Contrato #'.$contract['contrato'].' · '.$contract['direccion']."\n".$details."\nCaso de servicios públicos críticos con plazo de atención de 72 horas. Vence ".$deadline."\nRealizado por: ".$p['employee']['nombre'];
        $this->notifyJobs($case,$r,$body,$link,$p['documents'],false);
      }
      $selectedCalendarContacts = $this->contacts(self::EVENT);
      $connected = $selectedCalendarContacts ? $this->connectedCalendarContacts() : [];
      $googleIds = array_fill_keys(array_column($connected,'employee_id'), true);
      $calendarRecipients = [];
      foreach ($selectedCalendarContacts as $r) $calendarRecipients[(string)$r['employee_id']] = $r;
      foreach ($calendarRecipients as $r) {
        $ref='services-critical:'.$id.':employee:'.$r['employee_id'];
        $this->job($id,'calendar',$ref.':ticket',['tipo_item'=>'recordatorio','titulo'=>'Caso servicios públicos críticos · contrato #'.$contract['contrato'],'descripcion'=>$details.' · Plazo de atención del caso: 72 horas. '.PublicServicesDocument::url($id),'recordatorio_at'=>date('Y-m-d H:i:s',(int)$case['deadline_at']),'id_empleado'=>(string)$r['employee_id'],'creado_por'=>(string)$p['employee']['id_empleado'],'origen_app'=>'control-servicios-inmobiliarios','external_ref'=>$ref,'recordatorio_canal'=>'email','sincronizar_google'=>isset($googleIds[(string)$r['employee_id']]),'meta'=>['review_id'=>$id,'deadline_at'=>(int)$case['deadline_at'],'ticket_id'=>(int)($p['ticket_id']??0),'workflow'=>self::WORKFLOW]]);
      }
      $this->db->update($this->table(), ['planned'=>1], ['review_id'=>$id]);
      $pdo->commit();
    } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
  }
  private function notifyJobs(array $case,array $r,string $body,string $url,array $docs,bool $response,int $paymentId=0): void
  {
    $id=(int)$case['review_id']; $base='services-critical:'.$id.':revision-ticket';
    $email=strtolower(trim((string)($r['email']??'')));
    if (filter_var($email,FILTER_VALIDATE_EMAIL)) $this->job($id,'email',$base.':email:'.hash('sha256',$email),['destination'=>$email,'destination_name'=>$r['name'],'body'=>$body,'url'=>$url,'documents'=>$docs,'response'=>false,'workflow'=>self::WORKFLOW]);
    else $this->audit($id,'contact_missing','system',['recipient'=>$r['name'],'channel'=>'email']);
    $phone=preg_replace('/\D/','',(string)($r['phone']??'')); if(strlen($phone)===10)$phone='57'.$phone;
    if(!preg_match('/^[1-9][0-9]{7,14}$/D',$phone)) { $this->audit($id,'contact_missing','system',['recipient'=>$r['name'],'channel'=>'whatsapp']); return; }
    foreach ($docs as $key=>$doc) {
      $this->job($id,'whatsapp',$base.':wa:'.$phone.':'.$key,['destination'=>'+'.$phone,'destination_name'=>$r['name'],'body'=>$body,'url'=>$url,'document'=>$doc,'response'=>false,'workflow'=>self::WORKFLOW]);
    }
  }
  public function report(int $id,array $file,string $note,string $requestKey): int
  {
    throw new \DomainException('El reporte público de pagos fue retirado. La atención se gestiona desde el caso de servicios públicos críticos.');
  }
  public static function validatePdf(string $path): void
  {
    $size=is_file($path)?filesize($path):0;
    if($size<8||$size>10*1024*1024)throw new \DomainException('El PDF debe pesar máximo 10 MB.');
    $fp=fopen($path,'rb'); $head=fread($fp,5); fseek($fp,max(0,$size-2048)); $tail=stream_get_contents($fp); fclose($fp);
    if((new \finfo(FILEINFO_MIME_TYPE))->file($path)!=='application/pdf'||$head!=='%PDF-'||!str_contains($tail,'%%EOF'))throw new \DomainException('El archivo no es un PDF válido.');
  }
  public static function evidenceUrl(int $id,?int $expires=null): string
  {
    $expires??=time()+180*86400;
    return rtrim((string)SCM_BASE_URL,'/').'/evidencia-pago-servicios.php?id='.$id.'&expires='.$expires.'&sig='.hash_hmac('sha256','services-evidence|'.$id.'|'.$expires,(string)SCM_APP_SECRET);
  }
  public static function validEvidence(int $id,int $expires,string $sig): bool { return $id>0&&$expires>time()&&hash_equals(hash_hmac('sha256','services-evidence|'.$id.'|'.$expires,(string)SCM_APP_SECRET),$sig); }
  public function verify(int $id,string $decision,string $reason,array $actor): void
  {
    throw new \DomainException('Gestiona y cierra el caso desde Servicios inmobiliarios. La verificación de pagos fue retirada.');
  }
  /** Convert only open legacy follow-ups, retaining their original deadline and evidence. */
  public function upgradeOpenCases(?int $reviewId=null): int
  {
    $changed=0;
    $rows=$this->db->getResults('SELECT review_id FROM `'.$this->table().'` WHERE status NOT IN (\'verified\',\'ticket_closed\') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,\'$.workflow\')),\'\')<>\'ticket-v1\''.($reviewId!==null?' AND review_id='.(int)$reviewId:'').' ORDER BY review_id LIMIT 20');
    foreach($rows as $row){
      $pdo=$this->db->pdo();$pdo->beginTransaction();
      try{
        $case=$this->case((int)$row['review_id'],true);$p=json_decode($case['payload_json'],true,32,JSON_THROW_ON_ERROR);
        if(($p['workflow']??'')===self::WORKFLOW){$pdo->commit();continue;}
        $id=(int)$case['review_id'];
        $p['legacy_evidence']=$this->db->getResults('SELECT id FROM `'.$this->table('payments').'` WHERE review_id=?',[$id]);
        $p['ticket_id']=(new PublicServicesCriticalTicket($this->db))->create($id,$p,(int)$case['created_at'],(int)$case['deadline_at']);
        $p['workflow']=self::WORKFLOW;
        $this->db->update($this->table(),['payload_json'=>self::json($p),'status'=>'pending','planned'=>0],['review_id'=>$id]);
        $this->audit($id,'legacy_converted_to_ticket',(string)$p['employee']['id_empleado'],['ticket_id'=>$p['ticket_id'],'deadline_preserved'=>(int)$case['deadline_at']]);
        $pdo->prepare('UPDATE `'.$this->table('jobs').'` SET status=\'cancelled\', locked_until=0 WHERE review_id=? AND status IN (\'pending\',\'processing\')')->execute([$id]);
        $pdo->commit();$changed++;
      }catch(\Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!(int)$this->db->getVar('SELECT COUNT(*) FROM `'.$this->table('audit').'` WHERE review_id=? AND action=\'legacy_conversion_failed\'',[$row['review_id']]))$this->audit((int)$row['review_id'],'legacy_conversion_failed','system',['error'=>$e->getMessage()]);
        error_log('[services-critical-conversion] '.$row['review_id'].': '.$e->getMessage());
      }
    }
    return $changed;
  }
  /** Closing a native case closes reminders, without modifying either ticket status. */
  public function syncTicketClosures(): void
  {
    foreach($this->db->getCol('SELECT review_id FROM `'.$this->table().'` WHERE status NOT IN (\'verified\',\'ticket_closed\')') as $id){
      $case=$this->case((int)$id);
      if(empty($case['ticket_id']) || $case['status']!=='closed')continue;
      $pdo=$this->db->pdo();$pdo->beginTransaction();
      try{
        $case=$this->case((int)$id,true);
        if($case['status']!=='closed'){$pdo->commit();continue;}
        foreach($this->db->getResults('SELECT payload_json FROM `'.$this->table('jobs').'` WHERE review_id=? AND kind=\'calendar\'',[$id]) as $j){
          $p=json_decode($j['payload_json'],true,32,JSON_THROW_ON_ERROR);
          $this->job((int)$id,'calendar_close','services-critical:'.$id.':close:'.$p['id_empleado'],$p);
        }
        $pdo->prepare('UPDATE `'.$this->table('jobs').'` SET status=\'cancelled\' WHERE review_id=? AND kind=\'calendar\' AND status=\'pending\'')->execute([$id]);
        $this->db->update($this->table(),['status'=>'ticket_closed'],['review_id'=>$id]);
        $this->audit((int)$id,'native_ticket_closed','system',['ticket_id'=>$case['ticket_id']]);
        $pdo->commit();
      }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
  }
  /** Durable domain jobs enqueue through the one shared transport queue. No direct messages. */
  public function run(int $limit=30,?callable $calendarTransport=null,bool $notificationsOnly=false,?int $reviewId=null): array
  {
    if(!$this->available())return ['processed'=>0,'failed'=>0];
    if($this->db->pdo()->inTransaction())throw new \LogicException('Procesa las integraciones después de confirmar la operación.');
    // NotificationQueue ensures its schema using DDL. Boot before any transaction,
    // otherwise MySQL could implicitly commit the payment/job being processed.
    $this->notificationBridge ??= new SharedNotificationsBridge($this->db);
    try{$this->notificationBridge->queue();}catch(\Throwable $e){error_log('[services-critical-queue] '.$e->getMessage());}
    $this->retirePaymentJobs();
    $this->upgradeOpenCases($reviewId);
    $this->syncTicketClosures();
    foreach($this->db->getCol('SELECT review_id FROM `'.$this->table().'` WHERE planned=0'.($reviewId!==null?' AND review_id='.(int)$reviewId:'').' LIMIT 20') as $id){
      try{$this->plan((int)$id);}catch(\Throwable $e){error_log('[services-critical-plan] '.$e->getMessage());}
    }
    $stats=['processed'=>0,'failed'=>0];$pdo=$this->db->pdo();
    for($i=0;$i<$limit;$i++){
      $pdo->beginTransaction();
      $job=$this->db->getRow('SELECT * FROM `'.$this->table('jobs').'` WHERE (status=\'pending\' OR (status=\'processing\' AND locked_until<?)) AND available_at<=?'.($notificationsOnly?' AND kind IN (\'email\',\'whatsapp\')':'').($reviewId!==null?' AND review_id='.(int)$reviewId:'').' ORDER BY id LIMIT 1 FOR UPDATE',[time(),time()]);
      if(!$job){$pdo->commit();break;}
      $this->db->update($this->table('jobs'),['status'=>'processing','locked_until'=>time()+120,'attempts'=>(int)$job['attempts']+1],['id'=>$job['id']]);$pdo->commit();
      try{
        $p=json_decode($job['payload_json'],true,32,JSON_THROW_ON_ERROR);
        if(str_starts_with($job['kind'],'calendar')){
          $result=$calendarTransport?$calendarTransport($job['kind'],$p):$this->calendar($job['kind'],$p);
        }else{
          $pdo->beginTransaction();
          $result=$this->enqueue($job,$p);
        }
        $this->db->update($this->table('jobs'),['status'=>'done','locked_until'=>0,'last_error'=>null,'result_json'=>self::json((array)$result)],['id'=>$job['id']]);
        if($pdo->inTransaction())$pdo->commit();$stats['processed']++;
      }catch(\Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $this->db->update($this->table('jobs'),['status'=>'pending','locked_until'=>0,'available_at'=>time()+min(3600,60*(1+(int)$job['attempts'])),'last_error'=>mb_substr($e->getMessage(),0,1000)],['id'=>$job['id']]);$stats['failed']++;
      }
    }
    return $stats;
  }
  private function retirePaymentJobs(): void
  {
    $bridge=$this->notificationBridge;
    foreach($this->db->getResults('SELECT id,dedupe_key,payload_json,status FROM `'.$this->table('jobs').'` WHERE kind IN (\'email\',\'whatsapp\') AND status<>\'cancelled\'') as $job){
      $p=json_decode($job['payload_json'],true);
      if(($p['workflow']??'')===self::WORKFLOW)continue;
      if($bridge && (new SchemaInspector($this->db))->tableExists($bridge->queueTable())){
        $this->db->pdo()->prepare('UPDATE `'.$bridge->queueTable().'` SET status=\'cancelled\' WHERE project_code=? AND source_module=\'public-services-critical\' AND dedupe_key=? AND status=\'pending\'')->execute([$bridge->projectCode(),$job['dedupe_key']]);
      }
      if(in_array($job['status']??'', ['pending','processing'], true))$this->db->update($this->table('jobs'),['status'=>'cancelled','last_error'=>'Flujo sustituido por caso nativo de servicios públicos críticos.'],['id'=>$job['id']]);
    }
  }
  private function enqueue(array $job,array $p): array
  {
    $bridge=$this->notificationBridge;$queue=$bridge?->queue();if(!$queue)throw new \RuntimeException('La cola compartida no está disponible.');
    $existing=$this->db->getVar('SELECT id FROM `'.$bridge->queueTable().'` WHERE project_code=? AND dedupe_key=? LIMIT 1',[$bridge->projectCode(),$job['dedupe_key']]);
    if($existing)return ['queue_id'=>(int)$existing,'replayed'=>true];
    $options=['project_code'=>$bridge->projectCode(),'source_module'=>'public-services-critical','dedupe_key'=>$job['dedupe_key'],'destination_name'=>$p['destination_name'],'priority'=>1,'max_attempts'=>5,'meta'=>['review_id'=>(int)$job['review_id'],'critical_job_id'=>(int)$job['id'],'workflow'=>self::WORKFLOW,'ticket_id'=>(int)($this->case((int)$job['review_id'])['ticket_id']??0)]];
    if($job['kind']==='email'){
      $attachments=[];foreach($p['documents'] as $d)if(!empty($d['path']))$attachments[]=['path'=>$d['path'],'name'=>$d['filename']??$d['attachment_name']??basename($d['path'])];
      $options['payload']=['attachments'=>$attachments];
      $html='<p>'.nl2br(PublicServicesDocument::e($p['body'])).'</p><p><a href="'.PublicServicesDocument::e($p['url']).'">'.'Ver revisión'.'</a></p>';
      return ['queue_ids'=>$queue->enqueueEmail([$p['destination']],'Revisión de servicios públicos críticos · caso con plazo de 72 horas',$html,$options)];
    }
    $cfg=$this->config();$template=$cfg['whatsapp_template'];
    if(!$cfg['whatsapp_enabled']||!$template)throw new \RuntimeException('WhatsApp pendiente: configura y activa las plantillas aprobadas por Meta en Plantillas de actas.');
    $case=$this->case((int)$job['review_id']);$data=json_decode($case['payload_json'],true);
    $buttonBase=(string)$cfg['button_base'];
    if(!str_starts_with($p['url'],$buttonBase))throw new \RuntimeException('La base del botón de Meta no coincide con el enlace público.');
    $values=[$p['destination_name'],(string)$data['contract']['contrato'],mb_substr($p['body'],0,900),date('d/m/Y H:i',(int)$case['deadline_at']).' Colombia',$data['employee']['nombre']];
    $components=[['type'=>'header','parameters'=>[['type'=>'document','document'=>['link'=>$p['document']['url'],'filename'=>$p['document']['filename']??$p['document']['attachment_name']??'acta.pdf']]]],['type'=>'body','parameters'=>array_map(static fn($v)=>['type'=>'text','text'=>preg_replace('/\s+/u',' ',(string)$v)],$values)],['type'=>'button','sub_type'=>'url','index'=>'0','parameters'=>[['type'=>'text','text'=>substr($p['url'],strlen($buttonBase))]]]];
    return ['queue_id'=>$queue->enqueueWhatsAppOfficialTemplate($p['destination'],$template,$components,$options+['template_language'=>$cfg['language'],'message_text'=>$p['body']])];
  }
  private function calendar(string $kind,array $p): array
  {
    if($kind==='calendar' && !in_array((string)$p['id_empleado'], array_column($this->contacts(self::EVENT),'employee_id'), true)) return ['cancelled'=>true,'reason'=>'Funcionario no configurado en Notificaciones internas para Revisión crítica · 72 horas.'];
    if($kind==='calendar_close' && (int)$this->db->getVar('SELECT COUNT(*) FROM `'.$this->table('jobs').'` WHERE review_id=? AND kind=\'calendar\' AND status=\'processing\'',[(int)$p['meta']['review_id']])>0)throw new \RuntimeException('Esperando confirmar la creación del recordatorio antes de cerrarlo.');
    if($kind==='calendar' && in_array($this->case((int)$p['meta']['review_id'])['status']??'', ['closed','verified'],true))return ['cancelled'=>true];
    $rows=$this->calendarRequest('listar_items_calendario',['tipo_item'=>'recordatorio','id_empleado'=>$p['id_empleado'],'origen_app'=>$p['origen_app'],'external_ref'=>$p['external_ref'],'limite'=>100]);
    $list=$rows['items']??$rows;
    $existing=null;foreach((array)$list as $r)if(is_array($r)&&($r['external_ref']??'')===$p['external_ref']){$existing=$r;break;}
    if($kind==='calendar_close'){
      return $existing?$this->calendarRequest('actualizar_recordatorio_estado',['id_recordatorio'=>$existing['id'],'estado'=>'cancelado']):['no_reminder'=>true];
    }
    if($existing){
      // Update an existing reminder too, so converted cases lose the old payment wording.
      $result=$this->calendarRequest('actualizar_recordatorio',['id_recordatorio'=>$existing['id']]+$p);
      $result = (array)($result['item']??[]) + $result;
    }else $result=$this->calendarRequest('crear_recordatorio',$p);
    $result = (array)($result['item']??[]) + $result;
    if(!empty($p['sincronizar_google']) && empty($result['google_event_id']))throw new \RuntimeException('Recordatorio guardado; Google Calendar pendiente. Revisa la conexión Google del funcionario.');
    return $result;
  }
  private function calendarRequest(string $action,array $p): array
  {
    if($this->calendarHttp){
      $result=($this->calendarHttp)($action,$p);
      if(!is_array($result)||empty($result['success']))throw new \RuntimeException('No se confirmó la operación del calendario. Se reintentará.');
      return (array)($result['data']??[]);
    }
    $base=(string)(getenv('SCM_CALENDAR_API_URL')?:'https://sucasainmobiliaria.com.co/calendario-actividades/index.php?action=');
    if(!str_starts_with($base,'https://'))throw new \RuntimeException('La API del calendario requiere HTTPS.');
    $ch=curl_init($base.$action);$headers=['Content-Type: application/json'];$key=(string)(getenv('SCM_CALENDAR_API_KEY')?:'');if($key!=='')$headers[]='X-SKC-Calendar-Key: '.$key;
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>self::json($p),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25]);
    $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $result=is_string($raw)?json_decode($raw,true):null;
    if($status<200||$status>=300||!is_array($result)||empty($result['success']))throw new \RuntimeException('No se confirmó la operación del calendario. Se reintentará.');
    return (array)($result['data']??[]);
  }
}
