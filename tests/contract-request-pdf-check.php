<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App/Concerns/HandlesTicketWorkflowActions.php';
date_default_timezone_set('America/Bogota');
$directory = getenv('SCM_CONTRACT_ACTA_QA_DIR') ?: sys_get_temp_dir() . '/scm-contract-actas-qa';
if (!is_dir($directory)) mkdir($directory, 0750, true);
define('SCM_UPLOAD_PATH', $directory);
define('SCM_RESOURCES_PATH', dirname(__DIR__) . '/resources');
define('SCM_BASE_URL', 'https://example.invalid');
define('SCM_APP_SECRET', 'synthetic-test-only');
define('SCM_UPLOAD_MAX_BYTES', 10485760);

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function literal(string $text): string {
  return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], iconv('UTF-8','Windows-1252//TRANSLIT',$text));
}
final class ContractLetterProbe
{
  use \SCM\App\Concerns\HandlesTicketWorkflowActions;
  public function generate(string $kind, string $term, array $ticket): array {
    $request = '2026-10-08'; $end = '2027-01-09';
    $text = $kind === 'terminacion'
      ? $this->contractTerminationResponseText($ticket,$term,$request,$end)
      : $this->contractNonRenewalResponseText($ticket,$term,$request,$end);
    $details = 'Gestión de arrendamientos | Cel. [teléfono del funcionario] | SKC SuCasa Inmobiliaria';
    return $kind === 'terminacion'
      ? $this->generateContractTerminationActa($ticket,$term,$text,$request,$end,'Funcionario de ejemplo',$details)
      : $this->generateContractNonRenewalActa($ticket,$term,$text,'Funcionario de ejemplo',$details);
  }
}
$probe = new ContractLetterProbe();
$ticket = ['_ID'=>10916,'id_ticket'=>'EJEMPLO-10916','contrato'=>'2000','inmueble'=>'204578','id_inmueble'=>'84016','id_inmueble_data'=>'12466','solicitante'=>'ARRENDATARIO DE EJEMPLO','direccion'=>'Calle de Ejemplo No. 10-20, apartamento 301, Cartagena'];
foreach (['terminacion','no-prorroga'] as $kind) foreach (['dentro','fuera'] as $term) {
  $result = $probe->generate($kind,$term,$ticket);
  $bytes = file_get_contents($result['path']);
  check(str_starts_with($bytes,'%PDF-') && str_contains($bytes,'/Subtype /Image'),'Valid PDF with institutional letterhead');
  foreach (['GESTIÓN CONTRACTUAL','Respuesta emitida','SKC SuCasa Inmobiliaria','Página 1','Ticket #','Inmueble 84016'] as $removed) check(!str_contains($bytes,literal($removed)),"Removed {$removed}");
  check(str_contains($bytes,literal('Caso #EJEMPLO-10916 · Contrato #2000 · Inmueble SIMI: 204578')),'Case label and SIMI, never web/internal ID');
  check(str_contains($result['title'],'Caso #') && !str_contains($result['title'],'Ticket #'),'Attachment title also uses Caso');
  check(str_contains($bytes,literal('Soluciones Comerciales y Constructivas Sas.')),'Legal corporate name rendered with spaces');
  foreach (['Cartagena de Indias D.T. y C., '.date('d/m/Y'),'Señor(a): ARRENDATARIO DE EJEMPLO','Cordial saludo.'] as $bold) {
    check(preg_match('/BT \/F2 9 Tf [^\r\n]*\(' . preg_quote(literal($bold),'/') . '\) Tj/', $bytes) === 1,'Bold opening: '.$bold);
  }
  check(substr_count($bytes,literal('Cordial saludo.')) === 1,'Greeting appears once in all four variants');
  check(str_contains($bytes,'(Atentamente)') && str_contains($bytes,'(Funcionario de ejemplo)'),'Signature identity retained');
  check(substr_count($bytes,'/Type /Page ') === 1,'Representative letter fits one page');
  rename($result['path'],$directory.'/acta-'.$kind.'-'.$term.'-de-termino.pdf');
}
// Missing SIMI must not silently expose the web or internal property ID.
$missing = $ticket; unset($missing['inmueble']);
$result = $probe->generate('terminacion','fuera',$missing);
$bytes = file_get_contents($result['path']);
check(str_contains($bytes,'Inmueble SIMI: -') && !str_contains($bytes,'84016') && !str_contains($bytes,'12466'),'No fallback to a different identifier');
unlink($result['path']);
// Opt-in document settings must not remove other modules' default footer.
$pdf = new \SCM\Support\SimplePdf(); $pdf->paragraph('Documento de otro módulo');
check(str_contains($pdf->bytes(),literal('Página 1')),'Other documents retain page number');
echo "PASS: all four letters, removed decorations/footer, legal name, bold openings, SIMI selection, signature, single-page layout and shared defaults. PDFs: {$directory}\n";
