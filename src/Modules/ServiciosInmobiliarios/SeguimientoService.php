<?php

namespace SCM\Modules\ServiciosInmobiliarios;

use SCM\Core\Database;
use SCM\Support\EmailQueue;
use SCM\Support\EmailTemplate;
use SCM\Support\SchemaInspector;

final class SeguimientoService
{
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\WorkflowCommandsConcern;
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\PersistenceAndContactsConcern;
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\NotificationDeliveryConcern;

  private Database $db;
  private SchemaInspector $schema;
  private ?EmailQueue $queue = null;

  public function __construct(Database $db, SchemaInspector $schema)
  {
    $this->db     = $db;
    $this->schema = $schema;
  }

  public function setQueue(EmailQueue $queue): void
  {
    $this->queue = $queue;
  }

  /**
   * @return array<string,string>
   */
}
