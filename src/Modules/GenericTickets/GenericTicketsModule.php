<?php

namespace SCM\Modules\GenericTickets;

use SCM\Core\Auth;
use SCM\Core\Database;
use SCM\Support\HistoryLinkMap;
use SCM\Support\TimelineMaps\CertificacionesTimelineMap;
use SCM\Support\TimelineMaps\ContableTimelineMap;
use SCM\Support\TimelineMaps\ContractualTimelineMap;
use SCM\Support\TimelineMaps\EntregaTimelineMap;
use SCM\Support\TimelineMaps\PreventivaTimelineMap;
use SCM\Support\TimelineMaps\ReciboTimelineMap;

final class GenericTicketsModule
{
  use \SCM\Modules\GenericTickets\Concerns\GenericTimelineConcern;
  use \SCM\Modules\GenericTickets\Concerns\GenericQueryConcern;
  use \SCM\Modules\GenericTickets\Concerns\GenericEnrichmentConcern;
  use \SCM\Modules\GenericTickets\Concerns\GenericFiltersAndHistoryConcern;
  use \SCM\Modules\GenericTickets\Concerns\GenericPresentationConcern;
  use \SCM\Modules\GenericTickets\Concerns\PreventiveQueryConcern;

  /** @var object */
  private $owner;

  private Database $db;
  private ?\SCM\Views\GenericTicketsUiView $uiView = null;
  private ?\SCM\Views\GenericTicketsCardView $cardView = null;
  /** @var array<string,array<string,mixed>> */
  private array $genericFilterOptionsCache = [];

  public function __construct($owner, Database $db)
  {
    $this->owner = $owner;
    $this->db    = $db;
  }

  private function uiView(): \SCM\Views\GenericTicketsUiView
  {
    if ($this->uiView instanceof \SCM\Views\GenericTicketsUiView) {
      return $this->uiView;
    }
    $this->uiView = new \SCM\Views\GenericTicketsUiView(
      [$this, 'parse_unix_ts'],
      [$this, 'format_date'],
      [$this, 'format_history_detail_html'],
      [$this, 'build_history_item_buttons'],
      [$this, 'get_record_field_map']
    );
    return $this->uiView;
  }

  private function cardView(): \SCM\Views\GenericTicketsCardView
  {
    if ($this->cardView instanceof \SCM\Views\GenericTicketsCardView) {
      return $this->cardView;
    }
    $this->cardView = new \SCM\Views\GenericTicketsCardView(
      [$this, 'eval_hito_done'],
      [$this, 'eval_hito_visible'],
      [$this, 'parse_unix_ts'],
      [$this, 'format_date'],
      [$this, 'format_date_time'],
      [$this, 'resolve_hito_link'],
      [$this, 'first_id_value'],
      [$this, 'human_duration_since'],
      [$this, 'estado_badge'],
      [$this, 'render_seguimiento_form'],
      [$this, 'render_historial_block'],
      [$this, 'render_record_section'],
      [$this, 'render_single_record_section'],
      [$this, 'resolve_timeline_hitos_for_row']
    );
    return $this->cardView;
  }

  public function __call(string $name, array $arguments)
  {
    if (is_object($this->owner) && is_callable([$this->owner, $name])) {
      return $this->owner->$name(...$arguments);
    }

    throw new \BadMethodCallException('Metodo no encontrado: ' . $name);
  }

}
