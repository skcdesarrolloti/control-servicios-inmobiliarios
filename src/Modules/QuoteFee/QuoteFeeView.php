<?php

declare(strict_types=1);

namespace SCM\Modules\QuoteFee;

use SCM\Modules\Pending\PublicServicesUi as UI;

/** Configuración › Honorarios de cotización (public/assets/js/admin-tools.js, initQuoteFeeSettings). */
final class QuoteFeeView
{
  /** @param array{salario:float,tarifa:float,f_smlmv:float,f:float,transicion:float,k:float,curva:string} $config */
  public static function form(array $config, bool $unsaved = false): string
  {
    $money = static fn(float $value): string => '$' . number_format($value, 0, ',', '.');
    $decimal = static fn(float $value, int $places = 4): string => rtrim(rtrim(number_format($value, $places, ',', ''), '0'), ',');
    $field = static function (string $label, string $control, string $hint = ''): string {
      return '<label class="!sp-block !sp-min-w-0"><span class="' . UI::LABEL . '">' . $label . '</span>' . $control
        . ($hint !== '' ? '<span class="!sp-block !sp-mt-1 !sp-text-[10px] !sp-text-service-muted">' . $hint . '</span>' : '') . '</label>';
    };
    $input = static fn(string $name, string $value, string $extra = ''): string => '<input class="' . UI::INPUT . '" type="text" inputmode="decimal" name="' . $name . '" value="' . self::e($value) . '" data-quote-fee-field' . $extra . '>';
    $curves = '';
    foreach (QuoteFeeSettings::CURVES as $key => $label) {
      $curves .= '<option value="' . $key . '"' . ($config['curva'] === $key ? ' selected' : '') . '>' . $label . '</option>';
    }

    $fields = $field('Tarifa proporcional (%)', $input('tarifa', $decimal($config['tarifa'] * 100)), 'Porcentaje base sobre el valor de la reparación.')
      . $field('Componente inicial (F)', $input('f', (string) round($config['f']), ' data-quote-fee-f'), 'Adicional máximo en pesos. Se guarda como ' . $decimal($config['f_smlmv'], 6) . ' SMLMV y se ajusta solo cuando cambia el salario.')
      . $field('Salario base (SMLMV)', $input('salario', (string) round($config['salario']), ' data-quote-fee-salary'), 'Valor compartido: también lo usan actas de cierre, calculadora de aseo y reportes administrativos.')
      . $field('Transición (T)', $input('transicion', (string) round($config['transicion'])), 'Desde este valor de reparación solo aplica la tarifa proporcional.')
      . $field('Forma elíptica (k)', $input('k', $decimal($config['k'])), 'Entre 0 y 1. Solo aplica a la curva elipse.')
      . $field('Curva', '<select class="' . UI::INPUT . '" name="curva" data-quote-fee-field>' . $curves . '</select>');

    $rows = '';
    foreach (QuoteFeeSettings::PREVIEW_VALUES as $repair) {
      $fee = QuoteFeeSettings::fee($config, (float) $repair);
      $rows .= '<tr class="!sp-border-0 !sp-border-t !sp-border-solid !sp-border-slate-100"><td class="!sp-px-3 !sp-py-1.5">' . $money((float) $repair) . '</td><td class="!sp-px-3 !sp-py-1.5 !sp-text-right">' . $money($fee) . '</td><td class="!sp-px-3 !sp-py-1.5 !sp-text-right !sp-font-semibold">' . $decimal((float) QuoteFeeSettings::suggestedPercent($config, (float) $repair), 2) . ' %</td></tr>';
    }
    $th = '!sp-px-3 !sp-py-2 !sp-text-[9px] !sp-uppercase !sp-tracking-wide !sp-font-bold !sp-text-service-muted';
    $warning = QuoteFeeSettings::isIncreasing($config) ? '' : '<p class="!sp-m-0 !sp-p-3 !sp-rounded-lg !sp-bg-amber-50 !sp-border !sp-border-solid !sp-border-amber-200 !sp-text-[11px] !sp-text-amber-900">Revisa los parámetros: con estos valores el honorario en pesos puede bajar cuando sube la reparación. Sube la tarifa o la transición, o baja F o k.</p>';

    return '<form class="!sp-flex !sp-flex-col !sp-min-h-0 !sp-font-sans !sp-text-service-navy" data-quote-fee-form autocomplete="off" novalidate>'
      . '<div class="!sp-flex-1 !sp-overflow-y-auto !sp-p-4 sm:!sp-p-6 !sp-space-y-6">'
      . '<p class="!sp-m-0 !sp-text-[11px] !sp-text-service-muted !sp-leading-relaxed">Al enviar una cotización de mantenimiento se sugiere el % de administración: honorario = reparación × tarifa + F × curva(1 − reparación / T), y % = honorario / reparación. La reparación es el subtotal de trabajos (materiales, mano de obra, equipos y otros costos). El funcionario puede cambiar el % antes de enviar.</p>'
      . '<section class="!sp-grid !sp-grid-cols-1 sm:!sp-grid-cols-2 lg:!sp-grid-cols-3 !sp-gap-4">' . $fields . '</section>'
      . $warning
      . '<section class="!sp-space-y-2"><h4 class="!sp-m-0 !sp-text-xs !sp-font-bold">Ejemplos' . ($unsaved ? ' <span class="!sp-font-normal !sp-text-amber-800">(vista previa sin guardar)</span>' : '') . '</h4>'
      . '<div class="!sp-overflow-x-auto !sp-rounded-lg !sp-border !sp-border-solid !sp-border-slate-200"><table class="!sp-w-full !sp-border-collapse !sp-text-xs"><thead class="!sp-bg-slate-50"><tr><th class="' . $th . ' !sp-text-left">Reparación</th><th class="' . $th . ' !sp-text-right">Honorario</th><th class="' . $th . ' !sp-text-right">% sugerido</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
      . '</div>'
      . '<footer class="!sp-flex !sp-flex-wrap !sp-items-center !sp-justify-between !sp-gap-3 !sp-px-4 sm:!sp-px-6 !sp-py-3 !sp-bg-white !sp-border-0 !sp-border-t !sp-border-solid !sp-border-slate-200">'
      . '<span class="!sp-text-[11px] !sp-text-service-muted" role="status" data-quote-fee-status></span>'
      . '<div class="!sp-flex !sp-flex-wrap !sp-gap-2"><button type="submit" class="' . UI::SECONDARY . '" value="preview"><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">calculate</span>Vista previa</button>'
      . '<button type="submit" class="' . UI::PRIMARY . '" value="save"><span class="material-symbols-outlined !sp-text-[18px]" aria-hidden="true">save</span>Guardar configuración</button></div></footer>'
      . '</form>';
  }

  private static function e(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
