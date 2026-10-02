<?php
declare(strict_types=1);
namespace SCM\Modules\Pending;

/** Shared Tailwind primitives for the four services screens. */
final class PublicServicesUi
{
  public const CARD = '!sp-bg-white !sp-rounded-xl !sp-border !sp-border-solid !sp-border-slate-200 !sp-shadow-card !sp-overflow-hidden';
  public const INPUT = '!sp-block !sp-box-border !sp-w-full !sp-h-10 !sp-px-3 !sp-border !sp-border-solid !sp-border-slate-300 !sp-rounded-lg !sp-bg-slate-50 !sp-text-service-navy !sp-text-xs !sp-font-sans focus:!sp-outline-none focus:!sp-ring-2 focus:!sp-ring-service-yellow focus:!sp-border-service-navy disabled:!sp-opacity-50';
  public const LABEL = '!sp-block !sp-text-[10px] !sp-font-bold !sp-uppercase !sp-tracking-wide !sp-text-service-navy !sp-mb-2';
  public const PRIMARY = '!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-4 !sp-py-2.5 !sp-rounded-lg !sp-bg-service-yellow !sp-text-service-navy !sp-border !sp-border-solid !sp-border-service-yellow !sp-text-xs !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-amber-300 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-blue disabled:!sp-opacity-50';
  public const SECONDARY = '!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-2 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-white !sp-text-service-navy !sp-border !sp-border-solid !sp-border-slate-300 !sp-text-xs !sp-font-medium !sp-font-sans !sp-cursor-pointer hover:!sp-bg-slate-50 focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow disabled:!sp-opacity-50';
  public const NAVY = '!sp-inline-flex !sp-items-center !sp-justify-center !sp-gap-1.5 !sp-px-3 !sp-py-2 !sp-rounded-lg !sp-bg-service-navy !sp-text-white !sp-border !sp-border-solid !sp-border-service-navy !sp-text-[10px] !sp-font-semibold !sp-font-sans !sp-cursor-pointer hover:!sp-bg-service-blue focus-visible:!sp-ring-2 focus-visible:!sp-ring-service-yellow';
  public static function icon(string $name, string $classes = '!sp-w-4 !sp-h-4'): string
  {
    $paths = ['bolt'=>'m13 3-9 11h7v7l9-11h-7V3z','document'=>'M9 12h6m-6 4h6M7 3h7l5 5v13H5V3h2z','filter'=>'M3 4h18l-7 8v7l-4 2v-9L3 4z','download'=>'M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4','refresh'=>'M4 4v5h5m11 11v-5h-5M4 9a8 8 0 0 1 14-4m2 10a8 8 0 0 1-14 4','eye'=>'M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z M9 12a3 3 0 1 0 6 0 3 3 0 1 0-6 0','edit'=>'m16 3 5 5-12 12H4v-5L16 3z','calendar'=>'M4 5h16v16H4V5zM8 3v4m8-4v4M4 10h16','check'=>'m5 12 4 4L19 6','alert'=>'M12 3 2 21h20L12 3zM12 9v5m0 3h.01','copy'=>'M9 8h11v13H9V8zM15 8V3H4v13h5','user'=>'M8 6a4 4 0 1 0 8 0 4 4 0 1 0-8 0M4 21v-3a8 8 0 0 1 16 0v3','building'=>'M4 21V3h12v18m0-12h4v12M8 7h4m-4 4h4m-4 4h4','drop'=>'M12 3s-7 8-7 12a7 7 0 0 0 14 0c0-4-7-12-7-12z','close'=>'m6 6 12 12M6 18 18 6'];
    return '<svg class="' . $classes . ' !sp-shrink-0" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="' . ($paths[$name] ?? $paths['document']) . '"></path></svg>';
  }
  public static function render(string $templateName, array $data): string
  {
    extract($data, EXTR_SKIP);
    ob_start(); require dirname(__DIR__, 3) . '/resources/views/public-services/' . $templateName . '.php';
    return (string) ob_get_clean();
  }
  public static function date(mixed $value): string
  {
    $ts = PublicServicesWorkspace::timestamp($value);
    return $ts > 0 ? date('d/m/Y', $ts) : '-';
  }
  public static function field(string $background = '!sp-bg-slate-50'): string
  {
    return str_replace('!sp-bg-slate-50', $background, self::INPUT);
  }
}
