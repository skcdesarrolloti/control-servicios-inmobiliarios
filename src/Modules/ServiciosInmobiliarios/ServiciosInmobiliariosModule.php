<?php


namespace SCM\Modules\ServiciosInmobiliarios;

use SCM\Core\Auth;
use SCM\Metrics\ServiciosInmobiliariosMetricsService;
use SCM\Repositories\TicketsRepository;

final class ServiciosInmobiliariosModule
{
  private TicketsRepository $ticketsRepository;
  private ServiciosInmobiliariosFilters $filters;
  private ServiciosInmobiliariosMetricsService $metrics;
  private ServiciosInmobiliariosTablePresenter $presenter;

  public function __construct(
    TicketsRepository $ticketsRepository,
    ServiciosInmobiliariosFilters $filters,
    ServiciosInmobiliariosMetricsService $metrics,
    ServiciosInmobiliariosTablePresenter $presenter
  ) {
    $this->ticketsRepository = $ticketsRepository;
    $this->filters = $filters;
    $this->metrics = $metrics;
    $this->presenter = $presenter;
  }

  public function hasTicketsTable(): bool
  {
    return $this->ticketsRepository->hasTicketsTable();
  }

  /**
   * @param array<string,mixed> $input
   * @return array<string,string>
   */
  public function parseParams(array $input, string $prefix = 'scm_'): array
  {
    return $this->filters->parse($input, $prefix);
  }

  /** @return array<string,mixed> */
  public function getFilterOptions(): array
  {
    return $this->ticketsRepository->getMaintenanceFilterOptions();
  }

  /**
   * Conteo liviano para vistas ocultas: evita renderizar cards e historial
   * hasta que el usuario abra la subpestana.
   *
   * @param array<string,string> $params
   */
  public function countMaintenance(array $params, string $statusBucket = ''): int
  {
    $statusBucket = in_array($statusBucket, ['postergados', 'cerrados'], true) ? $statusBucket : '';
    if ($statusBucket !== '') {
      $params['_scmStatusBucket'] = $statusBucket;
    }
    $params['fPage'] = '1';
    $params['fPerPage'] = '1';

    $this->ticketsRepository->queryMaintenance($params);
    $pagination = $this->ticketsRepository->getLastMaintenancePagination();
    return max(0, (int) ($pagination['total'] ?? 0));
  }

  /**
   * Resumen liviano para el primer render: conserva KPIs/metricas sin
   * construir tarjetas del listado hasta que el usuario abra la pestaña.
   *
   * @param array<string,string> $params
   * @return array<string,mixed>
   */
  public function summarizeMaintenance(array $params, string $statusBucket = ''): array
  {
    $statusBucket = in_array($statusBucket, ['postergados', 'cerrados'], true) ? $statusBucket : '';
    if ($statusBucket !== '') {
      $params['_scmStatusBucket'] = $statusBucket;
    }

    return [
      'rows' => [],
      'stats' => $this->ticketsRepository->aggregateMaintenanceStats($params),
      'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1],
      'tbody' => '',
      'pagination_html' => '',
    ];
  }

  /**
   * @param array<string,string> $params
   * @param array<string,string> $config
   * @return array<string,mixed>
   */
  public function run(array $params, array $config, string $statusBucket = ''): array
  {
    $statusBucket = in_array($statusBucket, ['postergados', 'cerrados'], true) ? $statusBucket : '';
    if ($statusBucket !== '') {
      $params['_scmStatusBucket'] = $statusBucket;
    }
    $rows = $this->ticketsRepository->queryMaintenance($params);
    $pagination = $this->ticketsRepository->getLastMaintenancePagination();
    $rows = $this->metrics->enrichRows($rows);

    $stats = $this->ticketsRepository->aggregateMaintenanceStats($params);

    return [
      'rows' => $rows,
      'stats' => $stats,
      'tbody' => $this->presenter->renderTbody($rows, $config, Auth::isLoggedIn(), $statusBucket),
      'pagination' => $pagination,
      'pagination_html' => $this->presenter->renderPagination($pagination, count($rows)),
    ];
  }

  /**
   * Renderiza tarjetas completas para tickets concretos usando el mismo presentador del listado normal.
   *
   * @param array<int,int|string> $ticketIds
   * @param array<string,string> $config
   * @return array<string,string>
   */
  public function renderCardsByTicketIds(array $ticketIds, array $config, string $statusBucket = ''): array
  {
    $statusBucket = in_array($statusBucket, ['postergados', 'cerrados'], true) ? $statusBucket : '';
    $rows = $this->ticketsRepository->queryMaintenanceByPrimaryKeys($ticketIds);
    if (empty($rows)) {
      return [];
    }

    $rows = $this->metrics->enrichRows($rows);
    $cards = [];
    foreach ($rows as $row) {
      $ticketPk = trim((string) ($row['_ID'] ?? ''));
      if ($ticketPk === '') {
        continue;
      }
      $cards[$ticketPk] = $this->presenter->renderTbody([$row], $config, Auth::isLoggedIn(), $statusBucket);
    }
    return $cards;
  }
}
