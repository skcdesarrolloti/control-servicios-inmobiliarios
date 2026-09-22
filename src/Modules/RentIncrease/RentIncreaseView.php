<?php

declare(strict_types=1);

namespace SCM\Modules\RentIncrease;

final class RentIncreaseView
{
  public function renderPanel(): string
  {
    ob_start();
?>
    <div class="scm-rent-increase" data-rent-increase-panel>
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Cartas de aumento</h2>
          <p>Contratos de arrendamiento activos, generación de cartas y seguimiento de aumentos guardados.</p>
        </div>
        <div>
          <div class="scm-pending-count" data-rent-increase-count>0</div>
          <div class="scm-pending-count-label">registros filtrados</div>
        </div>
      </div>
      <aside class="scm-rent-increase-note">
        <strong>Firmas institucionales</strong>
        <span>La carta toma el coordinador contractual desde el funcionario activo con cargo contractual en <code>jet_cct_funcionarios</code>. El representante legal sale del funcionario activo con cargo <code>Gerente General</code>; la imagen de firma se lee de su campo de firma.</span>
      </aside>
      <div class="scm-status-subtabs scm-rent-increase-tabs" role="tablist" aria-label="Cartas de aumento">
        <button type="button" class="scm-status-topic-tab active" data-rent-increase-tab="contracts">Contratos</button>
        <button type="button" class="scm-status-topic-tab" data-rent-increase-tab="canon">Aumentos en canon</button>
        <button type="button" class="scm-status-topic-tab" data-rent-increase-tab="administracion">Aumentos en administración</button>
      </div>
      <?php foreach (['contracts' => 'Contratos activos', 'canon' => 'Aumentos en canon', 'administracion' => 'Aumentos en administración'] as $key => $label): ?>
        <section class="scm-rent-increase-section<?php echo $key === 'contracts' ? ' active' : ''; ?>" data-rent-increase-section="<?php echo esc_attr($key); ?>" data-scm-loaded="0">
          <?php echo $this->renderFilters($key, $label); ?>
          <div class="scm-table-wrap" data-rent-increase-table><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">Selecciona esta vista para cargar la información.</p></div>
          <div class="scm-pagination" data-rent-increase-pagination></div>
        </section>
      <?php endforeach; ?>
      <div class="scm-cia-modal" data-rent-increase-modal hidden>
        <div class="scm-cia-modal-backdrop" data-rent-increase-close></div>
        <section class="scm-cia-modal-panel" role="dialog" aria-modal="true" aria-labelledby="scm-rent-increase-modal-title">
          <header>
            <div><span class="scm-eyebrow">Carta de aumento</span><h3 id="scm-rent-increase-modal-title" data-rent-increase-modal-title>Nueva carta</h3><p data-rent-increase-modal-subtitle>Completa los datos antes de generar y notificar.</p></div>
            <button type="button" class="scm-cia-modal-close" data-rent-increase-close aria-label="Cerrar">&times;</button>
          </header>
          <div data-rent-increase-form-wrap></div>
        </section>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  private function renderFilters(string $scope, string $label): string
  {
    ob_start();
?>
    <div class="scm-filter-card">
      <h3>Filtros - <?php echo esc_html($label); ?></h3>
      <form autocomplete="off" data-rent-increase-form="<?php echo esc_attr($scope); ?>">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
        <div class="scm-grid" style="grid-template-columns:repeat(6,minmax(0,1fr));">
          <div class="scm-field"><label>Desde canon</label><input name="canon_from" type="date"></div>
          <div class="scm-field"><label>Hasta canon</label><input name="canon_to" type="date"></div>
          <div class="scm-field"><label>Desde administración</label><input name="admin_from" type="date"></div>
          <div class="scm-field"><label>Hasta administración</label><input name="admin_to" type="date"></div>
          <div class="scm-field"><label>Mes fin contrato</label><select name="month"><option value="0">Todos</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo esc_attr((string) $m); ?>"><?php echo esc_html($this->monthName($m)); ?></option><?php endfor; ?></select></div>
          <div class="scm-field"><label>Por página</label><select name="per_page"><option value="20">20</option><option value="30">30</option><option value="60">60</option><option value="100">100</option></select></div>
          <div class="scm-field"><label>Propietario</label><input name="propietario" type="text" placeholder="Nombre"></div>
          <div class="scm-field"><label>Arrendatario</label><input name="arrendatario" type="text" placeholder="Nombre"></div>
          <div class="scm-field"><label>Contrato</label><input name="contrato" type="text" placeholder="Código"></div>
          <div class="scm-field"><label>Inmueble</label><input name="inmueble" type="text" placeholder="# inmueble"></div>
        </div>
        <div class="scm-actions">
          <button class="scm-btn-primary-cyan" type="submit">Filtrar</button>
          <button class="scm-btn-secondary" type="button" data-rent-increase-clear>Limpiar</button>
          <span class="scm-spinner"><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
        </div>
      </form>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderTable(array $rows, string $scope): string
  {
    if ($rows === []) {
      return '<div class="scm-table-wrap"><p style="padding:32px;text-align:center;color:var(--scm-text-muted);">No hay registros con los filtros actuales.</p></div>';
    }
    $html = '<div class="scm-table-wrap"><table class="scm-table scm-table-prev scm-rent-increase-table"><thead><tr>'
      . '<th>Contrato</th><th>Inmueble</th><th>Canon</th><th>Admin</th><th>Dirección</th><th>Propietario</th><th>Arrendatario</th><th>Inicio</th><th>Fin</th><th>Acciones</th>'
      . '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $row = (array) $row;
      $html .= '<tr>';
      $html .= '<td><span class="scm-ticket-badge">' . esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '-')) . '</span></td>';
      $html .= '<td><span class="scm-inmueble-badge">' . esc_html((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '-')) . '</span></td>';
      $html .= '<td>' . esc_html($this->money($row['valor_canon'] ?? '')) . '<small>' . esc_html($this->dateText($row['fecha_incremento_canon'] ?? null)) . '</small></td>';
      $html .= '<td>' . esc_html($this->money($row['valor_administracion'] ?? '')) . '<small>' . esc_html($this->dateText($row['fecha_incremento_admin'] ?? null)) . '</small></td>';
      $html .= '<td style="max-width:220px;">' . esc_html((string) ($row['direccion'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['propietario'] ?? '-')) . '</td>';
      $html .= '<td>' . esc_html((string) ($row['arrendatario'] ?? '-')) . '</td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->dateText($row['inicio_contrato'] ?? null)) . '</td>';
      $html .= '<td class="scm-date-cell">' . esc_html($this->dateText($row['fin_contrato'] ?? null)) . '</td>';
      $html .= '<td class="scm-pending-action-cell">';
      if ($scope === 'contracts') {
        $html .= $this->actionButton('canon', $row) . $this->actionButton('administracion', $row);
      } else {
        $field = $scope === 'canon' ? 'carta_aumento_canon' : 'carta_aumento_admin';
        $url = trim((string) ($row[$field] ?? ''));
        if ($url !== '') {
          $html .= '<button type="button" class="scm-pending-action-btn" data-scm-open-iframe data-iframe-url="' . esc_attr($url) . '" data-iframe-title="Carta de aumento">Ver carta</button>';
        }
      }
      $html .= '</td></tr>';
    }
    return $html . '</tbody></table></div>';
  }

  /** @param array<string,mixed> $row */
  private function actionButton(string $type, array $row): string
  {
    $label = $type === 'canon' ? 'Aumentar canon' : 'Aumentar administración';
    $attrs = [
      'type' => $type,
      'contract-id' => (string) ($row['_ID'] ?? ''),
      'contract-code' => (string) ($row['contrato'] ?? $row['_ID'] ?? ''),
      'tenant' => (string) ($row['arrendatario'] ?? ''),
      'property' => (string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''),
      'address' => (string) ($row['direccion'] ?? ''),
      'canon' => (string) ($row['valor_canon'] ?? ''),
      'administration' => (string) ($row['valor_administracion'] ?? ''),
    ];
    $htmlAttrs = '';
    foreach ($attrs as $key => $value) {
      $htmlAttrs .= ' data-' . $key . '="' . esc_attr($value) . '"';
    }
    return '<button type="button" class="scm-pending-action-btn scm-pending-action-btn--blue" style="color:#fff;" data-rent-increase-open' . $htmlAttrs . '>' . esc_html($label) . '</button>';
  }

  /** @param array<string,mixed> $pagination */
  public function renderPagination(array $pagination): string
  {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $total = max(0, (int) ($pagination['total'] ?? 0));
    if ($totalPages <= 1) {
      return $total > 0 ? '<span class="scm-page-info">Mostrando ' . esc_html((string) $total) . ' registro(s)</span>' : '';
    }
    return '<div class="scm-page-controls">'
      . '<button type="button" class="scm-page-btn-rent-increase" data-page="' . esc_attr((string) max(1, $page - 1)) . '"' . ($page <= 1 ? ' disabled' : '') . '>Anterior</button>'
      . '<span class="scm-page-info">Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $totalPages) . ' | ' . esc_html((string) $total) . ' registros</span>'
      . '<button type="button" class="scm-page-btn-rent-increase" data-page="' . esc_attr((string) min($totalPages, $page + 1)) . '"' . ($page >= $totalPages ? ' disabled' : '') . '>Siguiente</button>'
      . '</div>';
  }

  private function money($value): string
  {
    $value = trim((string) $value);
    if ($value === '') {
      return '-';
    }
    if (preg_match('/no\s*aplica/i', $value) === 1) {
      return 'No aplica';
    }
    $normalized = preg_replace('/[^\d,.-]/', '', $value) ?? '';
    if ($normalized === '' || preg_match('/\d/', $normalized) !== 1) {
      return $value;
    }
    $negative = str_starts_with($normalized, '-');
    $normalized = trim($normalized, '-');
    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
      $normalized = str_replace('.', '', $normalized);
      $normalized = preg_replace('/,\d+$/', '', $normalized) ?? $normalized;
    } elseif (str_contains($normalized, '.')) {
      $parts = explode('.', $normalized);
      $last = end($parts);
      $normalized = strlen((string) $last) === 3
        ? str_replace('.', '', $normalized)
        : (preg_replace('/\.\d+$/', '', $normalized) ?? $normalized);
    } elseif (str_contains($normalized, ',')) {
      $parts = explode(',', $normalized);
      $last = end($parts);
      $normalized = strlen((string) $last) === 3
        ? str_replace(',', '', $normalized)
        : (preg_replace('/,\d+$/', '', $normalized) ?? $normalized);
    }
    $digits = preg_replace('/\D/', '', $normalized) ?? '';
    if ($digits === '') {
      return $value;
    }
    $amount = (int) $digits;
    if ($negative) {
      $amount *= -1;
    }
    return '$' . number_format($amount, 0, ',', '.');
  }

  private function dateText($value): string
  {
    if ($value === null || $value === '') {
      return '-';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }

  private function monthName(int $month): string
  {
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return $months[$month] ?? (string) $month;
  }
}
