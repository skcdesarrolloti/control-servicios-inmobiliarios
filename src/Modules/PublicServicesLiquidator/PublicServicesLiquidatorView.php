<?php

declare(strict_types=1);

namespace SCM\Modules\PublicServicesLiquidator;

final class PublicServicesLiquidatorView
{
  public function renderPanel(): string
  {
    ob_start();
?>
    <div class="scm-pending-wrap scm-psl" data-public-services-liquidator>
      <div class="scm-pending-header scm-pending-header--brand">
        <div>
          <h2>Liquidador de servicios publicos</h2>
          <p>Selecciona un contrato entregado, registra lecturas y genera ordenes de reembolso en PDF.</p>
        </div>
        <div>
          <div class="scm-pending-count" data-psl-total>$0</div>
          <div class="scm-pending-count-label">reembolso calculado</div>
        </div>
      </div>

      <div class="scm-psl-layout">
        <section class="scm-filter-card scm-psl-search">
          <h3>Contrato de arrendamiento</h3>
          <form data-public-services-liquidator-search autocomplete="off">
            <div class="scm-grid" style="grid-template-columns:minmax(0,1fr) auto;">
              <div class="scm-field">
                <label for="psl_query">Buscar contrato</label>
                <input id="psl_query" name="query" type="search" placeholder="Contrato, inmueble, propietario o inquilino">
              </div>
              <div class="scm-field scm-field--button">
                <label>&nbsp;</label>
                <button class="scm-btn-primary-cyan" type="submit">Buscar</button>
              </div>
            </div>
          </form>
          <div class="scm-psl-results" data-psl-results>
            <div class="scm-empty-state">Busca y selecciona el contrato para llenar la informacion base.</div>
          </div>
        </section>

        <form class="scm-filter-card scm-psl-form" data-public-services-liquidator-form autocomplete="off">
          <input type="hidden" name="contract_id" data-psl-contract-id>
          <div class="scm-psl-selected" data-psl-selected>
            <strong>Sin contrato seleccionado</strong>
            <span>Los datos del contrato se llenaran al seleccionarlo en la busqueda.</span>
          </div>

          <div class="scm-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
            <div class="scm-field">
              <label for="psl_periodo">Periodo</label>
              <input id="psl_periodo" name="periodo" type="text" placeholder="Ej. Sep 2026">
            </div>
            <div class="scm-field">
              <label for="psl_ciudad">Ciudad</label>
              <input id="psl_ciudad" name="ciudad" type="text" value="Cartagena de Indias">
            </div>
            <div class="scm-field">
              <label for="psl_tipo_cuenta">Tipo de cuenta</label>
              <input id="psl_tipo_cuenta" name="tipo_cuenta" type="text" placeholder="Ahorros / Corriente">
            </div>
            <div class="scm-field">
              <label for="psl_numero_cuenta">Numero de cuenta</label>
              <input id="psl_numero_cuenta" name="numero_cuenta" type="text">
            </div>
            <div class="scm-field">
              <label for="psl_titular_cuenta">Nombre titular</label>
              <input id="psl_titular_cuenta" name="titular_cuenta" type="text">
            </div>
            <div class="scm-field">
              <label for="psl_cedula_titular">Cedula/NIT titular</label>
              <input id="psl_cedula_titular" name="cedula_titular" type="text">
            </div>
            <div class="scm-field">
              <label>Notificar a</label>
              <div class="scm-psl-checks">
                <label><input type="checkbox" name="notify_roles[]" value="propietario" checked> Propietario</label>
                <label><input type="checkbox" name="notify_roles[]" value="arrendatario" checked> Inquilino</label>
              </div>
            </div>
            <div class="scm-field">
              <label>Canales</label>
              <div class="scm-psl-checks">
                <label><input type="checkbox" name="notify_channels[]" value="email" checked> Correo</label>
                <label><input type="checkbox" name="notify_channels[]" value="whatsapp"> WhatsApp</label>
              </div>
            </div>
          </div>

          <div class="scm-psl-services">
            <?php foreach ($this->serviceDefinitions() as $key => $service): ?>
              <?php echo $this->renderServiceSection($key, $service); ?>
            <?php endforeach; ?>
          </div>

          <div class="scm-actions">
            <button class="scm-btn-secondary" type="button" data-psl-calculate>Calcular</button>
            <button class="scm-btn-primary" type="button" data-psl-generate>Generar ordenes y notificar</button>
            <span class="scm-spinner" data-psl-spinner><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span></span>
          </div>
        </form>
      </div>

      <section class="scm-filter-card scm-psl-summary" data-psl-summary>
        <h3>Resultado de liquidacion</h3>
        <div class="scm-empty-state">Calcula para ver el detalle de cada servicio y descargar las ordenes generadas.</div>
      </section>

      <section class="scm-filter-card scm-psl-guide">
        <h3>Guias del modulo</h3>
        <div class="scm-psl-guide-grid">
          <article>
            <strong>Operacion del Excel</strong>
            <p>El valor unitario sale de valor consumo facturado dividido entre consumo total del periodo. El total del inquilino suma consumo del inquilino, cargo fijo del inquilino y otros cargos atribuibles. El reembolso es total factura menos total del inquilino.</p>
          </article>
          <article>
            <strong>Plantilla WhatsApp</strong>
            <p>Crear en Meta la plantilla <code>scm_liquidador_servicios_reembolso_v1</code>, idioma <code>es_CO</code>, encabezado documento y cuerpo: Hola {{1}}, compartimos {{2}} del contrato {{3}}, inmueble {{4}}, periodo {{5}}. Documento: {{6}}.</p>
          </article>
          <article>
            <strong>Correo</strong>
            <p>El correo usa la plantilla HTML interna y adjunta las ordenes PDF. No requiere plantilla externa; solo debe estar activo el worker de <code>shared-notifications</code>.</p>
          </article>
        </div>
      </section>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderContractsTable(array $rows): string
  {
    if ($rows === []) {
      return '<div class="scm-empty-state">No encontramos contratos entregados con ese criterio.</div>';
    }
    ob_start();
?>
    <div class="scm-table-wrap">
      <table class="scm-table scm-psl-contract-table">
        <thead><tr><th>Contrato</th><th>Inmueble</th><th>Propietario</th><th>Inquilino</th><th>Servicios</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $payload = $this->contractPayload($row); ?>
            <tr>
              <td><strong><?php echo esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '')); ?></strong></td>
              <td><?php echo esc_html((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '')); ?><small><?php echo esc_html((string) ($row['direccion'] ?? '')); ?></small></td>
              <td><?php echo esc_html((string) ($row['propietario'] ?? '')); ?></td>
              <td><?php echo esc_html((string) ($row['arrendatario'] ?? '')); ?></td>
              <td><?php echo esc_html(implode(', ', array_map(static fn(array $service): string => (string) ($service['label'] ?? ''), (array) ($row['configured_services'] ?? []))) ?: 'Sin configurar'); ?></td>
              <td><button type="button" class="scm-btn-secondary scm-btn-sm" data-psl-select-contract data-contract="<?php echo esc_attr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); ?>">Usar</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $payload */
  public function renderSummary(array $payload): string
  {
    $services = (array) ($payload['services'] ?? []);
    if ($services === []) {
      return '<div class="scm-empty-state">No hay servicios calculados.</div>';
    }
    ob_start();
?>
    <h3>Resultado de liquidacion</h3>
    <div class="scm-psl-summary-total">Total reembolso: <strong><?php echo esc_html($this->money((float) ($payload['total_reembolso'] ?? 0))); ?></strong></div>
    <div class="scm-psl-summary-grid">
      <?php foreach ($services as $service): ?>
        <?php $result = (array) ($service['result'] ?? []); $valid = (array) ($result['validaciones'] ?? []); ?>
        <article class="scm-psl-summary-card">
          <h4><?php echo esc_html((string) ($service['label'] ?? 'Servicio')); ?></h4>
          <div class="scm-psl-summary-amount"><?php echo esc_html($this->money((float) ($result['valor_reembolsar'] ?? 0))); ?></div>
          <dl>
            <dt>Total inquilino</dt><dd><?php echo esc_html($this->moneyNullable($result['total_pagar_inquilino'] ?? null)); ?></dd>
            <dt>Parte propietario</dt><dd><?php echo esc_html($this->moneyNullable($result['parte_anterior_propietario'] ?? null)); ?></dd>
            <dt>Diferencia factura</dt><dd><?php echo esc_html($this->moneyNullable($result['diferencia_total_factura'] ?? null)); ?></dd>
            <dt>Validacion</dt><dd><?php echo esc_html(implode(' · ', array_filter(array_map('strval', $valid)))); ?></dd>
          </dl>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($payload['documents'])): ?>
      <div class="scm-psl-documents">
        <strong>Ordenes generadas</strong>
        <?php foreach ((array) $payload['documents'] as $document): ?>
          <a class="scm-btn-secondary" href="<?php echo esc_url((string) ($document['url'] ?? '')); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) ($document['title'] ?? 'Descargar PDF')); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($payload['queued'])): ?>
      <?php $queued = (array) $payload['queued']; ?>
      <p class="scm-psl-queued">Notificaciones en cola: correo <?php echo esc_html((string) ((int) ($queued['email'] ?? 0))); ?>, WhatsApp <?php echo esc_html((string) ((int) ($queued['whatsapp'] ?? 0))); ?>.</p>
    <?php endif; ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,string> $service */
  private function renderServiceSection(string $key, array $service): string
  {
    ob_start();
?>
    <section class="scm-psl-service" data-psl-service="<?php echo esc_attr($key); ?>">
      <header>
        <label><input type="checkbox" name="services[<?php echo esc_attr($key); ?>][enabled]" value="1" data-psl-service-enabled> <?php echo esc_html($service['label']); ?></label>
        <span><?php echo esc_html($service['unit']); ?></span>
      </header>
      <input type="hidden" name="services[<?php echo esc_attr($key); ?>][account]" data-psl-account>
      <input type="hidden" name="services[<?php echo esc_attr($key); ?>][meter]" data-psl-meter>
      <div class="scm-grid" style="grid-template-columns:repeat(4,minmax(0,1fr));">
        <?php foreach ([
          'fecha_lectura_anterior' => ['Fecha lectura anterior', 'date'],
          'lectura_anterior' => ['Lectura anterior', 'number'],
          'fecha_entrega' => ['Fecha entrega inmueble', 'date'],
          'lectura_inicial' => ['Lectura inicial entrega', 'number'],
          'fecha_lectura_actual' => ['Fecha lectura actual', 'date'],
          'lectura_actual' => ['Lectura actual', 'number'],
          'valor_consumo_facturado' => ['Valor consumo facturado', 'number'],
          'total_factura' => ['Total factura', 'number'],
          'otros_cargos_inquilino' => ['Otros cargos inquilino', 'number'],
          'otros_cargos_no_inquilino' => ['Otros cargos no inquilino', 'number'],
        ] as $field => [$label, $type]): ?>
          <div class="scm-field">
            <label><?php echo esc_html($label); ?></label>
            <input name="services[<?php echo esc_attr($key); ?>][<?php echo esc_attr($field); ?>]" type="<?php echo esc_attr($type); ?>" <?php echo $type === 'number' ? 'step="0.01" min="0"' : ''; ?>>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="scm-psl-fixed">
        <strong>Cargos fijos de la factura</strong>
        <div class="scm-grid" style="grid-template-columns:repeat(8,minmax(0,1fr));">
          <?php for ($i = 1; $i <= 8; $i++): ?>
            <div class="scm-field">
              <label>Cargo <?php echo esc_html((string) $i); ?></label>
              <input name="services[<?php echo esc_attr($key); ?>][cargos_fijos][]" type="number" step="0.01" min="0">
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </section>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,mixed> $row @return array<string,mixed> */
  private function contractPayload(array $row): array
  {
    return [
      'id' => (int) ($row['_ID'] ?? 0),
      'contrato' => (string) ($row['contrato'] ?? ''),
      'inmueble' => (string) ($row['inmueble'] ?? $row['id_inmueble'] ?? ''),
      'direccion' => (string) ($row['direccion'] ?? ''),
      'propietario' => (string) ($row['propietario'] ?? ''),
      'arrendatario' => (string) ($row['arrendatario'] ?? ''),
      'services' => (array) ($row['configured_services'] ?? []),
    ];
  }

  /** @return array<string,array<string,string>> */
  private function serviceDefinitions(): array
  {
    return [
      'agua' => ['label' => 'Agua', 'unit' => 'M3'],
      'energia' => ['label' => 'Luz', 'unit' => 'kWh'],
      'gas' => ['label' => 'Gas', 'unit' => 'M3'],
    ];
  }

  private function money(float $value): string
  {
    return '$' . number_format($value, 0, ',', '.');
  }

  /** @param mixed $value */
  private function moneyNullable($value): string
  {
    return is_numeric($value) ? $this->money((float) $value) : '-';
  }
}
