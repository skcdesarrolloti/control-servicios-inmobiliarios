<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/app.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); });
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException('FAIL: ' . $message); echo 'PASS: ' . $message . PHP_EOL; };
$placeholder = ['_ID' => 48726, 'id_ticket' => 10950, 'nombre' => 'Funcionario QA', 'id_empleado' => '9001', 'cct_author_id' => 9001, 'cct_created' => '2026-10-10 10:36:05', 'respuesta' => null, 'imagen' => null, 'archivos' => null, 'fue_editada' => 'No'];
$view = new SCM\Views\GenericTicketsUiView(static fn($v) => strtotime((string) $v) ?: 0, static fn($v) => (string) $v, static fn($v) => esc_html($v), static fn() => [], static fn() => []);
$check(!str_contains($view->renderHistorialBlock([$placeholder]), 'data-history-type="reply"'), 'legacy blank creation placeholder is not rendered as a response');
$check(str_contains($view->renderHistorialBlock([$placeholder]), 'Sin historial registrado.'), 'empty legacy history uses the normal empty state');
$reply = array_replace($placeholder, ['_ID' => 48727, 'respuesta' => 'El funcionario respondió la solicitud.']);
$html = $view->renderHistorialBlock([$placeholder, $reply]);
$check(substr_count($html, 'data-history-type="reply"') === 1 && str_contains($html, $reply['respuesta']), 'actual consultant response remains visible beside an empty legacy placeholder');
foreach ([['imagen' => 'https://example.invalid/evidencia.png'], ['archivos' => [['archivo' => 'https://example.invalid/soporte.pdf']]], ['id_revision_correctiva' => 22], ['id_evento' => 23], ['respuesta' => 'Nota interna: revisión pendiente'], ['respuesta' => 'Seguimiento realizado']] as $activity) {
  $check(count(SCM\Support\TicketHistoryActivity::visible([$placeholder, array_replace($placeholder, $activity)])) === 1, 'media, linked records and real activities are preserved: ' . array_key_first($activity));
}
$propertyView = new class {
  use SCM\Modules\ServiciosInmobiliarios\Concerns\HistoryPresentationConcern;
  private int $historySeq = 0;
  public function render(array $items): string { return $this->renderHistorialBlock($items); }
};
$check(str_contains($propertyView->render([$placeholder]), 'Sin historial registrado.'), 'native property services popup also hides legacy blank responses');
$defaults = SCM\Support\PanelCaseNotifications::validateConfig([]);
$check($defaults['assigned_template'] === 'scm_caso_asignado_v1' && $defaults['external_template'] === 'scm_caso_registrado_v1', 'default template names match the confirmed v1 templates');
echo "History and template checks passed, no writes or sends.\n";
