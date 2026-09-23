<?php

declare(strict_types=1);

namespace SCM\Modules\PublicServicesLiquidator;

final class PublicServicesLiquidatorView
{
  public function renderPanel(): string
  {
    ob_start();
?>
    <div class="scm-pending-wrap scm-psl w-full bg-[#f8f9ff] text-[#0b1c30] font-sans antialiased" data-public-services-liquidator>
      <!-- Top Navigation Context / Meta Bar -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-6">
        <div>
          <div class="flex items-center gap-2 text-slate-500 text-xs font-semibold uppercase tracking-wider mb-1">
            <span class="material-symbols-outlined text-[16px]">receipt_long</span>
            <span>Módulo de Entrega &amp; Liquidaciones</span>
            <span class="text-slate-300">•</span>
            <span class="text-amber-700">Acta de Devolución &amp; Reembolsos</span>
          </div>
          <h1 class="text-2xl lg:text-3xl font-bold text-[#0b1c30] tracking-tight">Liquidador de Servicios Públicos</h1>
          <p class="text-sm text-slate-500 max-w-3xl mt-1 leading-relaxed">
            Calcula consumos proporcionales, prorrateos y genera automáticamente actas y órdenes de reembolso en PDF para entregas de inmuebles con respaldo normativo (Ley 820 de 2003).
          </p>
        </div>

        <!-- Live Total Badge & Direct Actions Card -->
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200 flex items-center gap-6 self-start lg:self-auto min-w-[320px] justify-between">
          <div class="flex flex-col">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total a Liquidar Inquilino</span>
            <div class="flex items-baseline gap-1 mt-0.5">
              <span class="text-2xl font-black text-[#0f1e36] tracking-tight" data-psl-total>$0</span>
              <span class="text-xs font-semibold text-slate-400">COP</span>
            </div>
            <span class="text-xs text-emerald-700 font-medium flex items-center gap-1.5 mt-0.5">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Calculado en tiempo real
            </span>
          </div>
          <div class="flex flex-col gap-1.5 items-end">
            <button class="flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg bg-[#0f1e36] text-white text-xs font-bold hover:bg-[#162846] transition-all shadow-sm" type="button" data-psl-generate>
              <span class="material-symbols-outlined text-[18px]">picture_as_pdf</span>
              <span>Orden PDF</span>
            </button>
            <span class="text-[10px] text-slate-400">Listo para firma</span>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start pb-8">
        <!-- Main Form Area (xl:col-span-8) -->
        <div class="xl:col-span-8 flex flex-col gap-6">

          <!-- FASE 01: CONTRATO DE ARRENDAMIENTO & BENEFICIARIO -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#eff4ff] flex items-center justify-center text-[#0f1e36]">
                  <span class="material-symbols-outlined text-[22px]">real_estate_agent</span>
                </div>
                <div>
                  <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Fase 01 • Referenciación</span>
                  <h2 class="text-lg font-bold text-[#0b1c30]">Contrato de Arrendamiento e Inmueble</h2>
                </div>
              </div>
              <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#eff4ff] text-[#0f1e36] text-xs font-semibold">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Contrato Activo en Liquidación
              </span>
            </div>

            <!-- Buscador Autocomplete -->
            <div class="p-4 bg-[#eff4ff] rounded-xl my-4 border border-[#dce9ff]">
              <form data-public-services-liquidator-search autocomplete="off">
                <label for="psl_query" class="text-xs font-bold text-[#0b1c30] block mb-1.5">Búsqueda rápida de expediente inmobiliario</label>
                <div class="flex flex-col sm:flex-row gap-2">
                  <div class="relative flex-1">
                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[20px]">search</span>
                    <input id="psl_query" name="query" type="search" placeholder="Buscar por contrato, inmueble, propietario o inquilino..." class="w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36] transition-all">
                  </div>
                  <button class="px-5 py-2.5 bg-[#0f1e36] text-white text-xs font-bold rounded-lg hover:bg-[#162846] transition-colors flex items-center justify-center gap-2 shrink-0 shadow-sm" type="submit">
                    <span class="material-symbols-outlined text-[18px]">sync</span>
                    <span>Buscar Contrato</span>
                  </button>
                </div>
              </form>

              <!-- Resultados de búsqueda -->
              <div class="scm-psl-results mt-3" data-psl-results>
                <div class="p-4 text-center text-xs text-slate-500 bg-white rounded-lg border border-dashed border-slate-200">
                  Busca y selecciona el contrato para cargar automáticamente los datos y servicios públicos vinculados.
                </div>
              </div>
            </div>

            <!-- Formulario Principal -->
            <form class="scm-filter-card scm-psl-form space-y-4 !border-0 !p-0 !shadow-none !bg-transparent" data-public-services-liquidator-form autocomplete="off">
              <input type="hidden" name="contract_id" data-psl-contract-id>

              <!-- Tarjeta de Contrato Seleccionado -->
              <div class="scm-psl-selected p-4 bg-slate-50 border border-slate-200 rounded-xl" data-psl-selected>
                <strong>Sin contrato seleccionado</strong>
                <span>Los datos del contrato, inmueble y titulares se llenarán al seleccionarlo en la búsqueda.</span>
              </div>

              <!-- Campos de Periodo, Cuenta y Notificación -->
              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-2">
                <div>
                  <label for="psl_periodo" class="text-xs font-semibold text-slate-600 block mb-1">Período de Facturación</label>
                  <input id="psl_periodo" name="periodo" type="text" placeholder="Ej. Sep 2026" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label for="psl_ciudad" class="text-xs font-semibold text-slate-600 block mb-1">Ciudad &amp; Sector</label>
                  <input id="psl_ciudad" name="ciudad" type="text" value="Cartagena de Indias" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label for="psl_tipo_cuenta" class="text-xs font-semibold text-slate-600 block mb-1">Tipo de Cuenta</label>
                  <input id="psl_tipo_cuenta" name="tipo_cuenta" type="text" placeholder="Ahorros / Corriente" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label for="psl_numero_cuenta" class="text-xs font-semibold text-slate-600 block mb-1">Número de Cuenta</label>
                  <input id="psl_numero_cuenta" name="numero_cuenta" type="text" placeholder="Ej. 123-456789-00" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label for="psl_titular_cuenta" class="text-xs font-semibold text-slate-600 block mb-1">Nombre Titular Reembolso</label>
                  <input id="psl_titular_cuenta" name="titular_cuenta" type="text" placeholder="Nombre completo" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label for="psl_cedula_titular" class="text-xs font-semibold text-slate-600 block mb-1">Cédula / NIT Titular</label>
                  <input id="psl_cedula_titular" name="cedula_titular" type="text" placeholder="Número de documento" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-[#0b1c30] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
                </div>
                <div>
                  <label class="text-xs font-semibold text-slate-600 block mb-1">Notificar Liquidación A</label>
                  <div class="flex items-center gap-3 pt-1">
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer select-none">
                      <input type="checkbox" name="notify_roles[]" value="propietario" checked class="w-4 h-4 rounded text-[#0f1e36] focus:ring-0 accent-[#0f1e36]">
                      <span>Propietario</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer select-none">
                      <input type="checkbox" name="notify_roles[]" value="arrendatario" checked class="w-4 h-4 rounded text-[#0f1e36] focus:ring-0 accent-[#0f1e36]">
                      <span>Inquilino</span>
                    </label>
                  </div>
                </div>
                <div>
                  <label class="text-xs font-semibold text-slate-600 block mb-1">Canales de Notificación</label>
                  <div class="flex items-center gap-3 pt-1">
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer select-none">
                      <input type="checkbox" name="notify_channels[]" value="email" checked class="w-4 h-4 rounded text-[#0f1e36] focus:ring-0 accent-[#0f1e36]">
                      <span>Correo</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer select-none">
                      <input type="checkbox" name="notify_channels[]" value="whatsapp" class="w-4 h-4 rounded text-[#0f1e36] focus:ring-0 accent-[#0f1e36]">
                      <span>WhatsApp</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- FASE 02: LECTURAS Y CONSUMOS -->
              <div class="pt-6 border-t border-slate-100">
                <div class="flex items-center justify-between mb-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#eff4ff] flex items-center justify-center text-[#0f1e36]">
                      <span class="material-symbols-outlined text-[22px]">tune</span>
                    </div>
                    <div>
                      <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Fase 02 • Lecturas de Medidores</span>
                      <h2 class="text-lg font-bold text-[#0b1c30]">Detalle de Servicios &amp; Prorrateos</h2>
                    </div>
                  </div>
                  <span class="text-xs text-slate-500 font-medium">3 servicios domiciliarios</span>
                </div>

                <div class="scm-psl-services space-y-4">
                  <?php foreach ($this->serviceDefinitions() as $key => $service): ?>
                    <?php echo $this->renderServiceSection($key, $service); ?>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Botones de Acción -->
              <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <span class="scm-spinner hidden" data-psl-spinner>
                  <span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span><span class="scm-spinner-dot"></span>
                </span>
                <button class="px-5 py-2.5 bg-slate-100 text-slate-700 text-xs font-bold rounded-lg hover:bg-slate-200 transition-colors flex items-center gap-2" type="button" data-psl-calculate>
                  <span class="material-symbols-outlined text-[18px]">calculate</span>
                  <span>Calcular Liquidación</span>
                </button>
                <button class="px-6 py-2.5 bg-[#0f1e36] text-white text-xs font-bold rounded-lg hover:bg-[#162846] transition-all flex items-center gap-2 shadow-sm" type="button" data-psl-generate>
                  <span class="material-symbols-outlined text-[18px]">verified</span>
                  <span>Generar Órdenes &amp; Notificar</span>
                </button>
              </div>
            </form>
          </section>

          <!-- Soportes Visuales de Lectura -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-amber-700 text-[22px]">photo_camera</span>
                <h3 class="text-lg font-bold text-[#0b1c30]">Soportes Visuales de Lectura al Desocupar</h3>
              </div>
              <span class="text-xs text-slate-500 font-medium">Actas de entrega con evidencia fotográfica</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="rounded-xl overflow-hidden bg-slate-50 border border-slate-200 shadow-sm flex flex-col">
                <div class="h-32 bg-cyan-50 flex items-center justify-center text-cyan-600">
                  <span class="material-symbols-outlined text-4xl">water_drop</span>
                </div>
                <div class="p-3 bg-white flex justify-between items-center border-t border-slate-100">
                  <div>
                    <span class="text-xs font-bold text-slate-800 block">Medidor de Agua</span>
                    <span class="text-[11px] text-slate-500">Lectura acta de entrega</span>
                  </div>
                  <span class="material-symbols-outlined text-emerald-600 text-[20px]">verified</span>
                </div>
              </div>
              <div class="rounded-xl overflow-hidden bg-slate-50 border border-slate-200 shadow-sm flex flex-col">
                <div class="h-32 bg-amber-50 flex items-center justify-center text-amber-500">
                  <span class="material-symbols-outlined text-4xl">bolt</span>
                </div>
                <div class="p-3 bg-white flex justify-between items-center border-t border-slate-100">
                  <div>
                    <span class="text-xs font-bold text-slate-800 block">Medidor de Energía</span>
                    <span class="text-[11px] text-slate-500">Lectura digital acta de entrega</span>
                  </div>
                  <span class="material-symbols-outlined text-emerald-600 text-[20px]">verified</span>
                </div>
              </div>
              <div class="rounded-xl overflow-hidden bg-slate-50 border border-slate-200 shadow-sm flex flex-col">
                <div class="h-32 bg-orange-50 flex items-center justify-center text-orange-500">
                  <span class="material-symbols-outlined text-4xl">local_fire_department</span>
                </div>
                <div class="p-3 bg-white flex justify-between items-center border-t border-slate-100">
                  <div>
                    <span class="text-xs font-bold text-slate-800 block">Medidor de Gas</span>
                    <span class="text-[11px] text-slate-500">Lectura sello intacto</span>
                  </div>
                  <span class="material-symbols-outlined text-emerald-600 text-[20px]">verified</span>
                </div>
              </div>
            </div>
          </section>
        </div>

        <!-- Sidebar Resumen (xl:col-span-4 sticky top-28) -->
        <div class="xl:col-span-4 flex flex-col gap-6 sticky top-28">
          <!-- Resultado de Liquidación -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200 flex flex-col gap-4 scm-filter-card scm-psl-summary" data-psl-summary>
            <div class="flex items-center justify-between pb-2 border-b border-slate-100">
              <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Resumen Consolidado</span>
              <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold">Oficial</span>
            </div>
            <div class="p-6 text-center text-xs text-slate-500 bg-slate-50 rounded-xl border border-dashed border-slate-200">
              Calcula para ver el detalle de cada servicio y descargar las órdenes generadas.
            </div>
          </section>

          <!-- Guías del Módulo -->
          <section class="bg-white rounded-xl p-6 shadow-sm border border-slate-200 flex flex-col gap-3 scm-filter-card scm-psl-guide">
            <div class="flex items-center gap-2 pb-2 border-b border-slate-100">
              <span class="material-symbols-outlined text-amber-700 text-[20px]">menu_book</span>
              <h3 class="text-sm font-bold text-[#0b1c30]">Guías Operativas del Módulo</h3>
            </div>
            <div class="flex flex-col gap-3 text-xs text-slate-600 scm-psl-guide-grid">
              <article class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                <strong class="text-slate-800 font-bold block mb-1">Cálculo de Prorrateo Normativo</strong>
                <p class="text-[11px] text-slate-500 leading-relaxed">El valor unitario resulta de dividir el valor consumo facturado entre el consumo total del periodo. El cobro al inquilino suma el consumo físico más la proporción de cargos fijos según los días habitados. Aplica el Art. 15 de la Ley 820 de 2003.</p>
              </article>
              <article class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                <strong class="text-slate-800 font-bold block mb-1">Plantilla Oficial WhatsApp</strong>
                <p class="text-[11px] text-slate-500 leading-relaxed">Plantilla Meta <code>scm_liquidador_servicios_reembolso_v1</code> con documento PDF adjunto y firma corporativa de SKC SuCasa Inmobiliaria.</p>
              </article>
              <article class="p-3 rounded-lg bg-slate-50 border border-slate-200">
                <strong class="text-slate-800 font-bold block mb-1">Notificación por Correo</strong>
                <p class="text-[11px] text-slate-500 leading-relaxed">Envío institucional automático con detalle del inmueble, acta de liquidación y orden de reembolso PDF adjunta.</p>
              </article>
            </div>
          </section>
        </div>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<int,array<string,mixed>> $rows */
  public function renderContractsTable(array $rows): string
  {
    if ($rows === []) {
      return '<div class="p-4 text-center text-xs text-slate-500 bg-white rounded-lg border border-dashed border-slate-200">No encontramos contratos entregados con ese criterio de búsqueda.</div>';
    }
    ob_start();
?>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white mt-2 shadow-sm scm-table-wrap">
      <table class="w-full text-left border-collapse text-xs scm-table scm-psl-contract-table">
        <thead>
          <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider text-[11px]">
            <th class="p-3">Contrato</th>
            <th class="p-3">Inmueble / Dirección</th>
            <th class="p-3">Propietario</th>
            <th class="p-3">Inquilino</th>
            <th class="p-3">Servicios Configurados</th>
            <th class="p-3 text-right">Acción</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($rows as $row): ?>
            <?php $payload = $this->contractPayload($row); ?>
            <tr class="hover:bg-slate-50/70 transition-colors">
              <td class="p-3 font-bold text-[#0f1e36]">
                <span class="px-2 py-0.5 rounded bg-[#eff4ff] text-[#0f1e36] font-mono text-[11px]">
                  <?php echo esc_html((string) ($row['contrato'] ?? $row['_ID'] ?? '')); ?>
                </span>
              </td>
              <td class="p-3">
                <div class="font-semibold text-slate-800"><?php echo esc_html((string) ($row['inmueble'] ?? $row['id_inmueble'] ?? '')); ?></div>
                <div class="text-[11px] text-slate-500"><?php echo esc_html((string) ($row['direccion'] ?? '')); ?></div>
              </td>
              <td class="p-3 text-slate-700"><?php echo esc_html((string) ($row['propietario'] ?? '')); ?></td>
              <td class="p-3 text-slate-700"><?php echo esc_html((string) ($row['arrendatario'] ?? '')); ?></td>
              <td class="p-3">
                <?php
                  $services = (array) ($row['configured_services'] ?? []);
                  if ($services === []): ?>
                    <span class="text-slate-400 italic">Sin configurar</span>
                  <?php else: ?>
                    <div class="flex flex-wrap gap-1">
                      <?php foreach ($services as $srv): ?>
                        <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-medium text-[10px]">
                          <?php echo esc_html((string) ($srv['label'] ?? '')); ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
              </td>
              <td class="p-3 text-right">
                <button type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#0f1e36] text-white text-xs font-semibold rounded-lg hover:bg-[#162846] transition-colors shadow-sm scm-btn-secondary scm-btn-sm" data-psl-select-contract data-contract="<?php echo esc_attr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); ?>">
                  <span>Usar</span>
                  <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                </button>
              </td>
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
      return '<div class="p-6 text-center text-xs text-slate-500 bg-slate-50 rounded-xl border border-dashed border-slate-200">No hay servicios calculados. Selecciona un contrato y haz clic en "Calcular Liquidación".</div>';
    }
    ob_start();
?>
    <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
      <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Resumen Consolidado</span>
      <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-bold flex items-center gap-1">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Calculado
      </span>
    </div>

    <!-- Total Reembolso Highlight Card -->
    <div class="p-4 rounded-xl bg-gradient-to-br from-[#0f1e36] to-[#162846] text-white flex flex-col gap-1 mb-4 shadow-sm scm-psl-summary-total">
      <span class="text-[11px] uppercase tracking-wider font-semibold text-slate-300">Total a Reembolsar Inquilino</span>
      <div class="text-2xl font-black tracking-tight text-white"><?php echo esc_html($this->money((float) ($payload['total_reembolso'] ?? 0))); ?> COP</div>
      <span class="text-[11px] text-emerald-300 flex items-center gap-1 mt-0.5">
        <span class="material-symbols-outlined text-[14px]">check_circle</span>
        <?php echo count($services); ?> servicio(s) domiciliario(s) prorrateados
      </span>
    </div>

    <!-- Desglose por Servicio -->
    <div class="flex flex-col gap-3 mb-4 scm-psl-summary-grid">
      <?php foreach ($services as $service): ?>
        <?php
          $result = (array) ($service['result'] ?? []);
          $valid = (array) ($result['validaciones'] ?? []);
          $label = (string) ($service['label'] ?? 'Servicio');
        ?>
        <article class="p-4 rounded-xl bg-slate-50 border border-slate-200 hover:border-slate-300 transition-colors scm-psl-summary-card">
          <div class="flex items-center justify-between mb-2">
            <h4 class="font-bold text-sm text-[#0b1c30] flex items-center gap-1.5">
              <span class="material-symbols-outlined text-[18px] text-[#0f1e36]">receipt</span>
              <?php echo esc_html($label); ?>
            </h4>
            <span class="font-bold text-sm text-[#0f1e36] bg-white px-2.5 py-1 rounded-lg border border-slate-200 scm-psl-summary-amount">
              <?php echo esc_html($this->money((float) ($result['valor_reembolsar'] ?? 0))); ?>
            </span>
          </div>

          <dl class="grid grid-cols-2 gap-2 text-xs pt-2 border-t border-slate-200/60">
            <div>
              <dt class="text-slate-500 text-[11px]">Total inquilino:</dt>
              <dd class="font-bold text-slate-800"><?php echo esc_html($this->moneyNullable($result['total_pagar_inquilino'] ?? null)); ?></dd>
            </div>
            <div>
              <dt class="text-slate-500 text-[11px]">Parte propietario:</dt>
              <dd class="font-bold text-slate-800"><?php echo esc_html($this->moneyNullable($result['parte_anterior_propietario'] ?? null)); ?></dd>
            </div>
            <div>
              <dt class="text-slate-500 text-[11px]">Diferencia factura:</dt>
              <dd class="font-semibold text-slate-700"><?php echo esc_html($this->moneyNullable($result['diferencia_total_factura'] ?? null)); ?></dd>
            </div>
            <?php if (!empty($valid)): ?>
              <div class="col-span-2 pt-1">
                <dt class="text-slate-500 text-[10px] uppercase font-bold tracking-wider mb-0.5">Validación técnica:</dt>
                <dd class="text-[11px] text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 font-medium">
                  <?php echo esc_html(implode(' · ', array_filter(array_map('strval', $valid)))); ?>
                </dd>
              </div>
            <?php endif; ?>
          </dl>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- Documentos Generados -->
    <?php if (!empty($payload['documents'])): ?>
      <div class="flex flex-col gap-2 pt-3 border-t border-slate-100 scm-psl-documents">
        <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Órdenes Generadas (PDF)</span>
        <div class="flex flex-col gap-2">
          <?php foreach ((array) $payload['documents'] as $document): ?>
            <a class="flex items-center justify-between p-2.5 rounded-lg bg-[#eff4ff] hover:bg-[#dce9ff] text-[#0f1e36] text-xs font-bold border border-[#dce9ff] transition-colors scm-btn-secondary" href="<?php echo esc_url((string) ($document['url'] ?? '')); ?>" target="_blank" rel="noopener">
              <span class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px] text-red-600">picture_as_pdf</span>
                <?php echo esc_html((string) ($document['title'] ?? 'Descargar Orden PDF')); ?>
              </span>
              <span class="material-symbols-outlined text-[16px]">open_in_new</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Notificaciones en Cola -->
    <?php if (!empty($payload['queued'])): ?>
      <?php $queued = (array) $payload['queued']; ?>
      <div class="p-3 bg-amber-50 rounded-lg border border-amber-200 text-xs text-amber-900 flex items-center gap-2 mt-2 scm-psl-queued">
        <span class="material-symbols-outlined text-amber-700 text-[18px]">outgoing_mail</span>
        <span>Notificaciones en cola: Correo (<strong><?php echo esc_html((string) ((int) ($queued['email'] ?? 0))); ?></strong>), WhatsApp (<strong><?php echo esc_html((string) ((int) ($queued['whatsapp'] ?? 0))); ?></strong>).</span>
      </div>
    <?php endif; ?>
<?php
    return (string) ob_get_clean();
  }

  /** @param array<string,string> $service */
  private function renderServiceSection(string $key, array $service): string
  {
    $icons = [
      'agua' => ['icon' => 'water_drop', 'color' => 'text-cyan-600', 'bg' => 'bg-cyan-50', 'company' => 'Acuacar • m³'],
      'energia' => ['icon' => 'bolt', 'color' => 'text-amber-500', 'bg' => 'bg-amber-50', 'company' => 'Afinia • kWh'],
      'gas' => ['icon' => 'local_fire_department', 'color' => 'text-orange-500', 'bg' => 'bg-orange-50', 'company' => 'Surtigas • m³'],
    ];
    $meta = $icons[$key] ?? ['icon' => 'tune', 'color' => 'text-slate-600', 'bg' => 'bg-slate-50', 'company' => (string)($service['unit'] ?? '')];

    ob_start();
?>
    <section class="scm-psl-service bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden transition-all duration-200" data-psl-service="<?php echo esc_attr($key); ?>">
      <!-- Header del Servicio -->
      <header class="p-4 bg-slate-50/80 border-b border-slate-200/80 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg <?php echo esc_attr($meta['bg']); ?> flex items-center justify-center <?php echo esc_attr($meta['color']); ?>">
            <span class="material-symbols-outlined text-[20px]"><?php echo esc_html($meta['icon']); ?></span>
          </div>
          <div class="flex flex-col">
            <div class="flex items-center gap-2">
              <label class="font-bold text-sm text-[#0b1c30] cursor-pointer select-none flex items-center gap-2">
                <input type="checkbox" name="services[<?php echo esc_attr($key); ?>][enabled]" value="1" data-psl-service-enabled class="w-4 h-4 rounded text-[#0f1e36] focus:ring-0 accent-[#0f1e36]">
                <span><?php echo esc_html($service['label']); ?></span>
              </label>
              <span class="px-2 py-0.5 rounded-full bg-white text-slate-600 border border-slate-200 font-semibold text-[10px]">
                <?php echo esc_html($meta['company']); ?>
              </span>
            </div>
            <span class="text-[11px] text-slate-400">Prorrateo por lecturas y días de ocupación</span>
          </div>
        </div>
        <span class="px-2 py-1 rounded bg-slate-100 text-slate-600 text-xs font-mono font-semibold uppercase">
          <?php echo esc_html($service['unit']); ?>
        </span>
      </header>

      <input type="hidden" name="services[<?php echo esc_attr($key); ?>][account]" data-psl-account>
      <input type="hidden" name="services[<?php echo esc_attr($key); ?>][meter]" data-psl-meter>

      <div class="p-4 flex flex-col gap-4">
        <!-- Bloque 1: Lecturas del Ciclo -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <!-- 1. Lectura Ciclo Anterior -->
          <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/80 flex flex-col gap-2">
            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500">1. Ciclo Anterior</span>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Fecha lectura anterior</label>
              <input name="services[<?php echo esc_attr($key); ?>][fecha_lectura_anterior]" type="date" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Lectura anterior</label>
              <input name="services[<?php echo esc_attr($key); ?>][lectura_anterior]" type="number" step="0.01" min="0" placeholder="0.00" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
          </div>

          <!-- 2. Entrega Inmueble -->
          <div class="p-3 bg-amber-50/60 rounded-xl border border-amber-200/60 flex flex-col gap-2">
            <div class="flex items-center justify-between">
              <span class="text-[10px] uppercase font-bold tracking-wider text-amber-800">2. Entrega Inmueble</span>
              <span class="material-symbols-outlined text-amber-700 text-[14px]">key</span>
            </div>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Fecha entrega inmueble</label>
              <input name="services[<?php echo esc_attr($key); ?>][fecha_entrega]" type="date" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Lectura entrega llaves</label>
              <input name="services[<?php echo esc_attr($key); ?>][lectura_inicial]" type="number" step="0.01" min="0" placeholder="0.00" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
          </div>

          <!-- 3. Factura Recibida -->
          <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/80 flex flex-col gap-2">
            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500">3. Factura Recibida</span>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Fecha lectura actual</label>
              <input name="services[<?php echo esc_attr($key); ?>][fecha_lectura_actual]" type="date" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
            <div>
              <label class="text-[11px] text-slate-600 block mb-0.5">Lectura actual factura</label>
              <input name="services[<?php echo esc_attr($key); ?>][lectura_actual]" type="number" step="0.01" min="0" placeholder="0.00" class="w-full p-2 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-[#0b1c30] focus:ring-2 focus:ring-[#0f1e36] focus:outline-none">
            </div>
          </div>
        </div>

        <!-- Bloque 2: Valores de la Factura -->
        <div>
          <span class="text-xs font-bold text-slate-700 block mb-2">Valores y Cargos de la Factura ($ COP)</span>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200/70">
              <label class="text-[11px] font-semibold text-slate-600 block mb-1">Valor consumo facturado</label>
              <input name="services[<?php echo esc_attr($key); ?>][valor_consumo_facturado]" type="number" step="0.01" min="0" placeholder="$ 0" class="w-full p-1.5 bg-white border border-slate-200 rounded text-xs font-semibold text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
            </div>
            <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200/70">
              <label class="text-[11px] font-semibold text-slate-600 block mb-1">Total factura liquidada</label>
              <input name="services[<?php echo esc_attr($key); ?>][total_factura]" type="number" step="0.01" min="0" placeholder="$ 0" class="w-full p-1.5 bg-white border border-slate-200 rounded text-xs font-semibold text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
            </div>
            <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200/70">
              <label class="text-[11px] font-semibold text-slate-600 block mb-1">Otros cargos inquilino</label>
              <input name="services[<?php echo esc_attr($key); ?>][otros_cargos_inquilino]" type="number" step="0.01" min="0" placeholder="$ 0" class="w-full p-1.5 bg-white border border-slate-200 rounded text-xs font-semibold text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
            </div>
            <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200/70">
              <label class="text-[11px] font-semibold text-slate-600 block mb-1">Otros cargos no inquilino</label>
              <input name="services[<?php echo esc_attr($key); ?>][otros_cargos_no_inquilino]" type="number" step="0.01" min="0" placeholder="$ 0" class="w-full p-1.5 bg-white border border-slate-200 rounded text-xs font-semibold text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
            </div>
          </div>
        </div>

        <!-- Bloque 3: Cargos Fijos Adicionales -->
        <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/80 scm-psl-fixed">
          <strong class="text-xs font-bold text-slate-700 block mb-2">Cargos fijos de la factura (Prorrateados por días)</strong>
          <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
            <?php for ($i = 1; $i <= 8; $i++): ?>
              <div>
                <label class="text-[10px] text-slate-500 block mb-0.5">Cargo <?php echo esc_html((string) $i); ?></label>
                <input name="services[<?php echo esc_attr($key); ?>][cargos_fijos][]" type="number" step="0.01" min="0" placeholder="$ 0" class="w-full p-1.5 bg-white border border-slate-200 rounded text-xs font-medium text-[#0b1c30] focus:outline-none focus:ring-2 focus:ring-[#0f1e36]">
              </div>
            <?php endfor; ?>
          </div>
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
