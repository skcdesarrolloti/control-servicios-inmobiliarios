<?php

declare(strict_types=1);

namespace SCM\Views;

final class PublicCaseView
{
  public function render(?array $case, string $error = '', string $audience = 'publico'): void
  {
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $base = rtrim((string) SCM_BASE_URL, '/');
    $audienceLabel = ['propietario' => 'Información para el propietario', 'arrendatario' => 'Información para el arrendatario', 'copropiedad' => 'Información para la copropiedad', 'funcionario' => 'Consulta para funcionarios'][$audience] ?? 'Consulta del caso';
    $login = $base . '/login.php' . ($case ? '?next=' . rawurlencode('index.php?scm_case=' . $case['_ID']) : '');
    ?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo $case ? 'Caso #' . $e($case['_ID']) : 'Caso no disponible'; ?> · SKC SuCasa Inmobiliaria</title><link rel="icon" href="<?php echo $e(\system_image('portal_favicon_url')); ?>"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"><link rel="stylesheet" href="<?php echo $e($base); ?>/assets/css/tailwind-admin.css?v=<?php echo $e(SCM_VERSION); ?>"><link rel="stylesheet" href="<?php echo $e($base); ?>/assets/css/public-case.css?v=<?php echo $e(SCM_VERSION); ?>"><script defer src="<?php echo $e($base); ?>/assets/js/public-case.js?v=<?php echo $e(SCM_VERSION); ?>"></script></head>
<body class="scm-public-case bg-slate-100 font-sans text-slate-900">
<main class="min-h-screen p-4 sm:p-8 flex items-start justify-center">
  <article class="scm-public-case-card w-full max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
    <header class="border-b border-slate-200 px-6 py-5 flex flex-wrap items-start justify-between gap-4">
      <div class="flex flex-col gap-4"><img data-brand-logo src="<?php echo $e(\system_image('portal_logo_url')); ?>" alt="SKC SuCasa Inmobiliaria" class="scm-public-case-logo"><div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">SKC SuCasa Inmobiliaria</p><h1 class="mt-1 text-2xl font-bold"><?php echo $case ? 'Caso #' . $e($case['_ID']) : 'Caso no disponible'; ?></h1><p class="mt-1 text-sm text-slate-500"><?php echo $e($audienceLabel); ?></p></div></div>
      <div class="scm-public-case-actions flex flex-wrap gap-2"><?php if ($case): ?><button type="button" data-print class="scm-new-case-button">Imprimir</button><?php endif; ?><?php if ($case && $audience === 'funcionario'): ?><a data-staff-access href="<?php echo $e($login); ?>" class="scm-new-case-button">Acceso funcionarios</a><?php endif; ?></div>
    </header>
    <div class="p-6 flex flex-col gap-6">
    <?php if (!$case): ?><p role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"><?php echo $e($error); ?></p>
    <?php else: ?>
      <section class="rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold break-words"><?php echo $e($case['asunto']); ?></h2><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-900"><?php echo $e($case['estado']); ?></span></div>
        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm"><?php foreach (['tema_ayuda' => 'Tema', 'contrato' => 'Contrato', 'inmueble' => 'Inmueble SIMI', 'direccion' => 'Dirección', 'fecha' => 'Fecha de registro'] as $key => $label): if ($case[$key] === '') continue; ?><div class="min-w-0"><dt class="text-xs font-semibold text-slate-500"><?php echo $label; ?></dt><dd class="mt-1 break-words"><?php echo $e($case[$key]); ?></dd></div><?php endforeach; ?></dl>
      </section>
      <section><h2 class="text-sm font-semibold">Descripción</h2><p class="mt-3 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700"><?php echo $e($case['descripcion']); ?></p></section>
      <?php if ($case['images'] || $case['documents']): ?>
      <section><h2 class="text-sm font-semibold">Evidencias y documentos</h2><p class="mt-1 text-xs text-slate-500">Selecciona una evidencia para verla aquí.</p>
        <div class="scm-public-case-evidence mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3"><?php foreach ($case['images'] as $i => $url): ?><button type="button" data-preview="image" data-url="<?php echo $e($url); ?>" data-title="Evidencia <?php echo $i + 1; ?>" class="rounded-xl border border-slate-200 bg-slate-50 p-2 text-left focus:ring-2 focus:ring-slate-400"><img class="h-32 w-full rounded-lg object-contain" src="<?php echo $e($url); ?>" alt="Evidencia <?php echo $i + 1; ?>" loading="lazy"><span class="mt-2 block text-xs text-slate-600">Evidencia <?php echo $i + 1; ?></span></button><?php endforeach; ?></div>
        <div class="mt-3 flex flex-col gap-2"><?php foreach ($case['documents'] as $document): ?><button type="button" data-preview="pdf" data-url="<?php echo $e($document['url']); ?>" data-title="<?php echo $e($document['name']); ?>" class="scm-new-case-button break-all text-left"><?php echo $e($document['name']); ?></button><?php endforeach; ?></div>
      </section><?php endif; ?>
      <?php if ($case['responsable'] !== ''): ?><footer class="scm-public-case-signoff border-t border-slate-200 pt-4"><p class="text-xs text-slate-500">Gestión a cargo de</p><p class="mt-1 text-sm font-semibold"><?php echo $e($case['responsable']); ?></p><p class="mt-1 text-xs text-slate-500">SKC SuCasa Inmobiliaria</p></footer><?php endif; ?>
    <?php endif; ?>
    </div>
  </article>
</main>
<dialog data-evidence-dialog class="scm-public-case-preview rounded-2xl border-0 bg-white p-0 text-slate-900 shadow-xl"><div class="flex items-center justify-between gap-4 border-b border-slate-200 p-4"><h2 data-preview-title class="text-sm font-semibold break-words">Evidencia</h2><button type="button" data-close-preview class="scm-new-case-button">Cerrar</button></div><div data-preview-body class="p-4"></div></dialog>
</body></html>
<?php
  }
}
