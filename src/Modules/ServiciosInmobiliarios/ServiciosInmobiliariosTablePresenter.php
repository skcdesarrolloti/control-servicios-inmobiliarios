<?php


namespace SCM\Modules\ServiciosInmobiliarios;

use SCM\Support\DateFormatter;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimestampParser;
use SCM\Timeline\Definitions\ServiciosInmobiliariosTimelineDefinition;
use SCM\Timeline\TimelineEngine;

final class ServiciosInmobiliariosTablePresenter
{
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\TableRowsConcern;
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\HistoryPresentationConcern;
  use \SCM\Modules\ServiciosInmobiliarios\Concerns\PaginationAndTimelineConcern;

  private DateFormatter $dateFormatter;
  private TimestampParser $parser;
  private TimelineEngine $timelineEngine;
  /** @var callable */
  private $seguimientoFormRenderer;
  private int $historySeq = 0;

  public function __construct(DateFormatter $dateFormatter, TimestampParser $parser, TimelineEngine $timelineEngine, callable $seguimientoFormRenderer)
  {
    $this->dateFormatter = $dateFormatter;
    $this->parser = $parser;
    $this->timelineEngine = $timelineEngine;
    $this->seguimientoFormRenderer = $seguimientoFormRenderer;
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @param array<string,string> $config
   */
}
