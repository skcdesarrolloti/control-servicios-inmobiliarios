<?php

namespace SCM\Modules\Pending;

final class PendingController
{
  private PendingService $service;
  private PendingView $view;

  public function __construct(PendingService $service, PendingView $view)
  {
    $this->service = $service;
    $this->view = $view;
  }

  /** @return array<string,string> */
  private function parseFilters(array $input, string $prefix): array
  {
    $clean = static fn($v): string => trim((string) ($v ?? ''));
    return [
      'mes' => $clean($input[$prefix . 'mes'] ?? ''),
      'empleado' => $clean($input[$prefix . 'id_empleado'] ?? ''),
      'tuvo' => $clean($input[$prefix . 'tuvo_preventiva'] ?? ''),
      'inmueble' => $clean($input[$prefix . 'inmueble'] ?? ''),
      'propietario' => $clean($input[$prefix . 'propietario'] ?? ''),
      'arrendatario' => $clean($input[$prefix . 'arrendatario'] ?? ''),
      'contrato' => $clean($input[$prefix . 'contrato'] ?? ''),
    ];
  }

  public function renderPreventivasTab(array $input): string
  {
    $payload = $this->buildPreventivasPayload($input);
    return $this->view->renderPreventivasPanel(
      (array) ($payload['filters'] ?? []),
      (array) ($payload['funcionarios'] ?? []),
      (array) ($payload['items'] ?? []),
      (int) ($payload['count'] ?? 0),
      (string) ($payload['corte'] ?? '')
    );
  }

  public function renderServiciosPublicosTab(array $input): string
  {
    $payload = $this->buildServiciosPublicosPayload($input);
    return $this->view->renderServiciosPublicosPanel(
      (array) ($payload['filters'] ?? []),
      (array) ($payload['items'] ?? []),
      (int) ($payload['count'] ?? 0),
      (string) ($payload['corte'] ?? '')
    );
  }

  public function renderReportesAdministrativosTab(): string
  {
    $payload = $this->buildReportesAdministrativosPayload($_GET);
    return $this->view->renderReportesAdministrativosPanel(
      (array) ($payload['filters'] ?? []),
      (array) ($payload['funcionarios'] ?? []),
      (array) ($payload['items'] ?? []),
      (int) ($payload['count'] ?? 0)
    );
  }

  public function renderContratosArrendamientoTab(): string
  {
    return $this->view->renderContratosArrendamientoPanel($this->getContratoBucketDefinitions());
  }

  /** @return array<string,array<string,string>> */
  public function getContratoBucketDefinitions(): array
  {
    return [
      'todos' => ['label' => 'Todos'],
      'por_entregar' => ['label' => 'Por entregar'],
      'entregado' => ['label' => 'Entregado'],
      'desistido' => ['label' => 'Desistido'],
      'por_recibir' => ['label' => 'Por recibir'],
      'no_asignado' => ['label' => 'No asignado'],
    ];
  }

  /** @return array<string,string> */
  private function parseContratoFilters(array $input): array
  {
    $clean = static fn($v): string => trim((string) ($v ?? ''));
    return [
      'page' => $clean($input['sca_page'] ?? '1'),
      'per_page' => $clean($input['sca_per_page'] ?? '30'),
      'contrato' => $clean($input['sca_contrato'] ?? ''),
      'inmueble' => $clean($input['sca_inmueble'] ?? ''),
      'direccion' => $clean($input['sca_direccion'] ?? ''),
      'propietario' => $clean($input['sca_propietario'] ?? ''),
      'arrendatario' => $clean($input['sca_arrendatario'] ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  public function buildPreventivasPayload(array $input): array
  {
    $filters = $this->parseFilters($input, 'spp_');
    $data = $this->service->buildPreventivas($filters);
    return [
      'filters' => $filters,
      'items' => (array) ($data['items'] ?? []),
      'funcionarios' => (array) ($data['funcionarios'] ?? []),
      'count' => count((array) ($data['items'] ?? [])),
      'corte' => (string) ($data['corte'] ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  public function buildServiciosPublicosPayload(array $input): array
  {
    $filters = $this->parseFilters($input, 'rsp_');
    $data = $this->service->buildServiciosPublicos($filters);
    return [
      'filters' => $filters,
      'items' => (array) ($data['items'] ?? []),
      'funcionarios' => (array) ($data['funcionarios'] ?? []),
      'count' => count((array) ($data['items'] ?? [])),
      'corte' => (string) ($data['corte'] ?? ''),
    ];
  }

  /** @return array<string,mixed> */
  public function buildReportesAdministrativosPayload(array $input = []): array
  {
    $filters = [
      'estado' => trim((string) ($input['sra_estado'] ?? 'Pendiente')),
      'id_empleado' => trim((string) ($input['sra_id_empleado'] ?? '')),
      'arrendatario' => trim((string) ($input['sra_arrendatario'] ?? '')),
      'inmueble' => trim((string) ($input['sra_inmueble'] ?? '')),
      'categoria' => trim((string) ($input['sra_categoria'] ?? '')),
      'id_ticket' => trim((string) ($input['sra_ticket'] ?? '')),
      'contrato' => trim((string) ($input['sra_contrato'] ?? '')),
    ];

    $data = $this->service->buildReportesAdministrativos($filters);
    return [
      'filters' => $filters,
      'funcionarios' => (array) ($data['funcionarios'] ?? []),
      'items' => (array) ($data['items'] ?? []),
      'count' => (int) ($data['count'] ?? 0),
    ];
  }

  /** @return array<string,mixed> */
  public function buildContratosArrendamientoPayload(array $input): array
  {
    $bucket = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) ($input['bucket'] ?? 'por_entregar')))) ?: 'por_entregar';
    $defs = $this->getContratoBucketDefinitions();
    if (!isset($defs[$bucket])) {
      $bucket = 'por_entregar';
    }
    $filters = $this->parseContratoFilters($input);
    $data = $this->service->buildContratosArrendamiento($filters, $bucket);
    return [
      'bucket' => $bucket,
      'label' => (string) ($defs[$bucket]['label'] ?? $bucket),
      'filters' => $filters,
      'items' => (array) ($data['items'] ?? []),
      'count' => (int) ($data['count'] ?? 0),
      'pagination' => (array) ($data['pagination'] ?? []),
    ];
  }

  /**
   * @param array<string,string> $input
   * @param array<int,string> $imagenes
   * @param array<int,array{nombre_archivo:string,archivo:string,media_archivo?:string}> $documentos
   * @param array<int,string> $notifyRecipients
   * @return array<string,mixed>
   */
  public function createAdministrativeTicket(array $input, array $imagenes, array $documentos, array $notifyRecipients): array
  {
    return $this->service->createAdministrativeTicket($input, $imagenes, $documentos, $notifyRecipients);
  }

  /** @return array<string,string> */
  public function approveReporteAdministrativo(int $preId): array
  {
    return $this->service->approveReporteAdministrativo($preId);
  }

  /** @return array<string,string> */
  public function rejectReporteAdministrativo(int $preId): array
  {
    return $this->service->rejectReporteAdministrativo($preId);
  }

  /** @return array<string,mixed> */
  public function markContratoRecibido(int $contractId, string $fechaRecibo): array
  {
    return $this->service->markContratoRecibido($contractId, $fechaRecibo);
  }
}
