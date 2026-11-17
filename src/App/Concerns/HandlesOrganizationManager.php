<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Core\Auth;
use SCM\Modules\OrganizationManager\OrganizationPdf;
use SCM\Modules\OrganizationManager\OrganizationPolicies;
use SCM\Modules\OrganizationManager\OrganizationRepository;
use SCM\Modules\OrganizationManager\OrganizationTransfers;
use SCM\Support\SchemaInspector;

/**
 * Actividades contractuales › Gestor organizacional (antes plugin [gestor_organizacional]).
 * La opción del menú (gestor_organizacional) la tiene todo funcionario: Mi gestión es de todos. Lo demás se habilita
 * con el catálogo de permisos por acción (grupo Gestor organizacional): org_manager_view, org_manager_manage,
 * org_manager_delete, org_manager_transfer y org_manager_export (reemplazan la opción gpo_full_access_cargo_ids).
 */
trait HandlesOrganizationManager
{
  private function orgRepository(): OrganizationRepository
  {
    return new OrganizationRepository($this->db);
  }

  /** @return array{id:string,name:string} quien ejecuta: id_empleado real y nombre */
  private function orgActor(): array
  {
    return ['id' => Auth::employeeId(), 'name' => Auth::user()];
  }

  private function orgRequireTab(): void
  {
    $this->verifyCsrf();
    if (!$this->canAccessDashboardTab('gestor_organizacional')) {
      $this->jsonFail('No tienes acceso al Gestor organizacional.');
    }
  }

  private function orgFail(\Throwable $error, string $fallback): void
  {
    if ($error instanceof \DomainException) {
      $this->jsonFail($error->getMessage());
    }
    error_log('[gestor-organizacional] ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine());
    $this->jsonFail($fallback);
  }

  private function orgInput(string $key, int $max = 250): string
  {
    $value = $_POST[$key] ?? '';
    return is_scalar($value) ? mb_substr(trim(sanitize_text_field((string) $value)), 0, $max, 'UTF-8') : '';
  }

  private function orgType(): string
  {
    $type = sanitize_key((string) ($_POST['tipo'] ?? ''));
    if (!isset(OrganizationRepository::TYPES[$type])) {
      $this->jsonFail('Tipo de registro inválido.');
    }
    return $type;
  }

  /** @return array<string,string> */
  private function orgFilters(): array
  {
    $filters = [];
    foreach (['id_area', 'id_cargo', 'activo', 'titulo', 'detalle', 'estado', 'audiencia', 'sin_area'] as $key) {
      $filters[$key] = $this->orgInput($key);
    }
    return $filters;
  }

  /** @return array<string,bool> */
  private function orgPermissions(): array
  {
    return [
      'view' => $this->canUseDashboardAction('org_manager_view'),
      'manage' => $this->canUseDashboardAction('org_manager_manage'),
      'delete' => $this->canUseDashboardAction('org_manager_delete'),
      'transfer' => $this->canUseDashboardAction('org_manager_transfer'),
      'export' => $this->canUseDashboardAction('org_manager_export'),
    ];
  }

  /** Cargo que el funcionario puede abrir en Gestión por cargo: el suyo, los que tiene a cargo o cualquiera con org_manager_view. */
  private function orgCanInspectCargo(OrganizationRepository $repository, int $cargoId): bool
  {
    return $cargoId > 0 && ($this->canUseDashboardAction('org_manager_view') || $repository->canInspectCargo(Auth::employeeId(), $cargoId));
  }

  public function ajax_handler_org_bootstrap(): void
  {
    $this->orgRequireTab();
    try {
      $repository = $this->orgRepository();
      $can = $this->orgPermissions();
      $catalog = $repository->catalog();
      $scope = $repository->cargoScope(Auth::employeeId());
      $inspectable = $can['view'] ? array_column($catalog['cargos'], 'id') : array_merge($scope['own'], $scope['managed']);
      if (!$can['view'] && !$can['manage']) {
        // Sin acceso al catálogo: solo lo necesario para nombrar áreas y cargos.
        $catalog = ['areas' => $catalog['areas'], 'cargos' => $catalog['cargos'], 'procesos' => [], 'sucursales' => [], 'funcionarios' => [], 'wp_roles' => []];
      }
      $this->jsonOk([
        'can' => $can,
        'catalog' => $catalog,
        'types' => OrganizationRepository::TYPES,
        'inspectable_cargos' => array_values(array_map('intval', $inspectable)),
        'own_cargos' => $scope['own'],
        'employee' => ['id' => Auth::employeeId(), 'name' => Auth::user()],
        'policy' => [
          'columns' => $repository->policies()->availableColumns(),
          'states' => OrganizationPolicies::STATES,
          'audiences' => OrganizationPolicies::AUDIENCES,
        ],
      ]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar el Gestor organizacional.');
    }
  }

  public function ajax_handler_org_list(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_view', 'Tu cargo no tiene permiso para ver los registros del Gestor organizacional.');
    try {
      $this->jsonOk($this->orgRepository()->list($this->orgType(), $this->orgFilters()));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudieron cargar los registros.');
    }
  }

  public function ajax_handler_org_record(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_view', 'Tu cargo no tiene permiso para ver los registros del Gestor organizacional.');
    try {
      $type = $this->orgType();
      $repository = $this->orgRepository();
      $row = $repository->find($type, (int) ($_POST['id'] ?? 0));
      if ($row === null) {
        $this->jsonFail('El registro ya no existe. Actualiza la lista.');
      }
      $this->jsonOk(['record' => $repository->present($type, $row)]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo abrir el registro.');
    }
  }

  public function ajax_handler_org_save(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_manage', 'Tu cargo no tiene permiso para crear o editar registros del Gestor organizacional.');
    try {
      $type = $this->orgType();
      $newVersion = ($_POST['nueva_version'] ?? '') === '1';
      // Cada campo se limpia (y se le quitan las barras) una sola vez en el repositorio.
      $input = $_POST;
      $result = $this->orgRepository()->save($type, (int) ($_POST['id'] ?? 0), is_array($input) ? $input : [], $this->orgActor(), $type === 'politicas' && $newVersion);
      $label = OrganizationRepository::TYPES[$type][0];
      $message = $newVersion ? 'Nueva versión ' . $result['record']['version'] . ' publicada.' : $label . ($result['created'] ? ' creada.' : ' guardada.');
      $this->jsonOk($result + ['message' => $message]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo guardar el registro.');
    }
  }

  public function ajax_handler_org_delete_impact(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_delete', 'Tu cargo no tiene permiso para eliminar registros del Gestor organizacional.');
    try {
      $this->jsonOk($this->orgRepository()->deleteImpact($this->orgType(), (int) ($_POST['id'] ?? 0)));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo revisar el registro.');
    }
  }

  public function ajax_handler_org_delete(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_delete', 'Tu cargo no tiene permiso para eliminar registros del Gestor organizacional.');
    try {
      $this->jsonOk($this->orgRepository()->delete($this->orgType(), (int) ($_POST['id'] ?? 0), $this->orgActor()));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo eliminar el registro.');
    }
  }

  public function ajax_handler_org_my_management(): void
  {
    $this->orgRequireTab();
    try {
      $data = $this->orgRepository()->managementForEmployee(Auth::employeeId());
      if ($data === null) {
        $this->jsonFail('No se encontró un funcionario asociado a tu usuario.');
      }
      $this->jsonOk($data);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar tu gestión.');
    }
  }

  public function ajax_handler_org_cargo_management(): void
  {
    $this->orgRequireTab();
    try {
      $repository = $this->orgRepository();
      $cargoId = (int) ($_POST['id_cargo'] ?? 0);
      if (!$this->orgCanInspectCargo($repository, $cargoId)) {
        $this->jsonFail('Sin permisos para consultar este cargo: solo el tuyo o los que tienes a cargo.');
      }
      $this->jsonOk($repository->managementForCargo($cargoId, Auth::employeeId()));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar la gestión del cargo.');
    }
  }

  /** Política visible: con org_manager_view, o asignada a un cargo propio o a uno que tiene a cargo. @return array<string,mixed> */
  private function orgVisiblePolicy(OrganizationRepository $repository): array
  {
    $policy = $repository->policy((int) ($_POST['id'] ?? 0));
    if ($policy === null) {
      $this->jsonFail('La política ya no existe.');
    }
    if (!$this->canUseDashboardAction('org_manager_view')) {
      $scope = $repository->cargoScope(Auth::employeeId());
      if (array_intersect(OrganizationRepository::ids($policy['id_cargo'] ?? ''), array_merge($scope['own'], $scope['managed'])) === []) {
        $this->jsonFail('Esta política no está asignada a tu cargo.');
      }
    }
    return $policy;
  }

  public function ajax_handler_org_policy_detail(): void
  {
    $this->orgRequireTab();
    try {
      $repository = $this->orgRepository();
      $policy = $this->orgVisiblePolicy($repository);
      $record = $repository->present('politicas', $policy);
      $version = (int) $record['version'];
      $record['leida'] = $repository->policies()->readsFor([(int) $policy['_ID']], Auth::employeeId())[(int) $policy['_ID']][$version] ?? '';
      $record['asignada'] = $repository->policyAssignedTo($policy, Auth::employeeId());
      if (($base = (int) ($policy['id_politica_base'] ?? 0)) > 0 && ($baseRow = $repository->policy($base)) !== null) {
        $record['base'] = ['id' => $base, 'titulo' => (string) $baseRow['titulo'], 'codigo' => (string) ($baseRow['codigo'] ?? '')];
      }
      $this->jsonOk(['record' => $record]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo abrir la política.');
    }
  }

  public function ajax_handler_org_policy_history(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_view', 'Tu cargo no tiene permiso para ver el historial de las políticas.');
    try {
      $repository = $this->orgRepository();
      $id = (int) ($_POST['id'] ?? 0);
      $this->jsonOk(['history' => $repository->policies()->history($id), 'activity' => $repository->activityLog()->recent('politicas', $id, 30)]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar el historial.');
    }
  }

  public function ajax_handler_org_policy_readers(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_view', 'Tu cargo no tiene permiso para ver las confirmaciones de lectura.');
    try {
      $repository = $this->orgRepository();
      $policy = $repository->policy((int) ($_POST['id'] ?? 0));
      if ($policy === null) {
        $this->jsonFail('La política ya no existe.');
      }
      $cargoNames = array_column($repository->catalog()['cargos'], 'nombre', 'id');
      $this->jsonOk($repository->policies()->readers($policy, $repository->activeFuncionarios(), $cargoNames) + ['titulo' => (string) $policy['titulo']]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudieron cargar las confirmaciones de lectura.');
    }
  }

  public function ajax_handler_org_policy_confirm(): void
  {
    $this->orgRequireTab();
    try {
      $repository = $this->orgRepository();
      $policy = $repository->policy((int) ($_POST['id'] ?? 0));
      if ($policy === null) {
        $this->jsonFail('La política ya no existe.');
      }
      if (!$repository->policyAssignedTo($policy, Auth::employeeId())) {
        $this->jsonFail('Solo confirma la lectura quien tiene la política asignada a su cargo.');
      }
      if (!OrganizationPolicies::visibleInManagement($policy)) {
        $this->jsonFail('Esta política no está vigente.');
      }
      $date = $repository->policies()->confirmRead($policy, Auth::employeeId(), Auth::user());
      $this->jsonOk(['confirmed_at' => $date, 'version' => max(1, (int) ($policy['version'] ?? 1)), 'message' => 'Lectura confirmada.']);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo confirmar la lectura.');
    }
  }

  public function ajax_handler_org_policy_code(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_manage', 'Tu cargo no tiene permiso para crear o editar políticas.');
    try {
      $this->jsonOk(['codigo' => $this->orgRepository()->policies()->suggestCode(OrganizationRepository::csv($_POST['id_area'] ?? ''), (int) ($_POST['id'] ?? 0))]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo sugerir el código.');
    }
  }

  public function ajax_handler_org_activity(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_view', 'Tu cargo no tiene permiso para ver la bitácora del Gestor organizacional.');
    try {
      $type = sanitize_key((string) ($_POST['tipo'] ?? ''));
      $this->jsonOk(['items' => $this->orgRepository()->activityLog()->recent(isset(OrganizationRepository::TYPES[$type]) ? $type : '', (int) ($_POST['id'] ?? 0), 100)]);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar la bitácora.');
    }
  }

  // --- Traslados ---------------------------------------------------------------------------------------------------

  private function orgTransfers(): OrganizationTransfers
  {
    return new OrganizationTransfers($this->db, new SchemaInspector($this->db));
  }

  /** @return array<string,mixed> */
  private function orgTransferOptions(): array
  {
    $decoded = json_decode((string) wp_unslash((string) ($_POST['options'] ?? '')), true);
    return is_array($decoded) ? $decoded : [];
  }

  /** @return list<string> */
  private function orgTransferModules(): array
  {
    $raw = $_POST['modules'] ?? [];
    return OrganizationTransfers::normalizeModules(is_array($raw) ? $raw : explode(',', (string) $raw));
  }

  public function ajax_handler_org_transfer_data(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_transfer', 'Tu cargo no tiene permiso para trasladar registros entre funcionarios.');
    try {
      $this->jsonOk($this->orgTransfers()->data());
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo cargar el módulo de traslados.');
    }
  }

  public function ajax_handler_org_transfer_preview(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_transfer', 'Tu cargo no tiene permiso para trasladar registros entre funcionarios.');
    try {
      $this->jsonOk($this->orgTransfers()->preview($this->orgInput('origin', 40), $this->orgTransferModules(), $this->orgTransferOptions()));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo previsualizar el traslado.');
    }
  }

  public function ajax_handler_org_transfer_execute(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_transfer', 'Tu cargo no tiene permiso para trasladar registros entre funcionarios.');
    try {
      $reason = trim(strip_tags(sanitize_textarea_field((string) ($_POST['reason'] ?? ''))));
      $result = $this->orgTransfers()->execute($this->orgInput('origin', 40), $this->orgInput('destination', 40), $this->orgTransferModules(), mb_substr($reason, 0, 1000, 'UTF-8'), $this->orgActor(), $this->orgTransferOptions());
      $this->jsonOk($result);
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo ejecutar el traslado.');
    }
  }

  // --- PDF ---------------------------------------------------------------------------------------------------------

  private function orgSendPdf(string $bytes, string $name): void
  {
    if (ob_get_level() > 0) {
      ob_end_clean();
    }
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $name) . '.pdf"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
  }

  public function ajax_handler_org_policies_pdf(): void
  {
    $this->orgRequireTab();
    $this->requireDashboardAction('org_manager_export', 'Tu cargo no tiene permiso para exportar las políticas.');
    try {
      $repository = $this->orgRepository();
      $filters = $this->orgFilters();
      $ids = array_column($repository->list('politicas', $filters)['rows'], '_ID');
      $policies = array_values(array_filter(array_map(static fn($id): ?array => $repository->policy((int) $id), $ids)));
      $catalog = $repository->catalog();
      $label = implode(' · ', array_filter([$filters['estado'] !== '' ? 'Estado: ' . ($filters['estado'] === 'sin' ? 'sin estado' : $filters['estado']) : '', $filters['audiencia'] !== '' ? 'Audiencia: ' . ($filters['audiencia'] === 'sin' ? 'sin audiencia' : $filters['audiencia']) : '']));
      $html = OrganizationPdf::policiesHtml($policies, array_column($catalog['areas'], 'nombre', 'id'), array_column($catalog['cargos'], 'nombre', 'id'), Auth::user(), $label);
      $bytes = OrganizationPdf::bytes($html, 'Políticas organizacionales');
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo generar el PDF de políticas.');
    }
    $this->orgSendPdf($bytes, 'politicas_organizacionales');
  }

  public function ajax_handler_org_management_pdf(): void
  {
    $this->orgRequireTab();
    try {
      $repository = $this->orgRepository();
      $cargoId = (int) ($_POST['id_cargo'] ?? 0);
      if ($cargoId > 0) {
        if (!$this->orgCanInspectCargo($repository, $cargoId)) {
          $this->jsonFail('Sin permisos para consultar este cargo.');
        }
        $data = $repository->managementForCargo($cargoId, Auth::employeeId());
        $person = '';
      } else {
        $data = $repository->managementForEmployee(Auth::employeeId());
        if ($data === null) {
          $this->jsonFail('No se encontró un funcionario asociado a tu usuario.');
        }
        $person = (string) $data['funcionario']['nombre'];
      }
      $bytes = OrganizationPdf::bytes(OrganizationPdf::managementHtml($data, Auth::user(), $person), 'Reporte integral de gestión');
      $name = 'gestion_' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', OrganizationPolicies::fold((string) ($data['cargos'][0]['nombre_cargo'] ?? 'cargo'))));
    } catch (\Throwable $error) {
      $this->orgFail($error, 'No se pudo generar el PDF de gestión.');
    }
    $this->orgSendPdf($bytes, $name);
  }
}
