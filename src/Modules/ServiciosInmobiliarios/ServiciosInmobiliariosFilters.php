<?php


namespace SCM\Modules\ServiciosInmobiliarios;

final class ServiciosInmobiliariosFilters
{
  /**
   * @param array<string,mixed> $input
   * @return array<string,string>
   */
  public function parse(array $input, string $prefix = 'scm_'): array
  {
    $page = $this->intValue($input, $prefix . 'page', 1);
    $perPage = $this->intValue($input, $prefix . 'per_page', 24);
    if ($perPage < 24) {
      $perPage = 24;
    }
    if ($perPage > 100) {
      $perPage = 100;
    }

    $singleDate = $this->value($input, $prefix . 'fecha');
    $dateFrom = $singleDate !== '' ? $singleDate : $this->value($input, $prefix . 'fecha_desde');
    $dateTo = $singleDate !== '' ? $singleDate : $this->value($input, $prefix . 'fecha_hasta');

    return [
      'fTema' => $this->value($input, $prefix . 'tema'),
      'fEstado' => $this->value($input, $prefix . 'estado'),
      'fEstadoAdmin' => $this->value($input, $prefix . 'estado_admin'),
      'fOrigen' => $this->value($input, $prefix . 'origen'),
      'fPrioridad' => $this->value($input, $prefix . 'prioridad'),
      'fCotizacion' => $this->value($input, $prefix . 'cotizacion'),
      'fPerturbacion' => $this->value($input, $prefix . 'perturbacion'),
      'fRevision' => $this->value($input, $prefix . 'revision'),
      'fMagnitud' => $this->value($input, $prefix . 'magnitud'),
      'fMagnitudCaso' => $this->value($input, $prefix . 'magnitud_caso'),
      'fInmueble' => $this->value($input, $prefix . 'inmueble'),
      'fContrato' => $this->value($input, $prefix . 'contrato'),
      'fEmpleado' => $this->value($input, $prefix . 'id_empleado'),
      'fArrendatario' => $this->value($input, $prefix . 'arrendatario'),
      'fPropietario' => $this->value($input, $prefix . 'propietario'),
      'fAsunto' => $this->value($input, $prefix . 'asunto'),
      'fBarrio' => $this->value($input, $prefix . 'barrio'),
      'fAseguradora' => $this->value($input, $prefix . 'aseguradora'),
      'fBusqueda' => $this->value($input, $prefix . 'busqueda'),
      'fAtraso' => $this->value($input, $prefix . 'atraso'),
      'fSinActualizar' => $this->value($input, $prefix . 'sin_actualizar'),
      'fTuvoSeguimiento' => $this->value($input, $prefix . 'tuvo_seguimiento'),
      'fFecha' => $singleDate,
      'fFechaDesde' => $dateFrom,
      'fFechaHasta' => $dateTo,
      'fPage' => (string) $page,
      'fPerPage' => (string) $perPage,
    ];
  }

  /** @param array<string,mixed> $input */
  private function value(array $input, string $key): string
  {
    if (!isset($input[$key])) {
      return '';
    }

    return trim((string) sanitize_text_field(wp_unslash((string) $input[$key])));
  }

  /** @param array<string,mixed> $input */
  private function intValue(array $input, string $key, int $default): int
  {
    if (!isset($input[$key])) {
      return $default;
    }

    $value = (int) sanitize_text_field(wp_unslash((string) $input[$key]));
    if ($value <= 0) {
      return $default;
    }

    return $value;
  }
}
