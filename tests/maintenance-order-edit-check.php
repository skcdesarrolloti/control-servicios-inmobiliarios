<?php
declare(strict_types=1);

// Execute the real save handler against isolated storage and queue adapters.
// Each child process exits through the handler's actual JSON response boundary.
namespace SCM\Core {
  final class Auth {
    public static function userId(): int { return 7; }
    public static function user(): string { return 'Editor'; }
  }
}
namespace SCM\Support {
  final class SchemaInspector {
    public function __construct($db) {}
    public function tableExists(string $table): bool { return true; }
    public function columnExists(string $table, string $column): bool { return true; }
    public function filterTableData(string $table, array $data): array { return $data; }
  }
  final class EmailQueue {
    public static array $jobs = [];
    public function __construct($db) {}
    public function enqueue($to, $subject, $html, $options): int {
      self::$jobs[] = compact('to', 'subject', 'html', 'options'); return 1;
    }
  }
  final class SmsQueue {
    public static array $jobs = [];
    public function __construct($db) {}
    public function enqueue($phone, $name, $message, $options): bool {
      self::$jobs[] = compact('phone', 'name', 'message', 'options'); return true;
    }
  }
}
namespace SCM\Modules\AdministrativeNotifications {
  final class AdministrativeNotificationsService {
    public function __construct($db) {}
    public function senderProfile(): array { return ['signature_line' => 'Editor - Coordinador - Cel. 3001234567']; }
  }
}
namespace {
  define('SCM_BASE_URL', 'https://example.com');
  define('SCM_APP_SECRET', 'isolated-test-secret');
  require dirname(__DIR__) . '/src/App/Concerns/HandlesMaintenanceActions.php';
  function esc_html($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
  function esc_url($v): string { return (string) $v; }
  function sanitize_text_field($v): string { return trim(strip_tags((string) $v)); }
  function wp_unslash($v) { return $v; }
  function sanitize_email($v): string { return filter_var($v, FILTER_SANITIZE_EMAIL); }
  function wp_strip_all_tags($v): string { return strip_tags((string) $v); }

  final class MemoryDb {
    public array $quote = ['_ID'=>'570', 'estado'=>'Aprobada', 'saldo_materiales'=>'700000', 'saldo_obra'=>'200000', 'saldo_maquinarias'=>'0', 'saldo_otros_costo'=>'0', 'id_ticket'=>'10841'];
    public array $order = ['_ID'=>'98', 'id_cotizacion'=>'570', 'categoria'=>'Materiales', 'valor'=>'300000', 'estado'=>'Aprobada', 'creador'=>'Creador original', 'email_creador'=>'original@example.com', 'cct_created'=>'2026-09-01 10:00:00', 'cct_modified'=>'2026-09-01 10:00:00', 'fecha'=>123, 'cct_author_id'=>'25', 'id_empleado'=>'25', 'autorizador'=>'Autorizador', 'id_autorizador'=>'30'];
    public array $histories = [];
    public array $locks = [];
    public bool $transaction = false;
    public bool $raceResponse = false;
    private array $snapshot = [];
    public function pdo(): self { return $this; }
    public function beginTransaction(): void { $this->transaction=true; $this->snapshot=[$this->quote,$this->order,$this->histories]; }
    public function commit(): void { $this->transaction=false; }
    public function inTransaction(): bool { return $this->transaction; }
    public function rollBack(): void { [$this->quote,$this->order,$this->histories]=$this->snapshot; $this->transaction=false; }
    public function table(string $name): string { return $name; }
    public function getRow(string $sql, array $args): ?array {
      if (str_contains($sql,'FOR UPDATE')) $this->locks[]=$sql;
      if (str_contains($sql,'jet_cct_cotizacion_mantenimiento')) return $this->quote;
      if (str_contains($sql,'jet_cct_ordenes')) return count($args) === 1 || (string) $args[1] === $this->order['id_cotizacion'] ? $this->order : null;
      if (str_contains($sql,'jet_cct_funcionarios')) return ['nombre'=>'Editor','correo'=>'editor@example.com','celular'=>'3001234567'];
      if (str_contains($sql,'jet_cct_tickets')) return ['_ID'=>'10841'];
      return null;
    }
    public function getVar($sql, $args): int { return 5; }
    public function update(string $table, array $data, array $where): int {
      if ($table==='jet_cct_ordenes') $this->order=array_merge($this->order,$data);
      if ($table==='jet_cct_cotizacion_mantenimiento') $this->quote=array_merge($this->quote,$data);
      return 1;
    }
    public function insert(string $table, array $data): bool {
      if ($table==='jet_cct_ordenes') $this->order=array_merge($data,['_ID'=>'99']);
      else $this->histories[]=$data;
      return true;
    }
    public function lastInsertId(): string { return '99'; }
    public function prepare(string $sql): MemoryStatement {
      if ($this->raceResponse) $this->order['cct_modified']='2026-10-01 11:00:00';
      return new MemoryStatement($this, $sql);
    }
  }
  final class MemoryStatement {
    private int $affected=0;
    public function __construct(private MemoryDb $db, private string $sql) {}
    public function execute(array $args): void {
      $where=array_slice($args,-3);
      if ($where !== [$this->db->order['_ID'], $this->db->order['estado'], $this->db->order['cct_modified']]) return;
      preg_match_all('/`([^`]+)` = \?/',explode(' WHERE ', $this->sql)[0],$fields);
      $this->db->order=array_merge($this->db->order,array_combine($fields[1],array_slice($args,0,-3)));
      $this->affected=1;
    }
    public function rowCount(): int { return $this->affected; }
  }
  final class Handler {
    use \SCM\App\Concerns\HandlesMaintenanceActions;
    public function __construct(public MemoryDb $db) {}
    private function verifyCsrf(): void {}
    private function canUseDashboardAction($action): bool { return true; }
    private function canAccessDashboardTab($tab): bool { return true; }
    private function table_exists($table): bool { return true; }
    private function current_employee_id(): string { return '77'; }
    private function format_cop_currency($v): string { return '$'.number_format((float) $v,0,',','.'); }
    private function maintenance_quote_number($v): float { return (float) $v; }
    private function maintenance_quote_whatsapp_text($v): string { return (string) $v; }
    private function maintenance_quote_whatsapp_url_button_suffix($v): string { return 'orden-publica.php?numero=98&sig=test'; }
    private function internalNotificationRecipientsForAction($action): array { return ['4']; }
    private function internalNotificationFuncionarioOptions(): array {
      return [['id'=>'4','name'=>'Aprobador','email'=>'approval@example.com','phone'=>'3002223344'], ['id'=>'6','name'=>'No seleccionado','email'=>'excluded@example.com','phone'=>'3009999988']];
    }
    private function base_url($path=''): string { return 'https://example.com/'.$path; }
    private function jsonOk(array $data): void { $this->output(true,$data); }
    private function jsonFail(string $message): void { $this->output(false,['message'=>$message]); }
    private function output(bool $success, array $data): void {
      echo json_encode(['success'=>$success,'data'=>$data,'quote'=>$this->db->quote,'order'=>$this->db->order,'histories'=>$this->db->histories,'locks'=>$this->db->locks,'email'=>\SCM\Support\EmailQueue::$jobs,'whatsapp'=>\SCM\Support\SmsQueue::$jobs],JSON_THROW_ON_ERROR); exit;
    }
  }
  if (isset($argv[1])) {
    $db=new MemoryDb();
    $_POST=['id_cotizacion'=>'570','id_orden'=>'98','id_proveedor'=>'5','categoria'=>'Materiales','concepto'=>'Trabajo ajustado','valor'=>'200000','proveedor'=>'Proveedor','identificacion_proveedor'=>'123','correo_proveedor'=>'provider@example.com','celular_proveedor'=>'3003334455','titular_proveedor'=>'Titular','identificacion_cuenta_proveedor'=>'123','cuenta_proveedor'=>'456','correo_pago_proveedor'=>'pay@example.com'];
    $_POST['order_version']=hash('sha256',json_encode($db->order,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    switch ($argv[1]) {
      case 'increase': $_POST['valor']='500000'; break;
      case 'over': $_POST['valor']='1000001'; break;
      case 'category': $_POST['categoria']='Mano de obra'; $_POST['valor']='150000'; break;
      case 'stale': $_POST['order_version']='old-version'; break;
      case 'foreign': $db->order['id_cotizacion']='571'; break;
      case 'unapproved': $db->quote['estado']='Esperando respuesta'; break;
      case 'create_after_acta': $_POST['id_orden']='0'; $db->quote['id_acta_satisfaccion']='10'; break;
      case 'no_balance': $_POST['id_orden']='0'; $db->quote['saldo_materiales']='0'; $db->quote['saldo_obra']='0'; break;
    }
    if (str_starts_with($argv[1], 'response_')) {
      $db->order['estado']='Esperando respuesta';
      $_POST['estado']='Aprobada';
      $_POST['order_version']=$argv[1]==='response_stale' ? 'old-version' : $db->order['cct_modified'];
      $db->raceResponse=$argv[1]==='response_race';
      (new Handler($db))->ajax_handler_cotizacion_order_response(); exit;
    }
    (new Handler($db))->ajax_handler_cotizacion_order_save(); exit;
  }
  $run=static function(string $case): array {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case);
    return json_decode((string) shell_exec($cmd),true,512,JSON_THROW_ON_ERROR);
  };
  $check=static function(bool $ok,string $label): void { if (!$ok) throw new \RuntimeException($label); echo 'OK '.$label.PHP_EOL; };
  $decrease=$run('decrease');
  $check($decrease['success'] && $decrease['quote']['saldo_materiales']==='800000','decrease returns the difference to quote balance');
  $check($decrease['order']['_ID']==='98' && $decrease['order']['creador']==='Creador original' && $decrease['order']['id_empleado']==='25','edit preserves number and creator identity');
  $check($decrease['order']['estado']==='Esperando respuesta' && $decrease['order']['autorizador']==='' && $decrease['order']['id_autorizador']==='','edit resets approval');
  $check(count($decrease['locks'])===2 && count($decrease['histories'])===2 && $decrease['histories'][0]['id_empleado']==='77','quote and order lock, with editor in both histories');
  $check(count($decrease['email'])===1 && count($decrease['whatsapp'])===1 && $decrease['email'][0]['to']===['approval@example.com'],'approval is requested again only from configured officials in both channels');
  $again=$run('decrease');
  $check($again['whatsapp'][0]['options']['dedupe_key']!==$decrease['whatsapp'][0]['options']['dedupe_key'],'each edit has a distinct notification generation');
  $increase=$run('increase');
  $check($increase['success'] && $increase['quote']['saldo_materiales']==='500000','increase consumes only the difference');
  $category=$run('category');
  $check($category['success'] && $category['quote']['saldo_materiales']==1000000 && $category['quote']['saldo_obra']==='50000','category transfer restores original category and charges new category');
  foreach (['over','stale','foreign','unapproved','no_balance'] as $case) {
    $result=$run($case);
    $check(!$result['success'] && $result['order']['valor']==='300000' && $result['email']===[] && $result['whatsapp']===[],$case.' rejected without changing order or notifying');
  }
  $afterActa=$run('create_after_acta');
  $check($afterActa['success'] && $afterActa['order']['_ID']==='99' && $afterActa['quote']['saldo_materiales']==='500000','approved quote with remaining balance allows a new order after satisfaction act');
  $approval=$run('response_current');
  $check($approval['success'] && $approval['order']['estado']==='Aprobada','current pending version can be approved');
  foreach (['response_stale','response_race'] as $case) {
    $result=$run($case);
    $check(!$result['success'] && $result['order']['estado']==='Esperando respuesta' && $result['email']===[],$case.' cannot approve a changed order');
  }
}
