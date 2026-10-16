<?php

declare(strict_types=1);

namespace SCM\Modules\PreventiveReview;

use SCM\Core\Database;
use SCM\Modules\CorrectiveReview\CorrectiveReviewPublicView;
use SCM\Support\SchemaInspector;

/** Informe público nativo de la revisión preventiva (reemplaza la página de WordPress). */
final class PreventiveReviewPublicView
{
  private Database $db;
  private CorrectiveReviewPublicView $shared;

  public function __construct(Database $db)
  {
    $this->db = $db;
    $this->shared = new CorrectiveReviewPublicView($db);
  }

  /** Encabezado con logo, igual en la página pública y en el PDF. */
  public function headerHtml(): string
  {
    $logo = function_exists('system_image') ? system_image('portal_logo_url', defined('SCM_DEFAULT_PORTAL_LOGO_URL') ? (string) SCM_DEFAULT_PORTAL_LOGO_URL : '') : '';
    return '<header class="scm-corrective-public-head">'
      . '<span class="scm-corrective-public-logo">' . ($logo !== '' ? '<img src="' . $this->h($logo) . '" alt="SKC SuCasa Inmobiliaria">' : '') . '</span>'
      . '<div><p>SKC SuCasa Inmobiliaria</p><strong>Revisión preventiva</strong></div>'
      . '</header>';
  }

  /**
   * @param string $pdfUrl enlace de descarga del PDF; vacío dentro del propio PDF
   * @return array{title:string,content:string,status:int}
   */
  public function render(int $reviewId, string $pdfUrl = ''): array
  {
    if ($reviewId <= 0) {
      return $this->error('Revisión no disponible', 'El número de revisión no es válido.', 400);
    }
    $table = $this->db->table('jet_cct_revision_preventiva');
    $review = (new SchemaInspector($this->db))->tableExists($table)
      ? ($this->db->getRow("SELECT * FROM `{$table}` WHERE `_ID` = ? LIMIT 1", [$reviewId]) ?: [])
      : [];
    if (!$review) {
      return $this->error('Revisión no encontrada', 'No encontramos una revisión preventiva con ese número.', 404);
    }
    [$ticket, $contract] = $this->shared->relatedRecords($review);
    $title = 'Revisión preventiva #' . $reviewId;
    $meta = [
      '# Caso' => $this->hash($this->first([$ticket['id_ticket'] ?? '', $review['id_ticket'] ?? ''])),
      'Contrato' => $this->hash($this->first([$review['contrato'] ?? '', $contract['contrato'] ?? ''])),
      'Inmueble simi' => $this->first([$review['inmueble'] ?? '', $contract['inmueble'] ?? '']),
      'Tipo de inmueble' => $this->first([$review['tip_inm'] ?? '']),
      'Dirección' => $this->first([$review['direccion'] ?? '', $contract['direccion'] ?? '']),
      'Propietario' => $this->first([$review['propietario'] ?? '', $contract['propietario'] ?? '']),
      'Arrendatario' => $this->first([$review['arrendatario'] ?? '', $contract['arrendatario'] ?? '']),
      'Fecha' => $this->date($review),
    ];
    $findings = PreventiveReviewChecklist::findingsCount($review);
    $damagesHtml = strcasecmp((string) ($review['encontro_danos'] ?? ''), 'Si') === 0
      ? $this->shared->damageItemsHtml($review['evaluacion_de_danos'] ?? '')
      : '';
    $summary = [
      'Daños encontrados' => $this->first([$review['encontro_danos'] ?? '']) ?: '-',
      'Hallazgos en la inspección' => (string) $findings,
      'Coordinador contractual' => $this->first([$review['coordinador'] ?? '']) ?: '-',
      'Envío del informe' => PreventiveReviewChecklist::wasSent($review) ? ($this->sentDate($review) ?: 'Enviado') : 'Pendiente',
    ];

    $content = '<article class="scm-corrective-public-card scm-corrective-public-hero">'
      . '<div class="scm-corrective-public-title"><h1>' . $this->h($title) . '</h1></div>'
      . '<div class="scm-corrective-public-actions"><button type="button" data-scm-print>Imprimir</button>' . ($pdfUrl !== '' ? '<a href="' . $this->h($pdfUrl) . '" download>Descargar PDF</a>' : '') . '</div>'
      . '</article>'
      . '<section class="scm-corrective-public-card"><h2>Datos de la revisión</h2><div class="scm-corrective-public-grid">' . $this->grid($meta) . '</div></section>'
      . '<section class="scm-corrective-public-card"><h2>Resumen</h2><div class="scm-corrective-public-grid scm-corrective-public-grid--summary">' . $this->grid($summary) . '</div>'
      . '<p class="scm-prev-public-legend"><span class="is-alert">Requiere atención</span><span class="is-ok">Sin novedad</span></p></section>';
    $groups = PreventiveReviewChecklist::groups();
    $groups[] = PreventiveReviewChecklist::sendGroup();
    foreach ($groups as $group) {
      $blocksHtml = '';
      foreach ($group['blocks'] as $block) {
        $row = PreventiveReviewChecklist::storedRow($review[$block['key']] ?? '');
        // La sección del envío solo aparece cuando se diligenció al enviar el informe.
        if ($group['key'] === 'contrato_envio' && implode('', $row) === '') {
          continue;
        }
        $blocksHtml .= $this->renderBlock($block, $row);
      }
      if ($blocksHtml !== '') {
        $content .= '<section class="scm-corrective-public-card scm-prev-public-group"><h2>' . $this->h($group['title']) . '</h2>' . $blocksHtml . '</section>';
      }
    }
    if ($damagesHtml !== '') {
      $content .= '<section class="scm-corrective-public-card"><h2>Daños encontrados</h2><div class="scm-corrective-public-items">' . $damagesHtml . '</div></section>';
    }
    $recommendations = $this->first([$review['recomendaciones'] ?? '']);
    if ($recommendations !== '') {
      $content .= '<section class="scm-corrective-public-card"><h2>Recomendaciones</h2><p class="scm-prev-public-text">' . nl2br($this->h($recommendations)) . '</p></section>';
    }
    $content .= $this->shared->signatureHtml($review, 'Revisión preventiva realizada por SKC SuCasa Inmobiliaria.');

    return ['title' => $title, 'content' => $content, 'status' => 200];
  }

  /** @param array<string,mixed> $block @param array<string,string> $row */
  private function renderBlock(array $block, array $row): string
  {
    $rows = '';
    foreach ($block['questions'] as $index => $question) {
      $value = $row[PreventiveReviewChecklist::questionField($block['key'], $index)] ?? '';
      $alert = (string) ($question['alert'] ?? '');
      $class = $value === '' ? 'is-empty' : ($alert !== '' ? (strcasecmp($value, $alert) === 0 ? 'is-alert' : 'is-ok') : 'is-info');
      $label = $value === '' ? 'Sin respuesta' : (PreventiveReviewChecklist::answerOptions()[$value] ?? $value);
      $rows .= '<li class="' . $class . '"><span>' . $this->h($question['label']) . '</span><strong>' . $this->h($label) . '</strong></li>';
    }
    $observation = $this->first([$row[PreventiveReviewChecklist::observationField($block['key'])] ?? '']);
    if ($rows === '' && $observation === '') {
      return '';
    }
    return '<div class="scm-prev-public-block"><h3>' . $this->h($block['label']) . '</h3>'
      . ($rows !== '' ? '<ul>' . $rows . '</ul>' : '')
      . ($observation !== '' ? '<p class="scm-prev-public-obs"><b>' . $this->h($block['observations'] ?? 'Observaciones') . ':</b> ' . $this->h($observation) . '</p>' : '')
      . '</div>';
  }

  /** @param array<string,string> $items */
  private function grid(array $items): string
  {
    $html = '';
    foreach ($items as $label => $value) {
      $html .= '<div><span>' . $this->h($label) . '</span><strong>' . $this->h($value !== '' ? $value : '-') . '</strong></div>';
    }
    return $html;
  }

  /** @param array<string,mixed> $review */
  private function date(array $review): string
  {
    foreach ([$review['fecha'] ?? '', $review['cct_created'] ?? ''] as $raw) {
      $ts = is_numeric($raw) ? (int) $raw : (strtotime((string) $raw) ?: 0);
      if ($ts > 0) {
        return date('d/m/Y H:i', $ts);
      }
    }
    return '-';
  }

  /** @param array<string,mixed> $review */
  private function sentDate(array $review): string
  {
    $raw = $review['fecha_envio'] ?? '';
    $ts = is_numeric($raw) ? (int) $raw : (strtotime((string) $raw) ?: 0);
    return $ts > 0 ? date('d/m/Y H:i', $ts) : '';
  }

  /** @param array<int,mixed> $values */
  private function first(array $values): string
  {
    foreach ($values as $value) {
      $text = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
      if ($text !== '' && $text !== '-') {
        return $text;
      }
    }
    return '';
  }

  private function hash(string $value): string
  {
    return $value === '' ? '' : '#' . ltrim($value, '#');
  }

  /** @return array{title:string,content:string,status:int} */
  private function error(string $title, string $message, int $status): array
  {
    return [
      'title' => $title,
      'content' => '<article class="scm-corrective-public-card scm-corrective-public-error"><h1>' . $this->h($title) . '</h1><p role="alert">' . $this->h($message) . '</p></article>',
      'status' => $status,
    ];
  }

  private function h(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
