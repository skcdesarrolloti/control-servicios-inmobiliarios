<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Core/Autoloader.php';
\SCM\Core\Autoloader::register(dirname(__DIR__) . '/src');
define('SCM_BASE_URL', 'https://example.test');
$pdo = new PDO('sqlite::memory:');
$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value);
$pdo->exec('CREATE TABLE wp_scm_ticket_completion_acts (id INTEGER PRIMARY KEY, ticket_pk INTEGER, legacy_act_id INTEGER, payload_json TEXT, status TEXT, signed_at TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE wp_jet_cct_actas_de_satisfaccion (_ID INTEGER PRIMARY KEY)');
$subject = new class(new \SCM\Core\Database($pdo)) {
  use \SCM\App\Concerns\RendersDashboard;
  private const DEFAULT_ACTA_URL = 'https://example.test/legacy?numero=';
  public function __construct(private \SCM\Core\Database $db) {}
  private function table_exists(string $table): bool { return (bool) $this->db->getRow("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]); }
  public function lookup(string $quote, string $legacy = '', string $ticket = '10841'): array { return $this->cotizacion_satisfaction_act_info($quote, $legacy, $ticket); }
};
$checks = 0;
$assert = static function(bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; $checks++; };
$insert = $pdo->prepare('INSERT INTO wp_scm_ticket_completion_acts VALUES (?,?,?,?,?,?,?)');
$insert->execute([1,10841,0,'{"source":{"quote_id":"999"}}','signed','2026-10-01','2026-10-01']);
$assert($subject->lookup('570','262')['url'] === '', 'deleted legacy reference and unrelated act do not create a link');
$insert->execute([2,10841,263,'{"source":{"quote_id":"570"}}','signed','2026-10-01','2026-10-01']);
$assert($subject->lookup('570')['id'] === 2, 'exact quotation selects its signed act');
$assert($subject->lookup('570','','999')['url'] === '', 'another ticket cannot supply the act');
$pdo->exec('DELETE FROM wp_scm_ticket_completion_acts WHERE id=2');
$assert($subject->lookup('570','263')['url'] === '', 'permanent deletion removes the quotation link');
$insert->execute([3,10841,0,'{"source":{"quote_id":"570"}}','archived',null,'2026-10-01']);
$assert($subject->lookup('570')['status'] === 'archived', 'archived act remains identifiable for history management');
$insert->execute([4,10841,0,'{"source":{"quote_id":"570"}}','pending',null,'2026-10-01']);
$assert($subject->lookup('570')['id'] === 4, 'pending version takes precedence over retired history');
$pdo->exec('DELETE FROM wp_scm_ticket_completion_acts');
$pdo->exec('INSERT INTO wp_jet_cct_actas_de_satisfaccion VALUES (264)');
$assert($subject->lookup('570','263,264')['id'] === 264, 'legacy lists resolve an existing document only');
$insert->execute([5,10841,0,'{"source":{"quote_id":"570"}}','superseded','2026-10-01','2026-10-01']);
$assert($subject->lookup('570')['url'] === '', 'historical signed version cannot replace a deleted current quotation act');
$pdo->exec('DROP TABLE wp_scm_ticket_completion_acts');
$assert($subject->lookup('570','263')['url'] === '', 'missing native table cannot resurrect deleted legacy act');
$assert($subject->lookup('570','264')['status'] === 'legacy', 'existing legacy act remains available');
$service = new \SCM\Modules\TicketCompletion\CompletionService(new \SCM\Modules\TicketCompletion\CompletionRepository(new \SCM\Core\Database($pdo)), str_repeat('x',32), SCM_BASE_URL);
$payload = ['source'=>['flow'=>'approved_quote','quote_id'=>'570'], 'signer'=>['role'=>'propietario','name'=>'QA','email'=>'qa@example.invalid','phone'=>''], 'items'=>[['damage'=>'Daño','solution'=>'Solución']], 'channels'=>['email']];
$json = json_encode($payload, JSON_THROW_ON_ERROR);
$act = ['id'=>7,'ticket_pk'=>10841,'status'=>'pending','payload_json'=>$json,'payload_hash'=>hash('sha256',$json),'expires_at'=>time()+3600,'delivery_json'=>'{}','cancellation_reason'=>'Prueba'];
$context = ['ticket'=>['_ID'=>10841,'id_ticket'=>'10841','estado'=>'En proceso','inmueble'=>'204578','contrato'=>'2000'], 'acts'=>[$act], 'contacts'=>[], 'source_flow'=>$payload['source']];
$view = new \SCM\Modules\TicketCompletion\CompletionView();
$pending = $view->panel($context,$service);
$assert(str_contains($pending,'data-acta-edit="7"') && str_contains($pending,'data-acta-archive="7"') && !str_contains($pending,'data-acta-delete="7"'), 'pending management supports inline edit and archive with restricted deletion');
$assert(str_contains($view->panel($context,$service,0,true,true),'data-acta-delete="7"'), 'administrative scope exposes confirmed deletion');
$edit = $view->panel($context,$service,7);
$assert(str_contains($edit,'data-acta-operation="update"') && str_contains($edit,'value="570"'), 'editing opens existing act and preserves quotation association');
$context['acts'][0]['status']='archived';
$archived = $view->panel($context,$service);
$assert(str_contains($archived,'data-acta-status="archived"') && str_contains($archived,'data-acta-delete="7"') && !str_contains($archived,'data-acta-edit="7"'), 'retired history shows status and delete, without editing retired act');
echo "$checks checks passed.\n";
