<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

/**
 * Herramientas › Simulador de ingreso neto del propietario (el mismo del panel comercial): ingreso neto por inmueble con
 * las tarifas de SuCasa, crédito preaprobado y PDF para el propietario. Los cálculos en pantalla los hace
 * owner-net-income.js; el servidor busca contratos, recalcula y genera el PDF (OwnerPreapprovalPdf).
 */
final class OwnerPreapprovalView
{
  public const DISCLAIMER = 'Valores de referencia sujetos a las políticas de crédito y a los términos y condiciones de SKC SuCasa Inmobiliaria. No constituyen una aprobación ni un compromiso de desembolso: el crédito queda sujeto a estudio, verificación y aprobación.';
  /** Íconos de las necesidades que resuelve el crédito preaprobado (OwnerPreapprovalService::USES). */
  private const USE_ICONS = ['servicios' => 'water_drop', 'administracion' => 'apartment', 'reparaciones' => 'construction', 'otros' => 'payments'];

  private static function e($value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /** Encabezado común sin fondo: etiqueta, título y descripción. */
  private static function hero(string $icon, string $title, string $text, string $aside = ''): string
  {
    return '<header class="ctl-hero"><div class="ctl-hero__text"><span class="ctl-eyebrow"><span class="material-symbols-outlined" aria-hidden="true">' . self::e($icon) . '</span>Herramientas</span><h1>' . self::e($title) . '</h1><p>' . $text . '</p>' . $aside . '</div></header>';
  }

  /** Campo de dinero con su unidad visible (/ mes, 12 meses) y, si aplica, la aclaración debajo. */
  private static function moneyField(string $field, string $label, string $unit, string $note = '', string $attribute = 'data-pre-field', string $class = ''): string
  {
    return '<label class="ctl-field' . ($class !== '' ? ' ' . $class : '') . '"><span class="ctl-label">' . self::e($label) . '</span><span class="ctl-input"><input ' . $attribute . '="' . self::e($field) . '" data-tool-money inputmode="numeric" autocomplete="off" placeholder="$0">' . ($unit !== '' ? '<em>' . self::e($unit) . '</em>' : '') . '</span>'
      . ($note !== '' ? '<small class="ctl-note">' . self::e($note) . '</small>' : '') . '</label>';
  }

  private static function selectField(string $field, string $label, string $options, string $row = ''): string
  {
    return '<label class="ctl-field"' . ($row !== '' ? ' data-pre-sim-row="' . self::e($row) . '"' : '') . '><span class="ctl-label">' . self::e($label) . '</span><span class="ctl-input"><select data-pre-sim="' . self::e($field) . '">' . $options . '</select></span></label>';
  }

  private static function switchField(string $field, string $label, bool $checked = false, string $row = ''): string
  {
    return '<label class="ctl-switch"' . ($row !== '' ? ' data-pre-sim-row="' . self::e($row) . '"' : '') . '><input type="checkbox" data-pre-sim="' . self::e($field) . '"' . ($checked ? ' checked' : '') . '><span aria-hidden="true"></span>' . self::e($label) . '</label>';
  }

  public static function render(OwnerPreapprovalService $service): string
  {
    $settings = $service->settings();
    $rates = $service->rates();
    $months = (int) $settings['income_months'];
    $e = static fn($value): string => self::e($value);
    $rateText = rtrim(rtrim(number_format((float) $rates['comision'], 4, ',', ''), '0'), ',');
    $modalities = '';
    $modalityLabels = ['defecto' => 'Con aseguradora (El Libertador, Sura, Zurich…)', 'berkley' => 'Póliza Berkley', 'mundial' => 'Póliza Seguros Mundial'];
    foreach (array_keys(OwnerNetIncomeSimulator::MODALITIES) as $key) $modalities .= '<option value="' . $e($key) . '">' . $e($modalityLabels[$key]) . '</option>';
    $insurers = '';
    foreach (array_keys($rates['aseguradoras']) as $name) $insurers .= '<option value="' . $e($name) . '">' . $e($name) . '</option>';
    $installments = '';
    for ($i = 1; $i <= 12; $i++) $installments .= '<option value="' . $i . '">' . ($i === 1 ? '1 pago (contado)' : $i . ' cuotas') . '</option>';
    $durations = '';
    foreach ([6, 12, 18, 24, 36, 48, 60] as $value) $durations .= '<option value="' . $value . '"' . ($value === 12 ? ' selected' : '') . '>' . $value . ' meses</option>';
    ob_start(); ?>
    <section class="ctl" data-net-income-tool data-min-months="<?php echo OwnerPreapprovalService::MIN_CONTRACT_MONTHS; ?>" data-min-deduction="<?php echo $e((string) $settings['no_mandate_deduction']); ?>" data-income-months="<?php echo $months; ?>" data-term-margin="<?php echo (int) $settings['term_margin_months']; ?>" data-rates="<?php echo $e(json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); ?>">
      <?php echo self::hero('savings', 'Simulador de ingreso neto del propietario', 'Calcula cuánto le queda al propietario cada mes después de la comisión, la garantía y los seguros, con las tarifas vigentes de SuCasa. Con ese ingreso accede a un <strong>crédito preaprobado</strong> para ponerse al día con servicios públicos, administración o reparaciones.'); ?>
      <div class="ctl-layout">
        <div class="ctl-main">
          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">1</span><div><h2>Propietario</h2><p>Si ya tiene contratos con SuCasa, búscalo por cédula o NIT y se cargan sus inmuebles. Si aún no está registrado, escribe sus datos y simula sus inmuebles.</p></div></div>
            <div class="ctl-segment" role="group" aria-label="Tipo de propietario">
              <button type="button" data-pre-mode="registrado" aria-pressed="true"><span class="material-symbols-outlined" aria-hidden="true">person_search</span>Registrado en SuCasa</button>
              <button type="button" data-pre-mode="manual" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">person_add</span>Aún no registrado</button>
            </div>
            <div class="ctl-search" data-pre-search>
              <label class="ctl-field"><span class="ctl-label">Cédula o NIT del propietario</span><span class="ctl-input"><input data-pre-document inputmode="numeric" maxlength="24" autocomplete="off" placeholder="Ej. 1143415938"></span></label>
              <button type="button" class="ctl-secondary" data-pre-search-button><span class="material-symbols-outlined" aria-hidden="true">search</span>Buscar</button>
            </div>
            <p class="ctl-status" data-pre-status role="status" aria-live="polite"></p>
            <div class="ctl-grid ctl-owner">
              <label class="ctl-field"><span class="ctl-label">Nombre completo <b class="ctl-required" aria-hidden="true">*</b></span><span class="ctl-input"><input data-pre-owner="name" maxlength="120" autocomplete="off" required placeholder="Nombre del propietario"></span></label>
              <label class="ctl-field"><span class="ctl-label">Cédula o NIT <b class="ctl-required" aria-hidden="true">*</b></span><span class="ctl-input"><input data-pre-owner="document" maxlength="24" autocomplete="off" required placeholder="Documento"></span></label>
              <label class="ctl-field"><span class="ctl-label">Celular <b class="ctl-required" aria-hidden="true">*</b></span><span class="ctl-input"><input data-pre-owner="phone" maxlength="20" inputmode="tel" autocomplete="off" required placeholder="Ej. 3145106244"></span></label>
              <label class="ctl-field"><span class="ctl-label">Correo <b class="ctl-required" aria-hidden="true">*</b></span><span class="ctl-input"><input type="email" data-pre-owner="email" maxlength="120" inputmode="email" autocomplete="off" required placeholder="correo@ejemplo.com"></span></label>
            </div>
            <p class="ctl-alert" data-pre-credits hidden></p>
          </section>

          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">2</span><div><h2>Ingreso neto por inmueble</h2><p>Simula cada inmueble con las tarifas de SuCasa (comisión sobre canon + administración con IVA, garantía con una aseguradora o con póliza Berkley o Seguros Mundial, y seguro de servicios públicos). Los contratos de un propietario registrado llegan con su canon, la comisión de su mandato y sus coberturas. El seguro se reparte en los 12 meses.</p></div></div>
            <div class="ctl-sources" data-pre-sources>
              <div class="ctl-empty" data-pre-empty><span class="material-symbols-outlined" aria-hidden="true">home_work</span><p>Busca al propietario para cargar sus contratos vigentes, o agrega un inmueble para simularlo.</p></div>
            </div>
            <button type="button" class="ctl-add" data-pre-add><span class="material-symbols-outlined" aria-hidden="true">add</span>Agregar inmueble</button>
          </section>

          <section class="ctl-card">
            <div class="ctl-card__head"><span class="ctl-step">3</span><div><h2>Lo que puede resolver con su crédito preaprobado</h2><p>Pregúntale al propietario si tiene algo pendiente con su inmueble. Si dice que sí, escribe cuánto necesita: así ve que su crédito lo cubre sin sacar dinero de su bolsillo, y aparece en el PDF.</p></div></div>
            <p class="ctl-question">¿El propietario necesita ponerse al día con servicios públicos, administración, reparaciones u otro gasto del inmueble?</p>
            <div class="ctl-segment" role="group" aria-label="El propietario necesita algo">
              <button type="button" data-pre-needs="si" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span>Sí, necesita</button>
              <button type="button" data-pre-needs="no" aria-pressed="true"><span class="material-symbols-outlined" aria-hidden="true">block</span>No por ahora</button>
            </div>
            <div data-pre-needs-panel hidden>
            <div class="ctl-uses">
              <?php foreach (OwnerPreapprovalService::USES as $key => [$label, $text]): ?>
              <label class="ctl-use">
                <span class="ctl-use__icon material-symbols-outlined" aria-hidden="true"><?php echo $e(self::USE_ICONS[$key]); ?></span>
                <span class="ctl-use__text"><strong><?php echo $e($label); ?></strong><small><?php echo $e($text); ?></small></span>
                <span class="ctl-input"><input data-pre-use="<?php echo $e($key); ?>" data-tool-money inputmode="numeric" autocomplete="off" placeholder="$0" aria-label="<?php echo $e($label); ?>"></span>
              </label>
              <?php endforeach; ?>
            </div>
            <p class="ctl-uses__summary" data-pre-uses-summary data-state="empty">Escribe cuánto necesita para cada cosa y muéstrale que su crédito preaprobado lo cubre.</p>
            </div>
            <label class="ctl-field ctl-notes"><span class="ctl-label">Observaciones para el propietario</span><textarea data-pre-notes rows="3" maxlength="600" placeholder="Opcional. Ej.: el crédito se descuenta en cuotas de su canon mensual."></textarea></label>
          </section>
        </div>

        <aside class="ctl-summary" aria-live="polite">
          <div class="ctl-summary__top">
            <span class="ctl-summary__label">Ingreso neto mensual del propietario</span>
            <strong class="ctl-summary__amount" data-pre-net>$0</strong>
            <span class="ctl-summary__formula" data-pre-formula>Canon $0 − descuentos $0 / mes</span>
          </div>
          <div class="ctl-credit">
            <span class="ctl-credit__label"><span class="material-symbols-outlined" aria-hidden="true">verified</span>Crédito preaprobado hasta</span>
            <strong class="ctl-credit__amount" data-pre-total>$0</strong>
            <span class="ctl-credit__formula">Para servicios, administración y reparaciones.</span>
          </div>
          <dl class="ctl-summary__rows">
            <div><dt>Inmuebles</dt><dd data-pre-count>0</dd></div>
            <div><dt>Canon mensual</dt><dd data-pre-gross>$0</dd></div>
            <div><dt>Descuentos promedio / mes</dt><dd data-pre-deductions>$0</dd></div>
            <div><dt>Plazo máximo estimado</dt><dd data-pre-term>Según vigencia</dd></div>
          </dl>
          <p class="ctl-error" data-pre-error role="alert" hidden></p>
          <button type="button" class="ctl-primary" data-pre-pdf><span class="material-symbols-outlined" aria-hidden="true">preview</span>Ver PDF para el propietario</button>
          <button type="button" class="ctl-secondary ctl-wide" data-pre-copy><span class="material-symbols-outlined" aria-hidden="true">content_copy</span><span data-pre-copy-label>Copiar mensaje para WhatsApp</span></button>
          <p class="ctl-fineprint"><?php echo $e(self::DISCLAIMER); ?></p>
        </aside>
      </div>

      <dialog class="ctl-dialog ctl-dialog--pdf" data-pre-viewer aria-labelledby="pre-viewer-title">
        <header><h2 id="pre-viewer-title"><span class="material-symbols-outlined" aria-hidden="true">picture_as_pdf</span>Vista previa del PDF</h2><button type="button" class="ctl-icon" data-pre-viewer-close aria-label="Cerrar"><span class="material-symbols-outlined" aria-hidden="true">close</span></button></header>
        <iframe class="ctl-pdf-frame" data-pre-viewer-frame title="Vista previa del PDF para el propietario"></iframe>
        <footer><button type="button" class="ctl-secondary" data-pre-viewer-close>Cerrar</button><button type="button" class="ctl-primary" data-pre-download><span class="material-symbols-outlined" aria-hidden="true">download</span>Descargar PDF</button></footer>
      </dialog>

      <template data-pre-source-template>
        <article class="ctl-source" data-pre-source>
          <header class="ctl-source__head">
            <div><strong data-pre-source-title>Inmueble</strong><span data-pre-source-meta></span></div>
            <button type="button" class="ctl-icon" data-pre-remove aria-label="Quitar inmueble" title="Quitar inmueble"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
          </header>
          <div class="ctl-source__body">
            <div class="ctl-grid">
              <?php echo self::moneyField('canon', 'Canon mensual', '/ mes'); ?>
              <label class="ctl-field"><span class="ctl-label">Duración del contrato</span><span class="ctl-input"><select data-pre-field="duracion"><?php echo $durations; ?></select></span><small class="ctl-note">Define el plazo máximo del crédito. Mínimo <?php echo OwnerPreapprovalService::MIN_CONTRACT_MONTHS; ?> meses.</small></label>
            </div>
            <div class="ctl-grid">
              <?php echo self::moneyField('administracion', 'Administración', '/ mes', 'La comisión y la garantía se calculan sobre canon + administración.', 'data-pre-sim'); ?>
              <label class="ctl-field"><span class="ctl-label">Tasa de comisión</span><span class="ctl-input"><input data-pre-sim="tasa" inputmode="decimal" maxlength="8" autocomplete="off" value="<?php echo $e($rateText); ?>"><em>% + IVA</em></span></label>
              <?php echo self::selectField('modalidad', 'Tipo de garantía', $modalities); ?>
              <?php echo self::selectField('aseguradora', 'Aseguradora', $insurers, 'defecto'); ?>
              <div class="ctl-switches ctl-full">
                <?php echo self::switchField('garantia', 'Aplicar garantía inmobiliaria', true, 'defecto'); ?>
                <?php echo self::switchField('servicios', 'Incluir seguro de servicios públicos'); ?>
                <?php echo self::switchField('iva', 'El canon lleva IVA'); ?>
              </div>
              <?php echo str_replace('<label class="ctl-field"', '<label class="ctl-field" data-pre-sim-row="valor"', self::moneyField('valor', 'Valor asegurado de servicios', '', '', 'data-pre-sim')); ?>
              <?php echo self::selectField('cuotas', 'Forma de pago del seguro', $installments, 'cuotas'); ?>
              <p class="ctl-note ctl-full ctl-rates" data-pre-sim-rates></p>
            </div>
            <div class="ctl-grid">
              <?php echo self::moneyField('otros', 'Otros descuentos', '/ mes'); ?>
            </div>
            <dl class="ctl-breakdown" data-pre-breakdown></dl>
            <p class="ctl-note ctl-rates" data-pre-source-policy hidden></p>
            <p class="ctl-alert" data-pre-source-warning hidden></p>
          </div>
          <footer class="ctl-source__foot">
            <span>Ingreso neto mensual<small data-pre-source-detail></small></span>
            <strong data-pre-source-net>$0</strong>
          </footer>
        </article>
      </template>
    </section>
    <?php return (string) ob_get_clean();
  }
}
