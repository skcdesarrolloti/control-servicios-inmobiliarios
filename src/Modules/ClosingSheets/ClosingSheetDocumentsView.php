<?php

declare(strict_types=1);

namespace SCM\Modules\ClosingSheets;

/**
 * Documentos de la hoja de cierre en pantalla: casillas para adjuntar en la lista de chequeo y en la carpeta del
 * contrato (closing-documents.js sube, elimina y previsualiza en la misma página), y la lista de solo lectura de la vista
 * de la hoja.
 */
final class ClosingSheetDocumentsView
{
  public const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,application/pdf,image/jpeg,image/png,image/webp';

  /**
   * Contenedor con lo que necesita closing-documents.js.
   * @param array{api:string,nonce:string,upload:string,delete:string,scope:array<string,int>} $config
   */
  public static function rootAttributes(array $config): string
  {
    return ' data-cd-root data-cd-api="' . self::e($config['api']) . '" data-cd-nonce="' . self::e($config['nonce']) . '" data-cd-upload-action="' . self::e($config['upload'])
      . '" data-cd-delete-action="' . self::e($config['delete']) . '" data-cd-scope="' . self::e(json_encode($config['scope'])) . '"';
  }

  /** @param list<array<string,mixed>> $documents @return array<string,list<array<string,mixed>>> "ítem|persona" => documentos */
  public static function byItem(array $documents): array
  {
    $out = [];
    foreach ($documents as $doc) {
      $out[$doc['item'] . '|' . $doc['person']][] = $doc;
    }
    return $out;
  }

  /**
   * Casilla de un documento: archivos cargados y, si se puede editar, el botón para adjuntar. Con $stateField, la casilla
   * avisa cuando el ítem está en «Cumple» sin archivo.
   * @param list<array<string,mixed>> $documents
   */
  public static function slot(string $item, int $person, string $label, array $documents, bool $editable, string $stateField = ''): string
  {
    $extra = $item === ClosingSheetForm::EXTRA_DOCUMENTS;
    $chips = implode('', array_map(static fn(array $doc): string => self::chip($doc, $editable), $documents));
    return '<div class="cd-slot" data-cd-slot data-cd-item="' . self::e($item) . '" data-cd-person="' . $person . '"' . ($stateField !== '' ? ' data-cd-state="' . self::e($stateField) . '"' : '') . '>'
      . (($label !== '' || $editable) ? '<div class="cd-slot-head">' . ($label !== '' ? '<span class="cd-slot-label">' . self::e($label) . '</span>' : '')
        . ($editable ? '<button type="button" class="cd-add" data-cd-add><span class="material-symbols-outlined" aria-hidden="true">attach_file</span> Adjuntar</button>'
          . '<input type="file" class="cd-file" multiple accept="' . self::ACCEPT . '" data-cd-input data-scm-no-auto-compress hidden aria-label="Adjuntar ' . self::e($label !== '' ? $label : 'documento') . '">' : '')
        . '</div>' : '')
      . ($extra && $editable ? '<input type="text" class="cs-input cd-title" data-cd-title maxlength="120" placeholder="Nombre del documento (opcional, antes de adjuntar)" aria-label="Nombre del documento adicional">' : '')
      . '<ul class="cd-docs" data-cd-docs>' . $chips . '</ul>'
      . ($documents === [] && !$editable ? '<p class="cd-empty">Sin archivo.</p>' : '')
      . '<p class="cd-warn" data-cd-warn hidden><span class="material-symbols-outlined" aria-hidden="true">warning</span> Está en «Cumple» y no tiene archivo.</p>'
      . '<p class="cd-status" data-cd-status role="status" hidden></p>'
      . '</div>';
  }

  /** Un archivo: abrirlo en la misma página (PDF e imágenes) o descargarlo (Word, Excel) y, si se puede, eliminarlo. @param array<string,mixed> $doc */
  public static function chip(array $doc, bool $editable): string
  {
    $icon = ['pdf' => 'picture_as_pdf', 'image' => 'image'][$doc['kind']] ?? 'description';
    $meta = implode(' · ', array_filter([self::size((int) $doc['bytes']), $doc['at'] > 0 ? date('d/m/Y', (int) $doc['at']) : '', (string) $doc['by']]));
    $inner = '<span class="material-symbols-outlined" aria-hidden="true">' . $icon . '</span><span class="cd-doc-text"><span class="cd-doc-name">' . self::e($doc['name']) . '</span><small>' . self::e($meta) . '</small></span>';
    $open = $doc['kind'] === 'file'
      ? '<a class="cd-doc-open" href="' . self::e($doc['url']) . '" download>' . $inner . '</a>'
      : '<button type="button" class="cd-doc-open" data-cd-preview="' . self::e($doc['url']) . '" data-cd-kind="' . self::e($doc['kind']) . '" data-cd-name="' . self::e($doc['name']) . '">' . $inner . '</button>';
    return '<li class="cd-doc" data-cd-doc="' . (int) $doc['id'] . '">' . $open
      . ($editable ? '<button type="button" class="cd-doc-del" data-cd-delete title="Eliminar" aria-label="Eliminar ' . self::e($doc['name']) . '"><span class="material-symbols-outlined" aria-hidden="true">delete</span></button>' : '')
      . '</li>';
  }

  /** Solo lectura (vista de la hoja de cierre). Con el ítem y la persona entra en «Documentos por actor». @param list<array<string,mixed>> $documents */
  public static function readList(array $documents, string $label = '', string $item = '', int $person = 0): string
  {
    if ($documents === []) {
      return '';
    }
    return '<div class="cd-read"' . ($item !== '' ? ' data-cd-item="' . self::e($item) . '" data-cd-person="' . $person . '"' : '') . '>' . ($label !== '' ? '<span class="cd-slot-label">' . self::e($label) . '</span>' : '')
      . '<ul class="cd-docs">' . implode('', array_map(static fn(array $doc): string => self::chip($doc, false), $documents)) . '</ul></div>';
  }

  /**
   * Barra «Documentos por actor»: un botón por persona o zona (cada propietario, arrendatario, codeudores, inmueble,
   * servicios, copropiedad, aseguradora y adicionales) con sus archivos. closing-documents.js arma los botones y el popup
   * con las casillas o listas de la página (data-cd-item / data-cd-person), así siguen al día al subir o eliminar.
   * @param array<int,string> $owners índice => nombre @param array<string,mixed> $values arrendatario y codeudor_N
   */
  public static function actorBar(array $owners, array $values): string
  {
    $text = static fn(mixed $value): string => is_array($value) ? '' : trim((string) $value);
    $named = static fn(string $label, string $name): string => $name !== '' ? $label . ': ' . $name : $label;
    $actors = [];
    foreach ($owners !== [] ? $owners : [1 => $text($values['propietario'] ?? '')] as $index => $name) {
      $actors['propietario_' . max(1, (int) $index)] = ClosingSheetDocuments::personLabel((int) $index, (string) $name);
    }
    $actors['arrendatario'] = $named('Arrendatario', $text($values['arrendatario'] ?? ''));
    for ($i = 1; $i <= 4; $i++) {
      $actors['codeudor_' . $i] = $named('Codeudor ' . $i, $text($values['codeudor_' . $i] ?? ''));
    }
    $actors += ['inmueble' => 'Inmueble', 'servicios' => 'Servicios públicos', 'copropiedad' => 'Copropiedad', 'aseguradora' => 'Aseguradora', 'adicionales' => 'Documentos adicionales'];
    $items = [];
    foreach (ClosingSheetDocuments::catalog() as $item => $entry) {
      $items[$item] = [self::actorKey((string) $entry['column']), (string) $entry['label']];
    }
    $map = ['actors' => $actors, 'order' => array_keys($actors), 'items' => $items];
    return '<div class="cd-actors" data-cd-actors="' . self::e(json_encode($map, JSON_UNESCAPED_UNICODE)) . '" hidden>'
      . '<p class="cd-actors-title"><span class="material-symbols-outlined" aria-hidden="true">groups</span> Documentos por actor</p>'
      . '<div class="cd-actor-list" data-cd-actor-list></div></div>';
  }

  /** Actor de un grupo de la lista de chequeo («propietario» se completa con el número de cada propietario). */
  private static function actorKey(string $column): string
  {
    if (preg_match('/^ob_documentos_co(\d)$/', $column, $match) === 1) {
      return 'codeudor_' . $match[1];
    }
    return match ($column) {
      ClosingSheetForm::OWNER_DOCUMENTS => 'propietario',
      'ob_documentos_arrendatario' => 'arrendatario',
      'ob_servicios_publicos', 'ob_cumplimiento_servicio' => 'servicios',
      'ob_documentos_copropiedad' => 'copropiedad',
      'ob_documentos_aseguradora_cierre' => 'aseguradora',
      '' => 'adicionales',
      default => 'inmueble',
    };
  }

  /** Visor dentro de la página (PDF o imagen), confirmación de eliminar y popup de «Documentos por actor». */
  public static function dialogs(): string
  {
    return '<dialog class="cd-actor-dialog" data-cd-actor-dialog aria-labelledby="cd-actor-title"><header><strong id="cd-actor-title" data-cd-actor-title>Documentos</strong><button type="button" class="cs-secondary" data-cd-actor-close>Cerrar</button></header><div class="cd-actor-body" data-cd-actor-body></div></dialog>'
      . '<dialog class="cd-viewer" data-cd-viewer><header><strong data-cd-viewer-title>Documento</strong><button type="button" class="cs-secondary" data-cd-viewer-close>Cerrar</button></header><div class="cd-viewer-body" data-cd-viewer-body></div></dialog>'
      . '<dialog class="cd-confirm" data-cd-confirm><form method="dialog"><h2>¿Eliminar documento?</h2><p data-cd-confirm-text></p><p class="cs-note">Deja de verse en la hoja y en la carpeta del contrato. Queda registrado quién lo eliminó.</p>'
      . '<div class="cs-actions"><button type="submit" value="cancel" class="cs-secondary">Cancelar</button><button type="submit" value="ok" class="cs-primary cd-danger">Eliminar</button></div></form></dialog>';
  }

  /**
   * Carpeta del contrato o de la hoja (documentos-contrato.php): los documentos de la lista de chequeo por grupo, con su
   * estado en la hoja, los adicionales y el historial de eliminados.
   * @param array{contract:array<string,mixed>,closing:array<string,mixed>,documents:list<array<string,mixed>>,deleted:list<array<string,mixed>>,owners:array<int,string>,codebtors:int} $folder
   * @param array{api:string,nonce:string,upload:string,delete:string,scope:array<string,int>}|null $config null: solo lectura
   */
  public static function folder(array $folder, ?array $config, string $closingUrl = ''): string
  {
    $editable = $config !== null;
    $closing = $folder['closing'];
    $documents = self::byItem($folder['documents']);
    $people = $folder['closing'] !== [] ? $folder['closing'] : $folder['contract'];
    $html = '<section class="cs-section cd-intro"><p>' . ($editable
        ? 'Adjunta cada documento en su casilla (PDF, foto, Word o Excel, máximo 10 MB por archivo; las fotos se comprimen). Los del propietario van por cada propietario.'
        : 'Documentos del negocio. Tu cargo puede verlos, pero no subir ni eliminar.') . '</p>'
      . ($closingUrl !== '' ? '<p><a class="cs-link" href="' . self::e($closingUrl) . '"><span class="material-symbols-outlined" aria-hidden="true">description</span> Ver hoja de cierre #' . (int) ($closing['_ID'] ?? 0) . '</a></p>' : '')
      . ($closing === [] ? '<p class="cs-note">Este contrato no tiene hoja de cierre en el panel: los documentos quedan solo en el contrato.</p>' : '')
      . self::actorBar($folder['owners'], $people) . '</section>';
    $ph = strtolower(trim((string) ($closing['propiedad_horizontal'] ?? $folder['contract']['propiedad_horizontal'] ?? ''))) === 'si';
    foreach (ClosingSheetForm::CHECKLIST as $column => [$group, , $items]) {
      if (preg_match('/^ob_documentos_co(\d)$/', $column, $match) === 1 && (int) $match[1] > $folder['codebtors'] && !self::hasAny($documents, $items)) {
        continue;
      }
      if ($column === 'ob_documentos_copropiedad' && !$ph && !self::hasAny($documents, $items)) {
        continue;
      }
      $rows = '';
      foreach ($items as [$state]) {
        if (!isset(ClosingSheetForm::DOCUMENT_ITEMS[$state])) {
          continue;
        }
        $value = trim((string) ($closing[$state] ?? ''));
        $slots = '';
        if ($column === ClosingSheetForm::OWNER_DOCUMENTS) {
          foreach ($folder['owners'] as $index => $name) {
            $slots .= self::slot($state, (int) $index, ClosingSheetDocuments::personLabel((int) $index, $name), $documents[$state . '|' . $index] ?? [], $editable);
          }
        } else {
          $slots .= self::slot($state, 0, '', $documents[$state . '|0'] ?? [], $editable);
        }
        $rows .= '<div class="cd-item"><div class="cd-item-head"><p>' . self::e(ClosingSheetForm::DOCUMENT_ITEMS[$state]) . '</p>'
          . ($value !== '' ? '<span class="csf-result is-' . self::tone($value) . '">' . self::e($value) . '</span>' : '') . '</div>' . $slots . '</div>';
      }
      if ($rows !== '') {
        $html .= '<fieldset class="cs-section cd-group"><legend>' . self::e($group) . '</legend>' . $rows . '</fieldset>';
      }
    }
    $html .= '<fieldset class="cs-section cd-group"><legend>Documentos adicionales</legend><p class="cs-note">Otros soportes del negocio que no están en la lista de chequeo.</p>'
      . self::slot(ClosingSheetForm::EXTRA_DOCUMENTS, 0, '', $documents[ClosingSheetForm::EXTRA_DOCUMENTS . '|0'] ?? [], $editable) . '</fieldset>';
    if ($folder['deleted'] !== []) {
      $catalog = ClosingSheetDocuments::catalog();
      $html .= '<details class="cs-section cd-history"><summary>Eliminados (' . count($folder['deleted']) . ')</summary><ul>';
      foreach (array_reverse($folder['deleted']) as $doc) {
        $html .= '<li><strong>' . self::e($doc['name']) . '</strong> · ' . self::e($catalog[$doc['item']]['label'] ?? $doc['item']) . ($doc['person'] > 0 ? ' (' . self::e(ClosingSheetDocuments::personLabel($doc['person'])) . ')' : '')
          . '<small>Subido por ' . self::e($doc['by'] !== '' ? $doc['by'] : '—') . ' · eliminado el ' . date('d/m/Y H:i', $doc['deleted_at']) . ' por ' . self::e($doc['deleted_by'] !== '' ? $doc['deleted_by'] : '—') . '</small></li>';
      }
      $html .= '</ul></details>';
    }
    return '<div class="cd-folder"' . ($editable ? self::rootAttributes($config) : ' data-cd-root') . '>' . $html . '</div>' . self::dialogs();
  }

  public static function tone(string $state): string
  {
    return match (mb_strtolower(trim($state), 'UTF-8')) { 'cumple' => 'good', 'no cumple' => 'bad', default => 'muted' };
  }

  /** @param array<string,list<array<string,mixed>>> $documents @param list<array<int,string>> $items */
  private static function hasAny(array $documents, array $items): bool
  {
    foreach ($items as [$state]) {
      foreach (array_keys($documents) as $key) {
        if (str_starts_with($key, $state . '|')) {
          return true;
        }
      }
    }
    return false;
  }

  private static function size(int $bytes): string
  {
    if ($bytes <= 0) {
      return '';
    }
    return $bytes >= 1048576 ? str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
  }

  private static function e(mixed $value): string
  {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
