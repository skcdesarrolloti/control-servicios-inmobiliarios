<?php
/**
 * Servicio para leer tickets con revision correctiva y calcular magnitud de daños.
 * Autor: Royner Guardo
 *
 * Requiere una conexión PDO activa.
 */

final class DamageMagnitudeService
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
                'estado'     => trim((string)($_GET['estado'] ?? '')),
                'sucursal'   => trim((string)($_GET['sucursal'] ?? '')),
                'empleado'   => trim((string)($_GET['empleado'] ?? '')),
                'magnitud'   => trim((string)($_GET['magnitud'] ?? '')),
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
        $ticketsTable = $this->prefix . 'jet_cct_tickets';
        $revisionTable = $this->prefix . 'jet_cct_revision_correctiva';

        $where = [
            "t.id_revision_correctiva IS NOT NULL",
            "TRIM(t.id_revision_correctiva) <> ''",
            "rc._ID IS NOT NULL",
        ];
        $params = [];

        if (!empty($filters['estado'])) {
            $where[] = "t.estado = :estado";
            $params[':estado'] = $filters['estado'];
        }

        if (!empty($filters['sucursal'])) {
            $where[] = "(t.sucursal = :sucursal OR rc.sucursal = :sucursal)";
            $params[':sucursal'] = $filters['sucursal'];
        }

        if (!empty($filters['empleado'])) {
            $where[] = "(t.id_empleado = :empleado OR rc.id_empleado = :empleado OR t.nombre_empleado LIKE :empleadoLike OR t.empleado LIKE :empleadoLike)";
            $params[':empleado'] = $filters['empleado'];
            $params[':empleadoLike'] = '%' . $filters['empleado'] . '%';
        }

        if (!empty($filters['q'])) {
            $where[] = "(
                t.id_ticket LIKE :q OR
                t.asunto LIKE :q OR
                t.descripcion LIKE :q OR
                t.direccion LIKE :q OR
                t.inmueble LIKE :q OR
                t.solicitante LIKE :q OR
                rc.inmueble LIKE :q OR
                rc.direccion LIKE :q OR
                rc.contrato LIKE :q
            )";
            $params[':q'] = '%' . $filters['q'] . '%';
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

                rc._ID AS revision_id,
                rc.fecha AS revision_fecha,
                rc.evaluacion_de_danos,
                rc.tiene_cotizacion,
                rc.destinatario,
                rc.celular_destinatario,
                rc.email_destinatario,
                rc.contrato AS revision_contrato,
                rc.inmueble AS revision_inmueble,
                rc.direccion AS revision_direccion,
                rc.tip_inm,
                rc.creador,
                rc.id_empleado AS revision_id_empleado,
                rc.coordinador,
                rc.tipo_negocio,
                rc.destinacion,
                rc.sucursal AS revision_sucursal
            FROM {$ticketsTable} t
            LEFT JOIN {$revisionTable} rc
                ON CAST(t.id_revision_correctiva AS UNSIGNED) = rc._ID
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

            if (!empty($filters['magnitud']) && $filters['magnitud'] !== $magnitude['key']) {
                continue;
            }

            $summary[$magnitude['key']] = ($summary[$magnitude['key']] ?? 0) + 1;
            $summary['total']++;

            $tickets[] = [
                'ticket_row_id' => (int)$row['ticket_row_id'],
                'id_ticket' => $row['id_ticket'] ?: (string)$row['ticket_row_id'],
                'revision_id' => (int)$row['revision_id'],
                'id_revision_correctiva' => $row['id_revision_correctiva'],
                'asunto' => $row['asunto'] ?: 'Sin asunto',
                'descripcion' => $this->cleanText($row['descripcion'] ?? ''),
                'estado' => $row['estado'] ?: 'Sin estado',
                'prioridad' => $row['prioridad'] ?: '',
                'tema_ayuda' => $row['tema_ayuda'] ?: '',
                'departamento' => $row['departamento'] ?: '',
                'direccion' => $row['revision_direccion'] ?: ($row['ticket_direccion'] ?: ''),
                'inmueble' => $row['revision_inmueble'] ?: ($row['ticket_inmueble'] ?: ''),
                'contrato' => $row['revision_contrato'] ?: ($row['ticket_contrato'] ?: ''),
                'sucursal' => $row['revision_sucursal'] ?: ($row['ticket_sucursal'] ?: ''),
                'fecha' => $this->formatUnixDate($row['ticket_fecha'] ?: $row['revision_fecha'] ?: null),
                'fecha_actualizacion' => $this->formatUnixDate($row['fecha_actualizacion'] ?: null),
                'solicitante' => $row['solicitante'] ?: ($row['destinatario'] ?: ''),
                'celular' => $row['celular_solicitante'] ?: ($row['celular_destinatario'] ?: ''),
                'correo' => $row['correo_solicitante'] ?: ($row['email_destinatario'] ?: ''),
                'empleado' => $row['nombre_empleado'] ?: ($row['empleado'] ?: ($row['creador'] ?: '')),
                'coordinador' => $row['coordinador'] ?: '',
                'tiene_cotizacion' => $row['tiene_cotizacion'] ?: '',
                'tipo_negocio' => $row['tipo_negocio'] ?: '',
                'destinacion' => $row['destinacion'] ?: '',
                'magnitud' => $magnitude,
                'matriz' => $this->buildMatrix($evaluation, $magnitude),
                'danos_detectados' => $this->extractDamageItems($evaluation),
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

        // JetEngine/WordPress a veces guarda cadenas escapadas.
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = stripslashes($raw);

        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        $unserialized = @unserialize($raw, ['allowed_classes' => false]);
        if ($unserialized !== false || $raw === 'b:0;') {
            return is_array($unserialized) ? $unserialized : ['valor' => $unserialized];
        }

        // Fallback: intenta convertir pares simples tipo clave: valor separados por saltos o comas.
        return ['texto' => $raw];
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
                'recommendation' => 'Revisar la evaluación correctiva porque no se pudo leer información suficiente.',
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
