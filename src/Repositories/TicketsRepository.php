<?php


namespace SCM\Repositories;

use SCM\Core\Database;
use SCM\Support\SchemaInspector;
use SCM\Support\TimestampParser;

final class TicketsRepository
{
  use \SCM\Repositories\Concerns\MaintenanceQueriesConcern;
  use \SCM\Repositories\Concerns\RelatedEnrichmentConcern;
  use \SCM\Repositories\Concerns\CaseEnrichmentConcern;
  use \SCM\Repositories\Concerns\TimelineEnrichmentConcern;
  use \SCM\Repositories\Concerns\HistoryEnrichmentConcern;
  use \SCM\Repositories\Concerns\MaintenanceStatisticsConcern;
  use \SCM\Repositories\Concerns\RepositoryUtilitiesConcern;

  public const MAINTENANCE_TOPICS = [
    'reparaciones necesarias',
    'reparaciones locativas',
    'mejoras utiles',
    'mejoras útiles',
    'reparaciones voluntarias',
    'reparaciones antes de la entrega',
    'reparaciones antes del recibo',
  ];

  private Database $db;
  private SchemaInspector $schema;
  private TimestampParser $parser;
  private int $lastMaintenanceTotal = 0;
  private int $lastMaintenancePage = 1;
  private int $lastMaintenancePerPage = 20;
  private int $lastMaintenanceTotalPages = 1;

  public function __construct(Database $db, SchemaInspector $schema, TimestampParser $parser)
  {
    $this->db     = $db;
    $this->schema = $schema;
    $this->parser = $parser;
  }

}
