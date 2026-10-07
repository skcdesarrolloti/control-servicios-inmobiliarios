<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
$options = getopt('', ['ticket:', 'apply']);
$reference = (string) ($options['ticket'] ?? '');
if (!ctype_digit($reference) || (int) $reference < 1) { fwrite(STDERR, "Indica --ticket=ID. Sin --apply solo muestra la revisión.\n"); exit(1); }
$db = \SCM\Core\App::db();
$pdo = $db->pdo();
try {
  $pdo->beginTransaction();
  $table = $db->table('jet_cct_tickets');
  $ticket = $db->getRow("SELECT * FROM `{$table}` WHERE id_ticket = ? LIMIT 1 FOR UPDATE", [$reference]);
  if (!$ticket || !in_array(mb_strtolower(trim((string) $ticket['tema_ayuda'])), ['retencion de contrato', 'retención de contrato'], true)) throw new RuntimeException('No es un ticket de retención.');
  $old = (string) $ticket['descripcion'];
  if (str_contains($old, '<strong>Gestión comercial de retención</strong>')) { $pdo->rollBack(); echo "La descripción ya está actualizada.\n"; exit; }
  $text = trim(html_entity_decode(strip_tags($old), ENT_QUOTES, 'UTF-8'));
  if (!preg_match('/^Se crea ticket comercial de retención de contrato (?:a partir de la (.+?) respondida en el ticket #(\d+)|desde el control de (.+?))\./u', $text, $origin)) throw new RuntimeException('Descripción personalizada: no se modifica automáticamente.');
  $contract = $db->getRow("SELECT * FROM `{$db->table('jet_cct_contratos_arrendamiento')}` WHERE `_ID` = ?", [(int) $ticket['id_contrato']]);
  if (!$contract) throw new RuntimeException('Contrato no encontrado.');
  preg_match('/Clasificación de la solicitud:\s*([^\.\n]+)\./u', $text, $classification);
  $documents = @unserialize((string) ($ticket['archivos'] ?? ''), ['allowed_classes' => false]);
  $document = is_array($documents) && isset($documents[0]) && is_array($documents[0]) ? $documents[0] : [];
  $end = $ticket['fecha_terminacion_contrato'] ?: ($contract['fin_contrato'] ?? '');
  $endTs = is_numeric($end) ? (int) ((float) $end > 9999999999 ? (float) $end / 1000 : $end) : (int) strtotime((string) $end);
  $description = \SCM\Modules\Contracts\ContractRetentionDescription::build([
    'source' => ($origin[1] ?? '') ?: ($origin[3] ?? 'solicitud contractual'), 'source_ticket' => $origin[2] ?? '',
    'status' => $classification[1] ?? '', 'contract' => $contract['contrato'] ?? '',
    'property' => $contract['inmueble'] ?? '', 'address' => $contract['direccion'] ?? '',
    'tenant' => $contract['arrendatario'] ?? '', 'end_date' => $endTs > 0 ? date('d/m/Y', $endTs) : '',
    'document_url' => $document['archivo'] ?? $document['media_archivo'] ?? '', 'document_title' => $document['nombre_archivo'] ?? '',
  ]);
  if (!array_key_exists('apply', $options)) {
    $pdo->rollBack(); echo json_encode(['ticket' => $reference, 'can_repair' => true, 'old_characters' => mb_strlen($text), 'new_characters' => mb_strlen(strip_tags($description))], JSON_THROW_ON_ERROR) . PHP_EOL; exit;
  }
  $now = date('Y-m-d H:i:s');
  $db->insert($db->table('jet_cct_historial_del_ticket'), [
    'cct_status' => 'publish', 'id_ticket' => (int) $ticket['_ID'], 'fecha' => time(),
    'nombre' => 'Sistema · Corrección de descripción de retención', 'cct_author_id' => 0, 'id_empleado' => '0',
    'cct_created' => $now, 'cct_modified' => $now, 'fue_editada' => 'Si',
    'respuesta' => '<p>Descripción resumida y organizada. La respuesta completa se conserva en el caso original y su documento.</p><details><summary>Descripción anterior conservada</summary><div>' . nl2br(htmlspecialchars($old, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div></details>',
  ]);
  if ($db->update($table, ['descripcion' => $description, 'cct_modified' => $now], ['_ID' => (int) $ticket['_ID']]) !== 1) throw new RuntimeException('No se pudo actualizar la descripción.');
  $pdo->commit(); echo "Descripción del ticket #{$reference} corregida con historial.\n";
} catch (Throwable $exception) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, $exception->getMessage() . PHP_EOL); exit(1);
}
