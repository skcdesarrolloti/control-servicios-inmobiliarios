<?php

namespace SCM\Views;

/**
 * Renderiza el modal "Ver Guía" con 4 pestañas:
 *  1. Estados de Tickets     (contenido PHP estático)
 *  2. Correspondencias de Daños  (CRUD vía AJAX – tabla jet_cct_correspondencias)
 *  3. Banco de Respuestas    (CRUD vía AJAX – tabla jet_cct_respuesta_de_ticket)
 *  4. Artículos Código Civil (CRUD vía AJAX – tabla jet_cct_articulos_codigo)
 */
final class GuideModalView
{
  // ─── Punto de entrada ────────────────────────────────────────────────

  public static function render(): string
  {
    ob_start();
?>
    <!-- ═══════════════════════ GUIDE OVERLAY ═══════════════════════ -->
    <div id="scm-guide-modal" class="scm-go" role="dialog" aria-modal="true" aria-label="Guía de referencia" aria-hidden="true">
      <div class="scm-go-dialog">

        <!-- Cabecera -->
        <div class="scm-go-header">
          <h3><i class="fas fa-book-open scm-go-title-icon"></i> Guía de Referencia</h3>
          <button type="button" class="scm-go-close" id="scm-close-guide" aria-label="Cerrar">&times;</button>
        </div>

        <!-- Barra de tabs principales -->
        <div class="scm-go-tabs-bar">
          <button type="button" class="scm-go-tab active" data-go-tab="estados">
            <i class="fas fa-tags"></i> Estados
          </button>
          <button type="button" class="scm-go-tab" data-go-tab="correspondencias">
            <i class="fas fa-tools"></i> Correspondencias
          </button>
          <button type="button" class="scm-go-tab" data-go-tab="respuestas">
            <i class="fas fa-comment-dots"></i> Respuestas
          </button>
          <button type="button" class="scm-go-tab" data-go-tab="articulos">
            <i class="fas fa-book-open"></i> Código Civil
          </button>
        </div>

        <!-- Paneles -->
        <div class="scm-go-body">

          <!-- ── TAB 1: Estados ──────────────────────────────────── -->
          <div class="scm-go-pane active" id="scm-go-pane-estados">
            <?php echo self::renderEstados(); ?>
          </div>

          <!-- ── TAB 2: Correspondencias ────────────────────────── -->
          <div class="scm-go-pane" id="scm-go-pane-correspondencias">
            <?php echo self::renderCrudPane(
              'gcd',
              [
                [
                  'id' => 'scm-gcd-clas',
                  'type' => 'select',
                  'placeholder' => '— Clasificación —',
                  'options' => ['Reparación Locativa', 'Reparación Necesaria', 'Mantenimiento General', 'Mejora Útil', 'Mejora Voluntaria', 'Vicio Oculto']
                ],
                [
                  'id' => 'scm-gcd-resp',
                  'type' => 'select',
                  'placeholder' => '— Responsable —',
                  'options' => ['Arrendador', 'Arrendatario', 'Ambos / Negociable', 'Propiedad Horizontal']
                ],
                ['id' => 'scm-gcd-bus', 'type' => 'text', 'placeholder' => 'Buscar situación...'],
              ]
            ); ?>
          </div>

          <!-- ── TAB 3: Banco de Respuestas ─────────────────────── -->
          <div class="scm-go-pane" id="scm-go-pane-respuestas">
            <?php echo self::renderCrudPane(
              'grt',
              [
                ['id' => 'scm-grt-est', 'type' => 'text', 'placeholder' => 'Filtrar por estado...'],
                ['id' => 'scm-grt-sit', 'type' => 'text', 'placeholder' => 'Filtrar por situación...'],
                ['id' => 'scm-grt-res', 'type' => 'text', 'placeholder' => 'Buscar en respuesta...'],
              ]
            ); ?>
          </div>

          <!-- ── TAB 4: Artículos Código Civil ──────────────────── -->
          <div class="scm-go-pane" id="scm-go-pane-articulos">
            <?php echo self::renderCrudPane(
              'gac',
              [
                [
                  'id' => 'scm-gac-cat',
                  'type' => 'select',
                  'placeholder' => '— Categoría —',
                  'options' => []
                ],
                ['id' => 'scm-gac-bus', 'type' => 'text', 'placeholder' => 'Buscar contenido...'],
              ]
            ); ?>
          </div>

        </div><!-- /scm-go-body -->
      </div><!-- /scm-go-dialog -->

      <!-- ── Modales de edición (fuera del dialog para z-index) ─── -->
      <?php echo self::renderGcdEditModal(); ?>
      <?php echo self::renderGrtEditModal(); ?>
      <?php echo self::renderGacEditModal(); ?>

    </div><!-- /scm-go (overlay) -->
  <?php
    return (string) ob_get_clean();
  }

  // ─── Tab 1: Contenido estático ───────────────────────────────────────

  private static function renderEstados(): string
  {
    $administrativos = self::getAdministrativos();

    ob_start();
  ?>
    <div class="scm-go-search-wrap">
      <input type="text" id="scm-go-search" class="scm-go-input" placeholder="&#128269; Buscar estado..." autocomplete="off" oninput="scmGoSearch()">
    </div>

    <div class="scm-go-subtabs">
      <button type="button" class="scm-go-subtab active" id="scm-go-subtab-adm" onclick="scmGoSubTab('adm')">
        <i class="fas fa-tools"></i> Administrativos
      </button>
    </div>

    <div id="scm-go-grid-adm" class="scm-go-cards-grid">
      <?php foreach ($administrativos as $item): ?>
        <div class="scm-go-card" data-search="<?php echo esc_attr(mb_strtolower($item['titulo'])); ?>">
          <div class="scm-go-card-head">
            <span class="scm-go-card-icon <?php echo esc_attr($item['color']); ?>">
              <i class="fas <?php echo esc_attr($item['icono']); ?>"></i>
            </span>
            <h4><?php echo esc_html($item['titulo']); ?></h4>
          </div>
          <div class="scm-go-card-body"><?php echo wp_kses_post($item['contenido']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="scm-go-no-results" class="scm-go-empty" style="display:none">
      <i class="fas fa-search"></i><span>Sin resultados para tu búsqueda.</span>
    </div>
  <?php
    return (string) ob_get_clean();
  }

  // ─── Tab 2/3/4: Plantilla CRUD genérica ─────────────────────────────

  private static function renderCrudPane(string $prefix, array $filters): string
  {
    ob_start();
  ?>
    <div class="scm-go-crud-header">
      <form id="scm-<?php echo esc_attr($prefix); ?>-filters" class="scm-go-filter-row" onsubmit="event.preventDefault(); scmGoLoad('<?php echo esc_attr($prefix); ?>')">
        <?php foreach ($filters as $f): ?>
          <?php if ($f['type'] === 'select'): ?>
            <select id="<?php echo esc_attr($f['id']); ?>" class="scm-go-select">
              <option value=""><?php echo esc_html($f['placeholder']); ?></option>
              <?php foreach ($f['options'] as $opt): ?>
                <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" id="<?php echo esc_attr($f['id']); ?>" class="scm-go-input" placeholder="<?php echo esc_attr($f['placeholder']); ?>">
          <?php endif; ?>
        <?php endforeach; ?>
        <button type="submit" class="scm-go-btn scm-go-btn--primary">
          <i class="fas fa-search"></i> Filtrar
        </button>
        <button type="button" class="scm-go-btn scm-go-btn--secondary" onclick="scmGoReset('<?php echo esc_attr($prefix); ?>')">
          <i class="fas fa-undo"></i> Limpiar
        </button>
      </form>
      <button type="button" class="scm-go-btn scm-go-btn--success" onclick="scmGoModal('<?php echo esc_attr($prefix); ?>', null)">
        <i class="fas fa-plus-circle"></i> Nuevo
      </button>
    </div>
    <div id="scm-<?php echo esc_attr($prefix); ?>-result" class="scm-go-result">
      <div class="scm-go-loading"><i class="fas fa-circle-notch fa-spin"></i></div>
    </div>
  <?php
    return (string) ob_get_clean();
  }

  // ─── Modales de edición ──────────────────────────────────────────────

  private static function renderGcdEditModal(): string
  {
    $clasificaciones = ['Reparación Locativa', 'Reparación Necesaria', 'Mantenimiento General', 'Mejora Útil', 'Mejora Voluntaria', 'Vicio Oculto'];
    $responsables    = ['Arrendador', 'Arrendatario', 'Ambos / Negociable', 'Propiedad Horizontal'];

    ob_start();
  ?>
    <div id="scm-gcd-edit" class="scm-go-edit-overlay" style="display:none" aria-hidden="true">
      <div class="scm-go-edit-dialog">
        <div class="scm-go-edit-header">
          <h4 id="scm-gcd-edit-title"><i class="fas fa-tools"></i> Correspondencia</h4>
          <button type="button" class="scm-go-close" onclick="document.getElementById('scm-gcd-edit').style.display='none'">&times;</button>
        </div>
        <div class="scm-go-edit-body">
          <input type="hidden" id="scm-gcd-id">
          <div class="scm-go-field">
            <label>Situación <span class="scm-go-req">*</span></label>
            <input type="text" id="scm-gcd-desc" class="scm-go-input" placeholder="Ej: Pintura deteriorada en paredes...">
          </div>
          <div class="scm-go-row-2">
            <div class="scm-go-field">
              <label>Clasificación <span class="scm-go-req">*</span></label>
              <select id="scm-gcd-clas-m" class="scm-go-select">
                <?php foreach ($clasificaciones as $c): ?>
                  <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="scm-go-field">
              <label>Responsable <span class="scm-go-req">*</span></label>
              <select id="scm-gcd-resp-m" class="scm-go-select">
                <?php foreach ($responsables as $r): ?>
                  <option value="<?php echo esc_attr($r); ?>"><?php echo esc_html($r); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="scm-go-row-2">
            <div class="scm-go-field">
              <label>Fundamento Legal</label>
              <textarea id="scm-gcd-legal" class="scm-go-textarea" rows="4"></textarea>
            </div>
            <div class="scm-go-field">
              <label>Reembolso</label>
              <textarea id="scm-gcd-reem" class="scm-go-textarea" rows="4"></textarea>
            </div>
          </div>
          <div class="scm-go-field">
            <label>Observaciones / Excepciones</label>
            <textarea id="scm-gcd-obs" class="scm-go-textarea" rows="5"></textarea>
          </div>
        </div>
        <div class="scm-go-edit-footer">
          <button type="button" class="scm-go-btn scm-go-btn--secondary" onclick="document.getElementById('scm-gcd-edit').style.display='none'">Cancelar</button>
          <button type="button" class="scm-go-btn scm-go-btn--primary" id="scm-gcd-save-btn" onclick="scmGoSave('gcd')">
            <i class="fas fa-save"></i> Guardar
          </button>
        </div>
      </div>
    </div>
  <?php
    return (string) ob_get_clean();
  }

  private static function renderGrtEditModal(): string
  {
    $categorias = ['Administrativo'];

    ob_start();
  ?>
    <div id="scm-grt-edit" class="scm-go-edit-overlay" style="display:none" aria-hidden="true">
      <div class="scm-go-edit-dialog">
        <div class="scm-go-edit-header">
          <h4 id="scm-grt-edit-title"><i class="fas fa-comment-dots"></i> Respuesta</h4>
          <button type="button" class="scm-go-close" onclick="document.getElementById('scm-grt-edit').style.display='none'">&times;</button>
        </div>
        <div class="scm-go-edit-body">
          <input type="hidden" id="scm-grt-id">
          <div class="scm-go-row-2">
            <div class="scm-go-field">
              <label>Categoría</label>
              <select id="scm-grt-cat-m" class="scm-go-select">
                <?php foreach ($categorias as $c): ?>
                  <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="scm-go-field">
              <label>Estado</label>
              <input type="text" id="scm-grt-est" class="scm-go-input">
            </div>
          </div>
          <div class="scm-go-field">
            <label>Situación</label>
            <input type="text" id="scm-grt-sit" class="scm-go-input">
          </div>
          <div class="scm-go-field">
            <label>Respuesta</label>
            <textarea id="scm-grt-res" class="scm-go-textarea" rows="6"></textarea>
          </div>
        </div>
        <div class="scm-go-edit-footer">
          <button type="button" class="scm-go-btn scm-go-btn--secondary" onclick="document.getElementById('scm-grt-edit').style.display='none'">Cancelar</button>
          <button type="button" class="scm-go-btn scm-go-btn--primary" id="scm-grt-save-btn" onclick="scmGoSave('grt')">
            <i class="fas fa-save"></i> Guardar
          </button>
        </div>
      </div>
    </div>
  <?php
    return (string) ob_get_clean();
  }

  private static function renderGacEditModal(): string
  {
    ob_start();
  ?>
    <div id="scm-gac-edit" class="scm-go-edit-overlay" style="display:none" aria-hidden="true">
      <div class="scm-go-edit-dialog">
        <div class="scm-go-edit-header">
          <h4 id="scm-gac-edit-title"><i class="fas fa-book-open"></i> Artículo Código Civil</h4>
          <button type="button" class="scm-go-close" onclick="document.getElementById('scm-gac-edit').style.display='none'">&times;</button>
        </div>
        <div class="scm-go-edit-body">
          <input type="hidden" id="scm-gac-id">
          <div class="scm-go-field">
            <label>Categoría <span class="scm-go-req">*</span></label>
            <input type="text" id="scm-gac-cat-m" class="scm-go-input" placeholder="Ej: Obligaciones, Contratos...">
          </div>
          <div class="scm-go-field">
            <label>Contenido del Artículo <span class="scm-go-req">*</span></label>
            <textarea id="scm-gac-cont" class="scm-go-textarea" rows="10"></textarea>
          </div>
        </div>
        <div class="scm-go-edit-footer">
          <button type="button" class="scm-go-btn scm-go-btn--secondary" onclick="document.getElementById('scm-gac-edit').style.display='none'">Cancelar</button>
          <button type="button" class="scm-go-btn scm-go-btn--primary" id="scm-gac-save-btn" onclick="scmGoSave('gac')">
            <i class="fas fa-save"></i> Guardar
          </button>
        </div>
      </div>
    </div>
<?php
    return (string) ob_get_clean();
  }

  // ─── Datos estáticos: Comerciales ────────────────────────────────────

  /** @return array<int, array<string, string>> */
  private static function getComerciales(): array
  {
    return [
      [
        'titulo' => 'Nuevo',
        'icono'  => 'fa-star',
        'color'  => 'scm-gc-yellow',
        'desc'   => 'Estado inicial generado automáticamente cuando se crea un ticket.',
      ],
      [
        'titulo' => 'Contactado',
        'icono'  => 'fa-headset',
        'color'  => 'scm-gc-blue',
        'desc'   => 'El A/C estableció contacto (llamada, email, WhatsApp o personalmente) con el cliente.',
      ],
      [
        'titulo' => 'Prospectado',
        'icono'  => 'fa-magnifying-glass-dollar',
        'color'  => 'scm-gc-green',
        'desc'   => 'Se estableció relación con el cliente y se diligencia el formulario de prospectación para conocer su necesidad.',
      ],
      [
        'titulo' => 'Mostrando',
        'icono'  => 'fa-eye',
        'color'  => 'scm-gc-purple',
        'desc'   => 'Se hace la presentación de inmuebles requeridos (máx. 4). Puede ser presencial, documental o virtual.',
      ],
      [
        'titulo' => 'En cierre',
        'icono'  => 'fa-file-signature',
        'color'  => 'scm-gc-indigo',
        'desc'   => 'En proceso de elaboración de contrato o promesa de venta. Incluye revisión de servicios y paz y salvos.',
        'extra'  => '<p style="margin:.5rem 0 .25rem;font-weight:700;font-size:.7rem;color:#6b7280;text-transform:uppercase;">Tiempo estimado según conservación:</p><ul style="list-style:none;padding:0;margin:0;font-size:.82rem"><li><strong style="color:#16a34a">5</strong> — Entrega inmediata</li><li><strong style="color:#16a34a">4</strong> — 1 día hábil</li><li><strong style="color:#ca8a04">3</strong> — 2 días hábiles</li><li><strong style="color:#ea580c">2</strong> — 5 días hábiles</li><li><strong style="color:#dc2626">1</strong> — Sin fecha definida</li></ul>',
      ],
      [
        'titulo' => 'Entregado',
        'icono'  => 'fa-key',
        'color'  => 'scm-gc-teal',
        'desc'   => 'Se realiza la entrega física del inmueble al arrendatario o comprador.',
      ],
      [
        'titulo' => 'Por publicar',
        'icono'  => 'fa-cloud-upload-alt',
        'color'  => 'scm-gc-cyan',
        'desc'   => 'Captación/re-captación agendada; está en proceso de ser verificada y publicada por el asistente.',
      ],
      [
        'titulo' => 'En estudio',
        'icono'  => 'fa-file-contract',
        'color'  => 'scm-gc-blue-dk',
        'desc'   => 'Documentos enviados a la Afianzadora o al Banco.',
        'nota'   => 'Una vez se obtenga el asegurable se debe hacer la inspección antes de la ocupación.',
      ],
      [
        'titulo' => 'En búsqueda',
        'icono'  => 'fa-binoculars',
        'color'  => 'scm-gc-amber',
        'desc'   => 'No existe en la oferta el inmueble requerido y se sale a conseguirlo.',
      ],
      [
        'titulo' => 'Captado',
        'icono'  => 'fa-check-circle',
        'color'  => 'scm-gc-green-lt',
        'desc'   => 'El inmueble fue captado y hace parte del portafolio a promocionar.',
      ],
      [
        'titulo' => 'Recaptado',
        'icono'  => 'fa-sync-alt',
        'color'  => 'scm-gc-green-dk',
        'desc'   => 'El inmueble fue recaptado y vuelve al portafolio a promocionar.',
      ],
      [
        'titulo' => 'Aplazado',
        'icono'  => 'fa-clock',
        'color'  => 'scm-gc-orange',
        'desc'   => 'Las aseguradoras o entidades financieras aplazan la solicitud por falta de documentos.',
      ],
      [
        'titulo' => 'Trasladado',
        'icono'  => 'fa-exchange-alt',
        'color'  => 'scm-gc-gray',
        'desc'   => 'El cliente solicita cambio en la destinación o cambio de asesor.',
      ],
      [
        'titulo' => 'Rechazado',
        'icono'  => 'fa-ban',
        'color'  => 'scm-gc-red',
        'desc'   => 'Las aseguradoras niegan el arriendo o el Banco niega el crédito.',
      ],
      [
        'titulo' => 'Postergado',
        'icono'  => 'fa-calendar-minus',
        'color'  => 'scm-gc-gray-lt',
        'desc'   => 'El cliente solicita prolongación en el tiempo para definir el negocio.',
      ],
      [
        'titulo' => 'Desistido',
        'icono'  => 'fa-times-circle',
        'color'  => 'scm-gc-red-lt',
        'desc'   => 'El cliente desiste del negocio o el presupuesto no se ajusta al mercado.',
      ],
    ];
  }

  // ─── Datos estáticos: Administrativos ───────────────────────────────

  /** @return array<int, array<string, string>> */
  private static function getAdministrativos(): array
  {
    return [
      [
        'titulo'    => 'Nuevo',
        'icono'     => 'fa-bolt',
        'color'     => 'scm-gc-yellow',
        'contenido' => '<strong>Definición:</strong> Estado inicial para notificar asignación de tarea.<br><br><strong>Política:</strong> Atender de forma inmediata (2 h máx.) y coordinar visita.',
      ],
      [
        'titulo'    => 'Por inspeccionar',
        'icono'     => 'fa-calendar-check',
        'color'     => 'scm-gc-blue',
        'contenido' => '<strong>Definición:</strong> Se ha contactado al cliente para establecer fecha de visita.<br><br><strong>Política:</strong> Dejar constancia de fecha y hora de la visita.',
      ],
      [
        'titulo'    => 'Inspeccionado',
        'icono'     => 'fa-clipboard-list',
        'color'     => 'scm-gc-indigo',
        'contenido' => '<strong>Definición:</strong> Valoración técnica de daños, causas y consecuencias.<br><br><strong>Política:</strong> Clasificar actividades de solución. Registro fotográfico con FECHA, HORA, DIRECCIÓN y COORDENADAS.',
      ],
      [
        'titulo'    => 'Cotizado',
        'icono'     => 'fa-file-invoice-dollar',
        'color'     => 'scm-gc-green',
        'contenido' => '<strong>Definición:</strong> Valoración económica (mano de obra, materiales, etc.).<br><br><strong>Política:</strong> Discriminar actividades detalladamente y anexar cotizaciones de proveedores.',
      ],
      [
        'titulo'    => 'En ejecución',
        'icono'     => 'fa-tools',
        'color'     => 'scm-gc-orange',
        'contenido' => '<strong>Definición:</strong> Asignación del trabajo de reparación.<br><br><strong>Política:</strong> 1. Seguimiento con fotos. 2. Si el propietario realiza la reparación, estar pendiente para acta de satisfacción.',
      ],
      [
        'titulo'    => 'Finalizado',
        'icono'     => 'fa-flag-checkered',
        'color'     => 'scm-gc-green-dk',
        'contenido' => '<strong>Definición:</strong> Reparación terminada y acta firmada.<br><br><strong>Política:</strong> Solo cerrar ticket con Acta de Satisfacción firmada. <em>Encuesta se envía con el acta.</em>',
      ],
      [
        'titulo'    => 'Entregado',
        'icono'     => 'fa-key',
        'color'     => 'scm-gc-teal',
        'contenido' => '<strong>Definición:</strong> Entrega del inmueble al Arrendatario.<br><br><strong>Política:</strong> Revisión previa, Inventario, Acta de entrega y Fotos (Fecha/Hora/Coords). <em>Encuesta con el acta.</em>',
      ],
      [
        'titulo'    => 'Recibido',
        'icono'     => 'fa-box-open',
        'color'     => 'scm-gc-blue-dk',
        'contenido' => '<strong>Definición:</strong> Recepción del inmueble del Arrendatario o devolución al Propietario.<br><br><strong>Política:</strong> Revisión, Acta de desocupación/recibo y Fotos. <em>Encuesta con el acta.</em>',
      ],
      [
        'titulo'    => 'Desistido',
        'icono'     => 'fa-thumbs-down',
        'color'     => 'scm-gc-red-lt',
        'contenido' => '<strong>Definición:</strong> Negocio no concretado o reparación no aprobada.<br><br><strong>Política:</strong> Contener pruebas en historial (chats, fotos, correos).',
      ],
      [
        'titulo'    => 'Trasladado',
        'icono'     => 'fa-share-square',
        'color'     => 'scm-gc-gray',
        'contenido' => '<strong>Definición:</strong> Responsabilidad trasladada a otro funcionario.<br><br><strong>Política:</strong> Daños de tercero (Copropiedad) se reasignan al líder de área.',
      ],
    ];
  }
}
