<?php
declare(strict_types=1);
namespace SCM\Support;

use SCM\Core\Database;

final class QuotationFinancingService
{
  public function __construct(private Database $db, private SchemaInspector $schema) {}

  /** $term: meses a los que se financia (obligatorio con "Sí"). */
  public function update(int $id, string $value, array $actor, int $term = 0): void
  {
    if ($id <= 0 || !in_array($value, ['Si','No'], true)) throw new \DomainException('Selecciona Sí o No para el financiamiento.');
    $credits = new \SCM\Modules\OwnerCredits\OwnerCreditService($this->db, null, $this->schema);
    if ($value === 'Si') $credits->assertQuoteTerm($id, $term);
    $employee = trim((string) ($actor['employee_id'] ?? ''));
    if ($employee === '') throw new \DomainException('No se pudo identificar al funcionario.');
    $table = $this->db->table('jet_cct_cotizacion_mantenimiento');
    if (!$this->schema->tableExists($table) || !$this->schema->columnExists($table, 'financiacion')) throw new \DomainException('No está disponible el financiamiento de cotizaciones.');
    $pdo = $this->db->pdo();
    $pdo->beginTransaction();
    try {
      $quote = $this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ?" . ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), [$id]);
      if (!$quote) throw new \DomainException('La cotización ya no existe.');
      $sent = in_array(strtolower(trim((string) ($quote['se_envio'] ?? ''))), ['si', 'sí', '1', 'true', 'enviada', 'enviado'], true) || (int) ($quote['fecha_envio'] ?? 0) > 0
        || in_array(strtolower(trim((string) ($quote['estado'] ?? ''))), ['aprobada', 'desaprobada'], true);
      if (!$sent) throw new \DomainException('El financiamiento se configura después de enviar la cotización.');
      $old = trim((string) ($quote['financiacion'] ?? ''));
      if ($old !== $value) {
        $now = date('Y-m-d H:i:s');
        $this->db->update($table, $this->schema->filterTableData($table, ['financiacion'=>$value,'cct_modified'=>$now]), ['_ID'=>$id]);
        $historyTable = $this->db->table('jet_cct_historial_del_ticket');
        if ($this->schema->tableExists($historyTable)) {
          $message = 'Financiamiento de cotización #' . $id . ': ' . ($old !== '' ? $old : 'Sin definir') . ' → ' . $value . ($value === 'Si' ? ' a ' . $term . ' meses' : '') . '.';
          $history = $this->schema->filterTableData($historyTable, [
            'cct_status'=>'publish','cct_author_id'=>$employee,'id_empleado'=>$employee,
            'cct_created'=>$now,'cct_modified'=>$now,'fecha'=>time(),'id_ticket'=>$quote['id_ticket'] ?? '',
            'id_cotizacion_mantenimiento'=>(string)$id,'nombre'=>$actor['name'] ?? '',
            'respuesta'=>$message,'respuesta_cct_ticket'=>$message,'fue_editada'=>'Si',
          ]);
          if ($history !== []) $this->db->insert($historyTable, $history);
        }
      }
      $pdo->commit();
    } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    // Con "Sí" también se sincroniza si solo cambió el plazo.
    if ($old !== $value || $value === 'Si') $credits->syncFromQuote($id, $value, $actor, $value === 'Si' ? $term : 0);
  }
}
