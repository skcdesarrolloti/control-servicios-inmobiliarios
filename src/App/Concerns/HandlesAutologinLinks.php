<?php

declare(strict_types=1);

namespace SCM\App\Concerns;

use SCM\Support\ActaAutologin;
use SCM\Support\FuncionarioOptions;
use SCM\Support\SchemaInspector;

/**
 * Generador de enlaces del puente con autologin (guía "Tipos de actas y enlaces").
 * El secreto nunca sale del servidor: se devuelve el enlace con un token firmado por funcionario.
 */
trait HandlesAutologinLinks
{
  /** accion del puente => parámetro que identifica el registro. @return array<string,string> */
  private static function autologinLinkActions(): array
  {
    return [
    'acta_satisfaccion' => 'ticket_pk',
    'acta_cotizacion' => 'id_cotizacion',
    'revision_preventiva' => 'ticket_pk',
    'revision_correctiva' => 'ticket_pk',
    'crear_cotizacion' => 'ticket_pk',
    'editar_cotizacion' => 'id_cotizacion',
    'enviar_cotizacion' => 'id_cotizacion',
    'crear_orden' => 'id_cotizacion',
    'editar_hoja_cierre' => 'id_cierre',
    'ver_hoja_cierre' => 'numero',
    'revision_servicios_cierre' => 'numero',
    ];
  }

  /** Acciones que abren su propia página en vez del puente. @return array<string,string> */
  private static function autologinLinkPages(): array
  {
    return ['editar_hoja_cierre' => 'hoja-cierre.php', 'ver_hoja_cierre' => 'hoja-cierre-ver.php', 'revision_servicios_cierre' => 'revision-servicios-cierre.php'];
  }

  private function autologinSecretForLinks(): string
  {
    $this->verifyCsrf();
    if (!$this->canManageDashboardPermissions()) {
      $this->jsonFail('Solo los administradores de permisos pueden generar enlaces con autologin.');
    }
    $secret = defined('SCM_ACTA_AUTOLOGIN_SECRET') ? trim((string) SCM_ACTA_AUTOLOGIN_SECRET) : '';
    if ($secret === '') {
      $this->jsonFail('El autologin está desactivado: falta ACTA_AUTOLOGIN_SECRET en la configuración del servidor.');
    }
    return $secret;
  }

  public function ajax_handler_autologin_link_options(): void
  {
    $this->autologinSecretForLinks();
    $employees = [];
    foreach (FuncionarioOptions::activeFuncionarios($this->db, new SchemaInspector($this->db), 'employee', true) as $row) {
      $id = trim((string) ($row['employee_id'] ?? ''));
      if ($id !== '') {
        $employees[] = ['id' => $id, 'label' => trim((string) ($row['name'] ?? '')) . ' · ' . trim((string) ($row['cargo'] ?? '')) . ' · ' . $id];
      }
    }
    usort($employees, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    $this->jsonOk(['employees' => $employees, 'actions' => self::autologinLinkActions()]);
  }

  public function ajax_handler_autologin_link(): void
  {
    $secret = $this->autologinSecretForLinks();
    $action = (string) ($_POST['accion'] ?? '');
    $recordId = trim((string) ($_POST['record_id'] ?? ''));
    $employeeId = trim((string) ($_POST['id_empleado'] ?? ''));
    if (!isset(self::autologinLinkActions()[$action])) {
      $this->jsonFail('Selecciona una acción válida.');
    }
    if (preg_match('/^\d{1,10}$/D', $recordId) !== 1 || (int) $recordId <= 0) {
      $this->jsonFail('Escribe el ID numérico del caso, la cotización o la hoja de cierre.');
    }
    $active = array_column(FuncionarioOptions::activeFuncionarios($this->db, new SchemaInspector($this->db), 'employee', true), null, 'employee_id');
    if ($employeeId === '' || !isset($active[$employeeId])) {
      $this->jsonFail('Selecciona un funcionario activo.');
    }
    $page = self::autologinLinkPages()[$action] ?? null;
    $query = $page === null ? ['accion' => $action] : [];
    $query[self::autologinLinkActions()[$action]] = $recordId;
    $base = rtrim((string) SCM_BASE_URL, '/') . '/' . ($page ?? 'puente-funcionario.php') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $this->jsonOk([
      'url' => ActaAutologin::link($secret, $base, $employeeId),
      'employee' => (string) ($active[$employeeId]['name'] ?? ''),
    ]);
  }
}
