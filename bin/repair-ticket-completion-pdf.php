<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';

$options = getopt('', ['id:', 'expected-sha256:', 'output:', 'apply', 'reason:']);
try {
  $id = (int) ($options['id'] ?? 0);
  if ($id < 1 || empty($options['expected-sha256']) || empty($options['output'])) throw new DomainException('Uso: php bin/repair-ticket-completion-pdf.php --id=9 --expected-sha256=HASH --output=RUTA.pdf [--apply --reason="Motivo"]');
  $output = (string) $options['output'];
  if (is_file($output)) throw new DomainException('El archivo de salida ya existe; usa otra ruta para conservarlo.');
  $repo = new \SCM\Modules\TicketCompletion\CompletionRepository(\SCM\Core\App::db());
  $service = new \SCM\Modules\TicketCompletion\CompletionService($repo, SCM_APP_SECRET, SCM_BASE_URL);
  $repair = new \SCM\Modules\TicketCompletion\CompletionPdfRepair($service, SCM_APP_SECRET);
  $candidate = $repair->prepare($id, (string) $options['expected-sha256']);
  if (file_put_contents($output, $candidate['pdf'], LOCK_EX) === false) throw new RuntimeException('No se pudo guardar el PDF de revisión.');
  if (isset($options['apply'])) {
    if (trim((string) ($options['reason'] ?? '')) === '') throw new DomainException('Para aplicar se requiere --reason.');
    $repo->db->pdo()->exec($repair->schemaSql());
    $repair->apply($candidate, (string) $options['reason']);
    echo "Presentación reparada. Original conservado en auditoría; firma y caso sin cambios.\n";
  } else {
    echo "PDF de revisión generado. No se modificó la base de datos.\n";
  }
  echo 'SHA-256: ' . $candidate['pdf_hash'] . "\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
