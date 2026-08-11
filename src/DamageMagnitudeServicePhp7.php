<?php
/**
 * Servicio para leer tickets con revision correctiva y calcular magnitud de daños.
 * Autor: Royner Guardo
 *
 * Requiere una conexión PDO activa.
 */

final class DamageMagnitudeServicePhp7
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix = 'wp_')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    /**
     * Endpoint JSON listo para llamar desde api.php.
     */
    public function handleRequest(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $filters = [
                'q'          => trim((string)($_GET['q'] ?? '')),
                'ticket'     => trim((string)($_GET['ticket'] ?? '')),
                'contrato'   => trim((string)($_GET['contrato'] ?? '')),
                'inmueble'   => trim((string)($_GET['inmueble'] ?? '')),
                'cotizacion' => trim((string)($_GET['cotizacion'] ?? '')),
                'estado'     => trim((string)($_GET['estado'] ?? '')),
                'sucursal'   => trim((string)($_GET['sucursal'] ?? '')),
                'empleado'   => trim((string)($_GET['empleado'] ?? '')),
                'magnitud'   => trim((string)($_GET['magnitud'] ?? '')),
                'revision_type' => trim((string)($_GET['revision_type'] ?? 'correctiva')),
                'limit'      => max(1, min(500, (int)($_GET['limit'] ?? 150))),
                'offset'     => max(0, (int)($_GET['offset'] ?? 0)),
            ];

            $data = $this->getTicketsWithDamageMagnitude($filters);
            echo json_encode([
                'ok' => true,
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'No se pudo consultar la magnitud de daños.',
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function getTicketsWithDamageMagnitude(array $filters = []): array
    {
        $revisionConfig = $this->getRevisionConfig((string)($filters['revision_type'] ?? 'correctiva'));
        $ticketsTable = $this->prefix . 'jet_cct_tickets';
        $revisionTable = $this->prefix . $revisionConfig['table'];
        $cotizacionTable = $this->prefix . 'jet_cct_cotizacion_mantenimiento';
        $revisionField = $revisionConfig['ticket_field'];
        $revisionRecipient = $revisionConfig['recipient_sql'];
        $revisionPhone = $revisionConfig['phone_sql'];
        $revisionEmail = $revisionConfig['email_sql'];

        $where = [
            "(
                LOWER(TRIM(COALESCE(t.departamento, ''))) = 'mantenimiento'
                OR LOWER(TRIM(COALESCE(t.tema_ayuda, ''))) IN (
                    'reparaciones necesarias',
                    'reparaciones locativas',
                    'mejoras utiles',
                    'mejoras útiles',
                    'reparaciones voluntarias',
                    'reparaciones antes de la entrega',
                    'reparaciones antes del recibo'
                )
                OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE '%reparacion%'
                OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE '%mantenimiento%'
            )",
            "LOWER(TRIM(COALESCE(t.estado, ''))) IN ('nuevo', 'en proceso')",
        ];
        $where = [
            "TRIM(COALESCE(t.{$revisionField}, '')) <> ''",
            "({$revisionConfig['topic_sql']})",
            "LOWER(TRIM(COALESCE(t.estado, ''))) IN ('nuevo', 'en proceso')",
        ];
        $params = [];

        if (!empty($filters['estado'])) {
            $where[] = "t.estado = :estado";
            $params[':estado'] = $filters['estado'];
        }

        if (!empty($filters['sucursal'])) {
            $where[] = "(t.sucursal = :sucursal_0 OR rc.sucursal = :sucursal_1)";
            $params[':sucursal_0'] = $filters['sucursal'];
            $params[':sucursal_1'] = $filters['sucursal'];
        }

        if (!empty($filters['empleado'])) {
            $where[] = "(t.id_empleado = :empleado_0 OR rc.id_empleado = :empleado_1 OR t.nombre_empleado LIKE :empleado_2 OR t.empleado LIKE :empleado_3)";
            $params[':empleado_0'] = $filters['empleado'];
            $params[':empleado_1'] = $filters['empleado'];
            $params[':empleado_2'] = '%' . $filters['empleado'] . '%';
            $params[':empleado_3'] = '%' . $filters['empleado'] . '%';
        }

        if (!empty($filters['q'])) {
            $this->addLikeAnyFilter($where, $params, [
                't.id_ticket',
                'rc.id_ticket',
                't.asunto',
                't.descripcion',
                't.direccion',
                't.inmueble',
                't.id_inmueble',
                't.contrato',
                't.id_contrato',
                't.solicitante',
                'rc.inmueble',
                'rc.id_inmueble',
                'rc.direccion',
                'rc.contrato',
                'rc.id_contrato',
            ], $filters['q'], 'q');
        }

        if (!empty($filters['ticket'])) {
            $this->addLikeAnyFilter($where, $params, ['t.id_ticket', 'CAST(t._ID AS CHAR)', 'rc.id_ticket'], $filters['ticket'], 'ticket');
        }

        if (!empty($filters['contrato'])) {
            $this->addLikeAnyFilter($where, $params, ['t.contrato', 't.id_contrato', 'rc.contrato', 'rc.id_contrato'], $filters['contrato'], 'contrato');
        }

        if (!empty($filters['inmueble'])) {
            $this->addLikeAnyFilter($where, $params, ['t.inmueble', 't.id_inmueble', 't.direccion', 'rc.inmueble', 'rc.id_inmueble', 'rc.direccion'], $filters['inmueble'], 'inmueble');
        }

        $hasCotizacionSql = "(
            TRIM(COALESCE(t.id_cotizacion_mantenimiento, '')) <> ''
            OR LOWER(TRIM(COALESCE(rc.tiene_cotizacion, ''))) IN ('si', '1', 'true')
        )";
        if (($filters['cotizacion'] ?? '') === 'has') {
            $where[] = $hasCotizacionSql;
        } elseif (($filters['cotizacion'] ?? '') === 'none') {
            $where[] = "NOT {$hasCotizacionSql}";
        }

        $limit = max(1, min(500, (int)($filters['limit'] ?? 150)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $sql = "
            SELECT
                t._ID AS ticket_row_id,
                t.id_ticket,
                t.asunto,
                t.descripcion,
                t.estado,
                t.prioridad,
                t.tema_ayuda,
                t.departamento,
                t.direccion AS ticket_direccion,
                t.inmueble AS ticket_inmueble,
                t.fecha AS ticket_fecha,
                t.fecha_actualizacion,
                t.solicitante,
                t.celular_solicitante,
                t.correo_solicitante,
                t.nombre_empleado,
                t.empleado,
                t.id_empleado AS ticket_id_empleado,
                t.id_inmueble,
                t.id_contrato,
                t.contrato AS ticket_contrato,
                t.sucursal AS ticket_sucursal,
                t.id_revision_correctiva,
                t.id_revision_preventiva,
                t.id_cotizacion_mantenimiento,
                t.final_trabajo AS ticket_final_trabajo,

                rc._ID AS revision_id,
                rc.id_ticket AS revision_ticket_id,
                rc.fecha AS revision_fecha,
                rc.evaluacion_de_danos,
                rc.tiene_cotizacion,
                {$revisionRecipient} AS destinatario,
                {$revisionPhone} AS celular_destinatario,
                {$revisionEmail} AS email_destinatario,
                rc.contrato AS revision_contrato,
                rc.id_contrato AS revision_id_contrato,
                rc.inmueble AS revision_inmueble,
                rc.id_inmueble AS revision_id_inmueble,
                rc.direccion AS revision_direccion,
                rc.tip_inm,
                rc.creador,
                rc.id_empleado AS revision_id_empleado,
                rc.coordinador,
                rc.tipo_negocio,
                rc.destinacion,
                rc.sucursal AS revision_sucursal,

                cot._ID AS cotizacion_id,
                cot.estado AS cotizacion_estado
            FROM {$ticketsTable} t
            LEFT JOIN {$revisionTable} rc
                ON CAST(NULLIF(TRIM(t.{$revisionField}), '') AS UNSIGNED) = rc._ID
            LEFT JOIN {$cotizacionTable} cot
                ON CAST(NULLIF(TRIM(t.id_cotizacion_mantenimiento), '') AS UNSIGNED) = cot._ID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(t.fecha_actualizacion, t.fecha, rc.fecha, 0) DESC, t._ID DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tickets = [];
        $summary = [
            'critico' => 0,
            'alto' => 0,
            'medio' => 0,
            'bajo' => 0,
            'sin_datos' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $evaluation = $this->decodeEvaluation($row['evaluacion_de_danos'] ?? '');
            $magnitude = $this->calculateMagnitude($evaluation, $row);

            if ($magnitude['key'] === 'sin_datos') {
                continue;
            }

            if (!empty($filters['magnitud']) && $filters['magnitud'] !== $magnitude['key']) {
                continue;
            }

            $summary[$magnitude['key']] = ($summary[$magnitude['key']] ?? 0) + 1;
            $summary['total']++;

            $ticketId = trim((string)($row['id_ticket'] ?: ($row['revision_ticket_id'] ?: $row['ticket_row_id'])));
            $hasCotizacion = $this->rowHasCotizacion($row);

            $damageItems = $this->extractDamageItems($evaluation);

            $tickets[] = [
                'ticket_row_id' => (int)$row['ticket_row_id'],
                'id_ticket' => $ticketId,
                'revision_ticket_id' => $row['revision_ticket_id'] ?: '',
                'revision_id' => (int)$row['revision_id'],
                'revision_type' => $revisionConfig['type'],
                'revision_label' => $revisionConfig['label'],
                'id_revision_correctiva' => $row['id_revision_correctiva'],
                'id_revision_preventiva' => $row['id_revision_preventiva'],
                'asunto' => $row['asunto'] ?: 'Sin asunto',
                'descripcion' => $this->cleanText($row['descripcion'] ?? ''),
                'estado' => $row['estado'] ?: 'Sin estado',
                'prioridad' => $row['prioridad'] ?: '',
                'tema_ayuda' => $row['tema_ayuda'] ?: '',
                'departamento' => $row['departamento'] ?: '',
                'direccion' => $row['revision_direccion'] ?: ($row['ticket_direccion'] ?: ''),
                'inmueble' => $row['revision_inmueble'] ?: ($row['revision_id_inmueble'] ?: ($row['ticket_inmueble'] ?: ($row['id_inmueble'] ?: ''))),
                'contrato' => $row['revision_contrato'] ?: ($row['revision_id_contrato'] ?: ($row['ticket_contrato'] ?: ($row['id_contrato'] ?: ''))),
                'sucursal' => $row['revision_sucursal'] ?: ($row['ticket_sucursal'] ?: ''),
                'fecha' => $this->formatUnixDate($row['ticket_fecha'] ?: $row['revision_fecha'] ?: null),
                'fecha_actualizacion' => $this->formatUnixDate($row['fecha_actualizacion'] ?: null),
                'solicitante' => $row['solicitante'] ?: ($row['destinatario'] ?: ''),
                'celular' => $row['celular_solicitante'] ?: ($row['celular_destinatario'] ?: ''),
                'correo' => $row['correo_solicitante'] ?: ($row['email_destinatario'] ?: ''),
                'empleado' => $row['nombre_empleado'] ?: ($row['empleado'] ?: ($row['creador'] ?: '')),
                'coordinador' => $row['coordinador'] ?: '',
                'tiene_cotizacion' => $hasCotizacion ? 'Si' : 'No',
                'cotizacion_id' => $row['cotizacion_id'] ?: ($row['id_cotizacion_mantenimiento'] ?: ''),
                'trabajo_finalizado' => 'No',
                'tipo_negocio' => $row['tipo_negocio'] ?: '',
                'destinacion' => $row['destinacion'] ?: '',
                'magnitud' => $magnitude,
                'matriz' => $this->buildMatrix($evaluation, $magnitude),
                'danos_detectados' => $this->hydrateDamagePhotos($damageItems),
            ];
        }

        return [
            'summary' => $summary,
            'tickets' => $tickets,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'returned' => count($tickets),
            ],
        ];
    }

    private function decodeEvaluation(?string $raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }

        // JetEngine/WordPress puede guardar JSON, serializado PHP o cadenas escapadas.
        $variants = array_values(array_unique([
            $raw,
            html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            stripslashes($raw),
            stripslashes(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ]));

        foreach ($variants as $candidate) {
            $json = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        foreach ($variants as $candidate) {
            $unserialized = @unserialize($candidate, ['allowed_classes' => false]);
            if ($unserialized !== false || $candidate === 'b:0;') {
                return is_array($unserialized) ? $unserialized : ['valor' => $unserialized];
            }
        }

        // Fallback: intenta convertir pares simples tipo clave: valor separados por saltos o comas.
        return ['texto' => $raw];
    }

    private function getRevisionConfig(string $type): array
    {
        $type = strtolower(trim($type));
        if ($type === 'preventiva') {
            return [
                'type' => 'preventiva',
                'label' => 'Revision preventiva',
                'table' => 'jet_cct_revision_preventiva',
                'ticket_field' => 'id_revision_preventiva',
                'topic_sql' => "LOWER(COALESCE(t.tema_ayuda, '')) LIKE '%preventiva%'",
                'recipient_sql' => "COALESCE(rc.creador, rc.propietario, rc.arrendatario, '')",
                'phone_sql' => "COALESCE(rc.celular_creador, rc.celular_propietario, rc.celular_arrendatario, '')",
                'email_sql' => "COALESCE(rc.email_creador, rc.email_propietario, rc.email_arrendatario, '')",
            ];
        }

        return [
            'type' => 'correctiva',
            'label' => 'Revision correctiva',
            'table' => 'jet_cct_revision_correctiva',
            'ticket_field' => 'id_revision_correctiva',
            'topic_sql' => "
                LOWER(TRIM(COALESCE(t.departamento, ''))) = 'mantenimiento'
                OR LOWER(TRIM(COALESCE(t.tema_ayuda, ''))) IN (
                    'reparaciones necesarias',
                    'reparaciones locativas',
                    'mejoras utiles',
                    'mejoras utiles',
                    'reparaciones voluntarias',
                    'reparaciones antes de la entrega',
                    'reparaciones antes del recibo'
                )
                OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE '%reparacion%'
                OR LOWER(COALESCE(t.tema_ayuda, '')) LIKE '%mantenimiento%'
            ",
            'recipient_sql' => "COALESCE(rc.destinatario, rc.creador, '')",
            'phone_sql' => "COALESCE(rc.celular_destinatario, rc.celular_creador, '')",
            'email_sql' => "COALESCE(rc.email_destinatario, rc.email_creador, '')",
        ];
    }

    private function addLikeAnyFilter(array &$where, array &$params, array $columns, string $value, string $prefix): void
    {
        $parts = [];
        $index = 0;
        foreach ($columns as $column) {
            $key = ':' . $prefix . '_' . $index;
            $parts[] = $column . ' LIKE ' . $key;
            $params[$key] = '%' . $value . '%';
            $index++;
        }
        if (!empty($parts)) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    private function rowHasCotizacion(array $row): bool
    {
        $ticketCotizacion = trim((string)($row['id_cotizacion_mantenimiento'] ?? '')) !== '';
        $revisionCotizacion = $this->containsAny(
            $this->normalizeText((string)($row['tiene_cotizacion'] ?? '')),
            ['si', '1', 'true']
        );
        return $ticketCotizacion || $revisionCotizacion;
    }

    private function calculateMagnitude(array $evaluation, array $row): array
    {
        $flat = $this->flattenEvaluation($evaluation);
        $joined = $this->normalizeText(implode(' | ', array_map(static function ($item) {
            return ($item['label'] ?? '') . ' ' . ($item['value'] ?? '');
        }, $flat)));

        if (empty($flat) && trim($joined) === '') {
            return $this->magnitudePayload('sin_datos', 0, [], 0, 0, 0, 0);
        }

        $criticalKeywords = [
            'critico', 'critica', 'muy grave', 'emergencia', 'urgente', 'inmediato',
            'riesgo electrico', 'corto circuito', 'cable expuesto', 'electrocucion',
            'fuga de gas', 'olor a gas', 'incendio', 'humo',
            'estructural', 'colapso', 'grieta estructural', 'techo caido', 'cielorraso caido',
            'inundacion', 'aguas negras', 'alcantarillado rebosado', 'no habitable', 'inhabitable'
        ];

        $highKeywords = [
            'grave', 'alto', 'severo', 'considerable', 'filtracion fuerte', 'humedad severa',
            'fuga de agua', 'tuberia rota', 'sin energia', 'sin agua', 'sin gas',
            'puerta principal danada', 'cerradura principal', 'ventana rota', 'vidrio roto'
        ];

        $mediumKeywords = [
            'medio', 'moderado', 'regular', 'reparacion', 'humedad', 'filtracion',
            'goteo', 'fisura', 'desprendimiento', 'deterioro', 'enchufe', 'tomacorriente',
            'sanitario', 'lavamanos', 'ducha', 'pintura', 'ceramica', 'baldosa'
        ];

        $lowKeywords = [
            'leve', 'bajo', 'menor', 'detalle', 'rayon', 'mancha', 'ajuste', 'limpieza',
            'desgaste normal', 'normal', 'bueno', 'funciona', 'sin dano', 'sin daño'
        ];

        $critical = $this->countKeywordHits($joined, $criticalKeywords);
        $high = $this->countKeywordHits($joined, $highKeywords);
        $medium = $this->countKeywordHits($joined, $mediumKeywords);
        $low = $this->countKeywordHits($joined, $lowKeywords);

        // Si hay campos explícitos de nivel/magnitud/estado, pesan más.
        foreach ($flat as $item) {
            $label = $this->normalizeText((string)($item['label'] ?? ''));
            $value = $this->normalizeText((string)($item['value'] ?? ''));
            if (preg_match('/nivel|magnitud|gravedad|severidad|dan[oñ]|estado|calificacion|evaluacion/', $label)) {
                if ($this->containsAny($value, ['critico', 'critica', 'emergencia', 'muy grave'])) {
                    $critical += 3;
                } elseif ($this->containsAny($value, ['grave', 'alto', 'severo'])) {
                    $high += 3;
                } elseif ($this->containsAny($value, ['medio', 'moderado', 'regular'])) {
                    $medium += 2;
                } elseif ($this->containsAny($value, ['leve', 'bajo', 'menor', 'bueno', 'sin dano', 'sin daño'])) {
                    $low += 1;
                }
            }
        }

        $itemCount = count($this->extractDamageItems($evaluation));
        $score = ($critical * 6) + ($high * 4) + ($medium * 2) + ($low * 1);

        // Ajustes por contexto del ticket.
        $priority = $this->normalizeText((string)($row['prioridad'] ?? ''));
        if ($this->containsAny($priority, ['urgente', 'alta', 'critica', 'critico'])) {
            $score += 3;
        }
        if ($itemCount >= 6) {
            $score += 3;
        } elseif ($itemCount >= 3) {
            $score += 1;
        }

        $indicators = $this->buildIndicators($joined, $criticalKeywords, $highKeywords, $mediumKeywords, $lowKeywords);

        if ($critical > 0 || $score >= 18) {
            return $this->magnitudePayload('critico', $score, $indicators, $itemCount, $critical, $high, $medium);
        }
        if ($high > 0 || $score >= 11) {
            return $this->magnitudePayload('alto', $score, $indicators, $itemCount, $critical, $high, $medium);
        }
        if ($medium > 0 || $score >= 5) {
            return $this->magnitudePayload('medio', $score, $indicators, $itemCount, $critical, $high, $medium);
        }
        return $this->magnitudePayload('bajo', max(1, $score), $indicators, $itemCount, $critical, $high, $medium);
    }

    private function magnitudePayload(string $key, int $score, array $indicators, int $items, int $critical, int $high, int $medium): array
    {
        $map = [
            'critico' => [
                'label' => 'Crítico',
                'class' => 'danger',
                'color' => '#dc2626',
                'recommendation' => 'Atención inmediata. Validar riesgo para habitabilidad, seguridad o servicios esenciales antes de cerrar el ticket.',
            ],
            'alto' => [
                'label' => 'Alto',
                'class' => 'high',
                'color' => '#ea580c',
                'recommendation' => 'Priorizar visita/cotización. Puede afectar uso normal del inmueble o generar deterioro progresivo.',
            ],
            'medio' => [
                'label' => 'Medio',
                'class' => 'medium',
                'color' => '#ca8a04',
                'recommendation' => 'Programar seguimiento. Requiere reparación, pero no evidencia urgencia crítica con la información registrada.',
            ],
            'bajo' => [
                'label' => 'Bajo',
                'class' => 'low',
                'color' => '#16a34a',
                'recommendation' => 'Gestionar en flujo normal. Daño menor, ajuste o desgaste sin señal de riesgo alto.',
            ],
            'sin_datos' => [
                'label' => 'Sin datos',
                'class' => 'empty',
                'color' => '#64748b',
                'recommendation' => 'Revisar la evaluación de la revisión porque no se pudo leer información suficiente.',
            ],
        ];

        return [
            'key' => $key,
            'label' => $map[$key]['label'],
            'class' => $map[$key]['class'],
            'color' => $map[$key]['color'],
            'score' => $score,
            'items' => $items,
            'critical_hits' => $critical,
            'high_hits' => $high,
            'medium_hits' => $medium,
            'indicators' => array_values(array_slice(array_unique($indicators), 0, 8)),
            'recommendation' => $map[$key]['recommendation'],
        ];
    }

    private function buildMatrix(array $evaluation, array $magnitude): array
    {
        $items = $this->extractDamageItems($evaluation);
        $text = $this->normalizeText(json_encode($evaluation, JSON_UNESCAPED_UNICODE));

        $matrix = [
            [
                'factor' => 'Seguridad / habitabilidad',
                'nivel' => $this->factorLevel($text, ['riesgo electrico','corto circuito','fuga de gas','estructural','colapso','incendio','inhabitable','no habitable']),
                'criterio' => 'Riesgos que pueden afectar personas, servicios esenciales o uso seguro del inmueble.',
            ],
            [
                'factor' => 'Servicios públicos / instalaciones',
                'nivel' => $this->factorLevel($text, ['sin agua','sin energia','sin gas','fuga de agua','tuberia rota','alcantarillado','aguas negras','tomacorriente','enchufe']),
                'criterio' => 'Daños en redes eléctricas, hidráulicas, sanitarias o gas.',
            ],
            [
                'factor' => 'Humedad / filtraciones',
                'nivel' => $this->factorLevel($text, ['humedad severa','filtracion fuerte','inundacion','humedad','filtracion','goteo']),
                'criterio' => 'Presencia de humedad, filtraciones, goteras o deterioro asociado.',
            ],
            [
                'factor' => 'Cantidad de áreas afectadas',
                'nivel' => $this->areaLevel(count($items)),
                'criterio' => 'Número de daños o ítems detectados en la evaluación.',
            ],
            [
                'factor' => 'Magnitud general calculada',
                'nivel' => $magnitude['label'],
                'criterio' => 'Resultado ponderado por palabras clave, nivel explícito y cantidad de hallazgos.',
            ],
        ];

        return $matrix;
    }

    private function factorLevel(string $text, array $keywords): string
    {
        $hits = $this->countKeywordHits($text, $keywords);
        if ($hits >= 2) return 'Alto';
        if ($hits === 1) return 'Medio';
        return 'Bajo / No reportado';
    }

    private function areaLevel(int $count): string
    {
        if ($count >= 6) return 'Alto';
        if ($count >= 3) return 'Medio';
        if ($count >= 1) return 'Bajo';
        return 'Sin datos';
    }

    private function extractDamageItems(array $evaluation): array
    {
        $structuredItems = $this->extractStructuredDamageItems($evaluation);
        if (!empty($structuredItems)) {
            return array_slice($structuredItems, 0, 20);
        }

        $flat = $this->flattenEvaluation($evaluation);
        $items = [];

        foreach ($flat as $item) {
            $label = trim((string)($item['label'] ?? ''));
            $value = trim((string)($item['value'] ?? ''));
            if ($value === '' || $value === '0' || strtolower($value) === 'no') {
                continue;
            }

            $normalized = $this->normalizeText($label . ' ' . $value);
            if ($this->containsAny($normalized, ['dano','daño','danado','dañado','grave','medio','moderado','leve','humedad','filtracion','fuga','roto','fisura','grieta','deterioro','malo','regular','critico','alto','bajo'])) {
                $items[] = [
                    'label' => $label,
                    'value' => $this->cleanText($value),
                ];
            }
        }

        return array_slice($items, 0, 20);
    }

    private function extractStructuredDamageItems(array $evaluation): array
    {
        $items = [];
        foreach ($evaluation as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $fields = [
                'indice' => $this->cleanText((string)($value['indice'] ?? '')),
                'area_afectada_1' => $this->cleanText((string)($value['area_afectada_1'] ?? '')),
                'area_afectada_2' => $this->cleanText((string)($value['area_afectada_2'] ?? '')),
                'area_afectada_3' => $this->cleanText((string)($value['area_afectada_3'] ?? '')),
                'area_afectada_4' => $this->cleanText((string)($value['area_afectada_4'] ?? '')),
                'descripcion_dano' => $this->cleanText((string)($value['descripcion_dano'] ?? '')),
                'consecuencia' => $this->cleanText((string)($value['consecuencia'] ?? '')),
                'nivel_dano' => $this->cleanText((string)($value['nivel_dano'] ?? '')),
                'tiempo_atencion' => $this->cleanText((string)($value['tiempo_atencion'] ?? '')),
                'a_quien_corresponde' => $this->cleanText((string)($value['a_quien_corresponde'] ?? '')),
                'registro_foto_dano' => $this->cleanText((string)($value['registro_foto_dano'] ?? '')),
            ];

            $hasRelevantValue = false;
            foreach ($fields as $fieldValue) {
                if ($fieldValue !== '') {
                    $hasRelevantValue = true;
                    break;
                }
            }
            if (!$hasRelevantValue) {
                continue;
            }

            $areas = array_values(array_filter([
                $fields['area_afectada_1'],
                $fields['area_afectada_2'],
                $fields['area_afectada_3'],
                $fields['area_afectada_4'],
            ], static function ($area) {
                return trim((string)$area) !== '';
            }));

            $label = $fields['indice'] !== '' ? $fields['indice'] : (string)$key;
            $valueText = $fields['descripcion_dano'] !== ''
                ? $fields['descripcion_dano']
                : ($fields['consecuencia'] !== '' ? $fields['consecuencia'] : implode(', ', $areas));

            $items[] = [
                'label' => $label,
                'value' => $valueText,
                'fields' => $fields,
                'areas' => $areas,
            ];
        }

        return $items;
    }

    private function hydrateDamagePhotos(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $raw = (string)($item['fields']['registro_foto_dano'] ?? '');
            foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
                $id = (int)trim($part);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        if (empty($ids)) {
            return $items;
        }

        $photos = $this->loadAttachmentUrls(array_values($ids));
        foreach ($items as &$item) {
            $itemPhotos = [];
            $raw = (string)($item['fields']['registro_foto_dano'] ?? '');
            foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
                $id = (int)trim($part);
                if ($id > 0 && isset($photos[$id])) {
                    $itemPhotos[] = [
                        'id' => $id,
                        'url' => $photos[$id],
                    ];
                }
            }
            $item['photos'] = $itemPhotos;
        }
        unset($item);

        return $items;
    }

    private function loadAttachmentUrls(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $postsTable = $this->prefix . 'posts';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT ID, guid FROM {$postsTable} WHERE ID IN ({$placeholders})");
            foreach ($ids as $index => $id) {
                $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            $url = trim((string)($row['guid'] ?? ''));
            if ($id > 0 && $url !== '') {
                $out[$id] = $url;
            }
        }
        return $out;
    }

    private function flattenEvaluation($value, string $prefix = ''): array
    {
        $out = [];

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $label = $prefix === '' ? (string)$key : $prefix . ' > ' . (string)$key;
                $out = array_merge($out, $this->flattenEvaluation($child, $label));
            }
            return $out;
        }

        if (is_object($value)) {
            return $this->flattenEvaluation((array)$value, $prefix);
        }

        $scalar = is_bool($value) ? ($value ? 'Sí' : 'No') : (string)$value;
        $out[] = [
            'label' => $prefix,
            'value' => $scalar,
        ];
        return $out;
    }

    private function buildIndicators(string $text, array ...$keywordGroups): array
    {
        $found = [];
        foreach ($keywordGroups as $group) {
            foreach ($group as $keyword) {
                if ($this->textContains($text, $this->normalizeText($keyword))) {
                    $found[] = $keyword;
                }
            }
        }
        return $found;
    }

    private function countKeywordHits(string $text, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $keyword) {
            if ($this->textContains($text, $this->normalizeText($keyword))) {
                $count++;
            }
        }
        return $count;
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($this->textContains($text, $this->normalizeText($keyword))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compatible con PHP 7.x. Sustituye la funcion nativa de PHP 8 para busqueda de texto.
     */
    private function textContains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
        $text = preg_replace('/[^a-z0-9ñ\s\|>_\-]/u', ' ', $text) ?: $text;
        return preg_replace('/\s+/', ' ', trim($text)) ?: '';
    }

    private function cleanText(?string $text): string
    {
        $text = trim(strip_tags((string)$text));
        $text = preg_replace('/\s+/', ' ', $text) ?: '';
        return mb_substr($text, 0, 600, 'UTF-8');
    }

    private function formatUnixDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        $ts = (int)$value;
        if ($ts <= 0) {
            return '';
        }
        return date('d/m/Y', $ts);
    }
}
