<?php
declare(strict_types=1);
namespace SCM\Modules\Pending;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;

/** Native case and both histories are part of the locked review transaction. */
final class PublicServicesCriticalTicket
{
  public const TOPIC = 'Servicio publico critico';
  public function __construct(private Database $db) {}

  public function create(int $reviewId, array $payload, int $created, int $deadline): int
  {
    if (!$this->db->pdo()->inTransaction()) throw new \LogicException('El caso crítico debe guardarse dentro de la operación de revisión.');
    $schema = new SchemaInspector($this->db);
    $tables = array_map([$this->db, 'table'], ['jet_cct_tickets','jet_cct_historial_del_ticket','jet_cct_historial_del_inmueble']);
    foreach ($tables as $table) {
      if (!$schema->tableExists($table)) throw new \DomainException('No está disponible el esquema de casos e historial.');
      $definition=$this->db->getRow('SHOW CREATE TABLE `'.$table.'`');
      if(!preg_match('/\bENGINE=InnoDB\b/i',(string)($definition['Create Table']??''))) throw new \DomainException('Ejecuta bin/migrate-public-services.php: los casos y sus historiales deben usar InnoDB.');
    }
    $c=$payload['contract']; $e=$payload['employee'];
    $actor=(string)($e['id_empleado']??'');
    if ($actor==='' || $actor==='0' || empty($c['id_inmueble'])) throw new \DomainException('Faltan el funcionario real o el inmueble del caso crítico.');
    $registered=time(); $mysql=date('Y-m-d H:i:s',$registered);
    $description='Revisión de servicios públicos #'.$reviewId.'. Contrato #'.$c['contrato'].'. '.(new PublicServicesCritical($this->db))->summary($payload)
      .'. Caso con plazo de atención de 72 horas desde el registro de la revisión. Vence '.date('d/m/Y H:i',$deadline).' (Colombia).';
    $descriptionHtml='<p>'.PublicServicesDocument::e($description).'</p><p><a href="'.PublicServicesDocument::e(PublicServicesDocument::url($reviewId)).'">Ver revisión de servicios públicos y actas</a></p>';
    $files=[]; foreach($payload['documents'] as $doc) if(!empty($doc['url'])) $files[]=['nombre_archivo'=>$doc['title']??'Acta de revisión crítica','media_archivo'=>$doc['url'],'archivo'=>$doc['url']];
    foreach($payload['legacy_evidence']??[] as $evidence) $files[]=['nombre_archivo'=>'Soporte histórico #'.(int)$evidence['id'],'media_archivo'=>PublicServicesCritical::evidenceUrl((int)$evidence['id']),'archivo'=>PublicServicesCritical::evidenceUrl((int)$evidence['id'])];
    $ticket=array_intersect_key($c,array_flip(['contrato','id_inmueble','inmueble','direccion','barrio','id_arrendatario','arrendatario','correo_arrendatario','celular_arrendatario','id_propietario','propietario','correo_propietario','celular_propietario','id_inventario','sucursal']));
    $ticket=array_replace($ticket,[
      'cct_status'=>'publish','cct_author_id'=>$actor,'cct_created'=>$mysql,'cct_modified'=>$mysql,
      'id_contrato'=>(string)$c['_ID'],'estado'=>'Nuevo','estado_administrativo'=>'Nuevo',
      'tema_ayuda'=>self::TOPIC,'asunto'=>self::TOPIC.' · revisión #'.$reviewId.' · contrato #'.$c['contrato'],
      'descripcion'=>$descriptionHtml,'departamento'=>'Servicio al arrendatario','prioridad'=>'Prioridad urgente',
      'fecha'=>$registered,'fecha_actualizacion'=>$registered,'creador_por'=>'Funcionario','id_creador'=>(string)($e['_ID']??$actor),
      'id_empleado'=>$actor,'id_asignado'=>$actor,'empleado'=>$e['nombre'],'nombre_empleado'=>$e['nombre'],
      'correo_empleado'=>$e['correo']??'','celular_empleado'=>$e['celular']??$e['telefono']??'',
      'nombre_creador_ticket'=>$e['nombre'],'cargo_creador_ticket'=>$e['nombre_cargo']??'',
      'celular_creador_ticket'=>$e['celular']??$e['telefono']??'','correo_creador_ticket'=>$e['correo']??'',
      'solicitante_tipo'=>'arrendatario','id_solicitante'=>$c['id_arrendatario']??'',
      'solicitante'=>$c['arrendatario'],'correo_solicitante'=>$c['correo_arrendatario']??'',
      'celular_solicitante'=>$c['celular_arrendatario']??'','archivos'=>serialize($files),
      'tuvo_seguimiento'=>'No','tuvo_reporte'=>'No',
    ]);
    foreach(['estado_rev_entrega','estado_acta_entrega','estado_acta_desocupacion','estado_rev_correctiva','estado_rev_preventiva','estado_cotizacion_mantenimiento','estado_acta_satisfaccion','estado_rev_recibo','estado_acta_recibo'] as $flag) $ticket[$flag]='No';
    if(array_diff(['id_ticket','tema_ayuda','id_inmueble','id_contrato','estado','estado_administrativo'], $schema->getTableColumns($tables[0]))) throw new \DomainException('El esquema de casos está incompleto.');
    $this->db->insert($tables[0],$schema->filterTableData($tables[0],$ticket));
    $id=(int)$this->db->lastInsertId();
    if($id<=0)throw new \RuntimeException('No se obtuvo el identificador del caso crítico.');
    $this->db->update($tables[0],['id_ticket'=>(string)$id],['_ID'=>$id]);
    $note='Caso #'.$id.' creado automáticamente: '.self::TOPIC.'. '.$description;
    $base=['cct_status'=>'publish','cct_author_id'=>$actor,'id_empleado'=>$actor,'cct_created'=>$mysql,'cct_modified'=>$mysql,'fecha'=>$registered,'id_ticket'=>$id];
    $this->db->insert($tables[1],$schema->filterTableData($tables[1],$base+['nombre'=>$e['nombre'],'respuesta'=>$note,'observacion'=>$note,'archivos'=>serialize($files),'fue_editada'=>'Si']));
    $this->db->insert($tables[2],$schema->filterTableData($tables[2],$base+[
      'id_inmueble'=>(string)$c['id_inmueble'],'id_inmueble_data'=>(string)$c['id_inmueble'],
      'tipo_reporte'=>'Ticket','tipo_de_reporte_his'=>'Ticket','observacion'=>$note,'observacion_his'=>$note,
      'funcionario'=>$e['nombre'],'reporte_realizado_por_his'=>$e['nombre'],
    ]));
    return $id;
  }

  public static function open(array $ticket): bool
  {
    foreach(['estado','estado_administrativo'] as $field) if(in_array(mb_strtolower(trim((string)($ticket[$field]??''))),['cerrado','cerrada','resuelto','resuelta','finalizado','finalizada','anulado','anulada'],true))return false;
    return !empty($ticket['_ID']);
  }
}
