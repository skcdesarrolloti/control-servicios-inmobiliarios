<?php

declare(strict_types=1);

namespace SCM\Modules\Tools;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Tasa vigente de interés bancario corriente (consumo y ordinario) y resolución de la Superfinanciera.
 * Fuente principal: datos abiertos de la SFC en datos.gov.co (pare-7x5i, JSON estable). La página de la
 * SFC (PowerBI, publicación mensual y comunicado .docx, como en herramientas/cal-mora) se consulta solo
 * para los enlaces al acto y al comunicado, y como respaldo completo si la API no responde.
 */
final class SuperfinRateService
{
  private const BASE_URL = 'https://www.superfinanciera.gov.co';
  private const POWERBI_URL = 'https://www.superfinanciera.gov.co/powerbi/reportes/513/';
  private const DEFAULT_PUBLICATION = 'https://www.superfinanciera.gov.co/publicacion/10829';
  private const OPEN_DATA_URL = 'https://www.datos.gov.co/resource/pare-7x5i.json';
  private const OPEN_DATA_MODALITY = 'CONSUMO Y ORDINARIO';

  private SuperfinHttpClient $http;
  private string $cacheFile;
  private int $cacheTtlSeconds;

  public function __construct(SuperfinHttpClient $http, string $cacheFile, int $cacheTtlSeconds = 21600)
  {
    $this->http = $http;
    $this->cacheFile = $cacheFile;
    $this->cacheTtlSeconds = $cacheTtlSeconds;
  }

  public static function default(): self
  {
    return new self(new SuperfinHttpClient(), rtrim((string) SCM_STORAGE_PATH, '/\\') . '/data/superfin_cache.json');
  }

  /**
   * @return array<string, mixed>
   */
  public function getLatest(bool $forceRefresh = false): array
  {
    $cached = null;
    if (!$forceRefresh) {
      $cached = $this->readCache(false);
      if ($cached !== null) {
        $cached['from_cache'] = true;
        return $cached;
      }
    } else {
      $cached = $this->readCache(true);
    }

    try {
      $fresh = $this->fetchLatest();
      $this->writeCache($fresh);
      $fresh['from_cache'] = false;
      return $fresh;
    } catch (Throwable $e) {
      if ($cached === null) {
        $cached = $this->readCache(true);
      }
      if ($cached !== null) {
        $cached['from_cache'] = true;
        $warnings = $cached['warnings'] ?? [];
        if (!is_array($warnings)) {
          $warnings = [];
        }
        $warnings[] = 'Actualización falló: ' . $e->getMessage();
        $cached['warnings'] = array_values(array_unique(array_filter($warnings)));
        return $cached;
      }
      throw $e;
    }
  }

  /**
   * @return array<string, mixed>
   */
  private function fetchLatest(): array
  {
    try {
      $latest = $this->fetchFromOpenData();
    } catch (Throwable $e) {
      $fallback = $this->fetchFromSfc();
      $fallback['warnings'][] = 'datos.gov.co no respondió (' . $e->getMessage() . '); se usó la página de la Superfinanciera.';
      return $fallback;
    }
    try {
      $sfc = $this->fetchFromSfc();
      // El acto solo se enlaza si es la misma resolución; la publicación y el comunicado son los del mes vigente.
      $sameResolution = (string) ($sfc['resolution_number'] ?? '') === (string) $latest['resolution_number'];
      foreach (['publication_url', 'bulletin_url', 'resolution_url'] as $key) {
        if (!empty($sfc[$key]) && ($key !== 'resolution_url' || $sameResolution)) $latest[$key] = $sfc[$key];
      }
    } catch (Throwable $e) {
      // Los enlaces son opcionales; la tasa y la resolución ya vienen de datos abiertos.
    }
    return $latest;
  }

  /**
   * @return array<string, mixed>
   */
  private function fetchFromOpenData(): array
  {
    $url = self::OPEN_DATA_URL . '?' . http_build_query([
      '$where' => "modalidad = '" . self::OPEN_DATA_MODALITY . "'",
      '$order' => 'vigencia_desde DESC',
      '$limit' => 3,
    ]);
    $rows = json_decode($this->http->get($url), true);
    if (!is_array($rows)) {
      throw new RuntimeException('respuesta no valida');
    }
    $tz = new DateTimeZone('America/Bogota');
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $best = null;
    foreach ($rows as $row) {
      if (!is_array($row) || strtoupper(trim((string) ($row['modalidad'] ?? ''))) !== self::OPEN_DATA_MODALITY) continue;
      $from = substr((string) ($row['vigencia_desde'] ?? ''), 0, 10);
      $rate = (float) str_replace([',', '%'], ['.', ''], (string) ($row['interes_bancario_corriente'] ?? ''));
      if ($from === '' || $from > $today || $rate <= 0) continue;
      if ($best === null || $from > $best['from']) {
        $best = ['from' => $from, 'to' => substr((string) ($row['vigencia_hasta'] ?? ''), 0, 10), 'rate' => $rate, 'number' => trim((string) ($row['resolucion'] ?? '')), 'year' => (int) substr((string) ($row['fecha_resolucion'] ?? $from), 0, 4)];
      }
    }
    if ($best === null) {
      throw new RuntimeException('sin tasa vigente de consumo y ordinario');
    }
    $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fromDate = new DateTimeImmutable($best['from'], $tz);
    $toDate = $best['to'] !== '' ? new DateTimeImmutable($best['to'], $tz) : $fromDate->modify('last day of this month');
    $vigencia = $fromDate->format('Y-m') === $toDate->format('Y-m')
      ? sprintf('%d y el %d de %s de %d', (int) $fromDate->format('j'), (int) $toDate->format('j'), $months[(int) $toDate->format('n')], (int) $toDate->format('Y'))
      : $fromDate->format('d/m/Y') . ' - ' . $toDate->format('d/m/Y');
    $number = $best['number'] !== '' ? $best['number'] : null;
    $year = $best['year'] > 0 ? $best['year'] : null;

    return [
      'source' => 'Superintendencia Financiera de Colombia',
      'source_detail' => 'datos.gov.co · Tasa de interés bancario corriente (consumo y ordinario)',
      'fetched_at' => (new DateTimeImmutable('now', $tz))->format(DateTimeInterface::ATOM),
      'powerbi_url' => self::POWERBI_URL,
      'publication_url' => self::DEFAULT_PUBLICATION,
      'bulletin_url' => null,
      'resolution_url' => null,
      'resolution_number' => $number,
      'resolution_year' => $year,
      'resolution_label' => $number !== null && $year !== null ? sprintf('Resolucion %s de %s', $number, $year) : null,
      'vigencia_text' => $vigencia,
      'vigencia_desde' => $fromDate->format('Y-m-d'),
      'vigencia_hasta' => $toDate->format('Y-m-d'),
      'annual_effective_rate_percent' => round($best['rate'], 4),
      'annual_effective_rate_decimal' => $best['rate'] / 100.0,
      'mora_annual_rate_percent' => round($best['rate'] * 1.5, 4),
      'mora_monthly_rate_percent' => round($best['rate'] * 1.5 / 12.0, 6),
      'warnings' => [],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function fetchFromSfc(): array
  {
    $warnings = [];
    $powerbiHtml = $this->http->get(self::POWERBI_URL);
    $powerbiData = $this->parsePowerbiCard($powerbiHtml);
    $warnings = array_merge($warnings, $powerbiData['warnings']);

    $publicationUrl = self::DEFAULT_PUBLICATION;
    if ($powerbiData['publication_href'] !== null) {
      $publicationUrl = $this->absoluteUrl($powerbiData['publication_href']);
    }

    $publicationHtml = $this->http->get($publicationUrl);
    $bulletinData = $this->parseBulletinLink($publicationHtml);
    $warnings = array_merge($warnings, $bulletinData['warnings']);

    $bulletinUrl = null;
    $docData = [
      'resolution_number' => null,
      'resolution_year' => null,
      'resolution_url' => null,
      'annual_effective_rate_percent' => null,
      'vigencia_text' => null,
      'warnings' => [],
    ];

    if ($bulletinData['bulletin_href'] !== null) {
      $bulletinUrl = $this->absoluteUrl($bulletinData['bulletin_href']);
      try {
        $docData = $this->parseBulletinDocx($this->http->getBinary($bulletinUrl));
      } catch (Throwable $e) {
        $docData['warnings'][] = 'No se pudo leer el comunicado mensual: ' . $e->getMessage();
      }
    }

    $warnings = array_merge($warnings, $docData['warnings']);

    $ratePercent = $docData['annual_effective_rate_percent'] ?? $powerbiData['annual_effective_rate_percent'];
    if (!is_float($ratePercent) && !is_int($ratePercent)) {
      throw new RuntimeException('No fue posible extraer la tasa efectiva anual vigente.');
    }
    $ratePercent = (float) $ratePercent;

    $vigenciaText = $docData['vigencia_text'] ?? $powerbiData['vigencia_text'] ?? $bulletinData['vigencia_text'];
    $vigenciaRange = $this->parseVigencia($vigenciaText);

    $annualEffectiveDecimal = $ratePercent / 100.0;
    $moraAnnualPercent = $ratePercent * 1.5;
    $moraMonthlyPercent = $moraAnnualPercent / 12.0;

    $resolutionLabel = null;
    if ($docData['resolution_number'] !== null && $docData['resolution_year'] !== null) {
      $resolutionLabel = sprintf('Resolucion %s de %s', $docData['resolution_number'], $docData['resolution_year']);
    }

    return [
      'source' => 'Superintendencia Financiera de Colombia',
      'fetched_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format(DateTimeInterface::ATOM),
      'powerbi_url' => self::POWERBI_URL,
      'publication_url' => $publicationUrl,
      'bulletin_url' => $bulletinUrl,
      'resolution_url' => $docData['resolution_url'],
      'resolution_number' => $docData['resolution_number'],
      'resolution_year' => $docData['resolution_year'],
      'resolution_label' => $resolutionLabel,
      'vigencia_text' => $vigenciaText,
      'vigencia_desde' => $vigenciaRange['from'],
      'vigencia_hasta' => $vigenciaRange['to'],
      'annual_effective_rate_percent' => round($ratePercent, 4),
      'annual_effective_rate_decimal' => $annualEffectiveDecimal,
      'mora_annual_rate_percent' => round($moraAnnualPercent, 4),
      'mora_monthly_rate_percent' => round($moraMonthlyPercent, 6),
      'warnings' => array_values(array_unique(array_filter($warnings))),
    ];
  }

  /**
   * @return array{annual_effective_rate_percent:?float, publication_href:?string, vigencia_text:?string, warnings:array<int, string>}
   */
  private function parsePowerbiCard(string $html): array
  {
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $warnings = [];

    $match = [];
    $ok = preg_match(
      '~<span class="title-cont">\s*Interés Bancario Cte\.\s*Consumo y ordinario\s*</span>.*?'
        . '<a[^>]+href="([^"]+)"[^>]*>\s*([^<]+)\s*</a>.*?'
        . '<p class="h2-value">\s*([0-9]{1,2},[0-9]{2})%~siu',
      $decoded,
      $match
    ) === 1;

    if (!$ok) {
      $warnings[] = 'No se pudo leer el bloque de tasa en PowerBI.';
      return [
        'annual_effective_rate_percent' => null,
        'publication_href' => null,
        'vigencia_text' => null,
        'warnings' => $warnings,
      ];
    }

    return [
      'annual_effective_rate_percent' => $this->toPercent($match[3]),
      'publication_href' => trim($match[1]),
      'vigencia_text' => trim($match[2]),
      'warnings' => $warnings,
    ];
  }

  /**
   * @return array{bulletin_href:?string, vigencia_text:?string, warnings:array<int, string>}
   */
  private function parseBulletinLink(string $html): array
  {
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $warnings = [];

    $match = [];
    $ok = preg_match(
      '~<li>\s*([^<]+?)\s*para\s*<a\s+href="([^"]*idFile=\d+)"[^>]*>\s*Crédito de Consumo y Ordinario~iu',
      $decoded,
      $match
    ) === 1;

    if (!$ok) {
      $warnings[] = 'No se encontro el comunicado mensual de consumo y ordinario en la publicacion.';
      return [
        'bulletin_href' => null,
        'vigencia_text' => null,
        'warnings' => $warnings,
      ];
    }

    return [
      'bulletin_href' => trim($match[2]),
      'vigencia_text' => trim($match[1]),
      'warnings' => $warnings,
    ];
  }

  /**
   * @return array{resolution_number:?string, resolution_year:?int, resolution_url:?string, annual_effective_rate_percent:?float, vigencia_text:?string, warnings:array<int, string>}
   */
  private function parseBulletinDocx(string $binary): array
  {
    $warnings = [];
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('No se pudo leer el comunicado porque ZipArchive no esta disponible.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cm_docx_');
    if ($tmp === false) {
      throw new RuntimeException('No se pudo crear archivo temporal para leer el comunicado.');
    }

    file_put_contents($tmp, $binary);

    $zip = new ZipArchive();
    $zipOpened = $zip->open($tmp);
    if ($zipOpened !== true) {
      @unlink($tmp);
      throw new RuntimeException('El archivo mensual no pudo abrirse como DOCX.');
    }

    $documentXml = $zip->getFromName('word/document.xml');
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    $zip->close();
    @unlink($tmp);

    if (!is_string($documentXml) || $documentXml === '') {
      throw new RuntimeException('El comunicado no contiene word/document.xml.');
    }

    $text = $this->extractDocxText($documentXml);

    $resolutionNumber = null;
    $resolutionYear = null;
    if (preg_match('/Resoluci[oó]n\s*(\d{3,6})\s*de\s*(20\d{2})/iu', $text, $mRes) === 1) {
      $resolutionNumber = $mRes[1];
      $resolutionYear = (int) $mRes[2];
    } else {
      $warnings[] = 'No se pudo extraer el numero de resolucion desde el comunicado.';
    }

    $ratePercent = null;
    if (preg_match(
      '/Cr[eé]dito\s+de\s+consumo\s+y\s+ordinario\s+Se\s+certifica\s+en\s*([0-9]{1,2},[0-9]{2})%/iu',
      $text,
      $mRate
    ) === 1) {
      $ratePercent = $this->toPercent($mRate[1]);
    } else {
      $warnings[] = 'No se pudo extraer la tasa de consumo y ordinario en el comunicado.';
    }

    $vigenciaText = null;
    if (preg_match(
      '/vigencia\s+entre\s+el\s+([0-9]{1,2}\s+y\s+el\s+[0-9]{1,2}\s+de\s+[a-záéíóúñ]+\s+de\s+20\d{2})/iu',
      $text,
      $mVig
    ) === 1) {
      $vigenciaText = trim($mVig[1]);
    }

    $resolutionUrl = null;
    if (is_string($relsXml) && $relsXml !== '') {
      $resolutionUrl = $this->extractResolutionLinkFromRels($relsXml);
    }

    return [
      'resolution_number' => $resolutionNumber,
      'resolution_year' => $resolutionYear,
      'resolution_url' => $resolutionUrl,
      'annual_effective_rate_percent' => $ratePercent,
      'vigencia_text' => $vigenciaText,
      'warnings' => $warnings,
    ];
  }

  private function extractDocxText(string $documentXml): string
  {
    $dom = new DOMDocument();
    if (!$dom->loadXML($documentXml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
      throw new RuntimeException('No se pudo procesar el XML del comunicado.');
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $nodes = $xpath->query('//w:body//*');
    if ($nodes === false) {
      throw new RuntimeException('No se pudo leer el contenido textual del comunicado.');
    }

    $text = '';
    foreach ($nodes as $node) {
      if ($node->namespaceURI !== 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
        continue;
      }
      if ($node->localName === 't') {
        $text .= $node->textContent;
      } elseif ($node->localName === 'p') {
        $text .= "\n";
      }
    }

    $text = preg_replace('/[ \t\x0B\f\r]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n+/u', "\n", $text) ?? $text;
    return trim($text);
  }

  private function extractResolutionLinkFromRels(string $relsXml): ?string
  {
    $dom = new DOMDocument();
    if (!$dom->loadXML($relsXml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
      return null;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $nodes = $xpath->query(
      '//r:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"]'
    );
    if ($nodes === false) {
      return null;
    }

    foreach ($nodes as $node) {
      $href = '';
      if ($node->attributes !== null) {
        $attr = $node->attributes->getNamedItem('Target');
        if ($attr !== null) {
          $href = (string) $attr->nodeValue;
        }
      }
      $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($href !== '' && str_contains($href, 'idFile=')) {
        return $href;
      }
    }

    return null;
  }

  /**
   * @return array{from:?string, to:?string}
   */
  private function parseVigencia(?string $text): array
  {
    if ($text === null) {
      return ['from' => null, 'to' => null];
    }

    $clean = trim($text);
    if ($clean === '') {
      return ['from' => null, 'to' => null];
    }

    if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s*-\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/', $clean, $m) === 1) {
      return [
        'from' => $this->isoDate((int) $m[3], (int) $m[2], (int) $m[1]),
        'to' => $this->isoDate((int) $m[6], (int) $m[5], (int) $m[4]),
      ];
    }

    if (preg_match(
      '/(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\s+(\d{1,2})\s+al\s+(\d{1,2})\s+de\s+(\d{4})/iu',
      $clean,
      $m
    ) === 1) {
      $month = $this->monthNumber($m[1]);
      return [
        'from' => $this->isoDate((int) $m[4], $month, (int) $m[2]),
        'to' => $this->isoDate((int) $m[4], $month, (int) $m[3]),
      ];
    }

    if (preg_match(
      '/(\d{1,2})\s+y\s+el\s+(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\s+de\s+(\d{4})/iu',
      $clean,
      $m
    ) === 1) {
      $month = $this->monthNumber($m[3]);
      return [
        'from' => $this->isoDate((int) $m[4], $month, (int) $m[1]),
        'to' => $this->isoDate((int) $m[4], $month, (int) $m[2]),
      ];
    }

    return ['from' => null, 'to' => null];
  }

  private function monthNumber(string $monthName): int
  {
    $map = [
      'enero' => 1,
      'febrero' => 2,
      'marzo' => 3,
      'abril' => 4,
      'mayo' => 5,
      'junio' => 6,
      'julio' => 7,
      'agosto' => 8,
      'septiembre' => 9,
      'setiembre' => 9,
      'octubre' => 10,
      'noviembre' => 11,
      'diciembre' => 12,
    ];

    $normalized = strtolower(trim($monthName));
    return $map[$normalized] ?? 1;
  }

  private function isoDate(int $year, int $month, int $day): ?string
  {
    $dt = DateTimeImmutable::createFromFormat(
      '!Y-n-j',
      sprintf('%04d-%d-%d', $year, $month, $day),
      new DateTimeZone('America/Bogota')
    );
    return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d') : null;
  }

  private function toPercent(string $text): float
  {
    $clean = trim($text);
    $clean = str_replace('%', '', $clean);
    if (str_contains($clean, ',')) {
      $clean = str_replace('.', '', $clean);
      $clean = str_replace(',', '.', $clean);
    } else {
      $clean = str_replace(',', '', $clean);
    }
    return (float) $clean;
  }

  private function absoluteUrl(string $href): string
  {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') {
      return self::BASE_URL;
    }
    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
      return $href;
    }
    if (!str_starts_with($href, '/')) {
      $href = '/' . $href;
    }
    return self::BASE_URL . $href;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function readCache(bool $allowStale): ?array
  {
    if (!is_file($this->cacheFile)) {
      return null;
    }

    $mtime = filemtime($this->cacheFile);
    if ($mtime === false) {
      return null;
    }

    if (!$allowStale && (time() - $mtime) > $this->cacheTtlSeconds) {
      return null;
    }

    $json = file_get_contents($this->cacheFile);
    if (!is_string($json) || $json === '') {
      return null;
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function writeCache(array $payload): void
  {
    $dir = dirname($this->cacheFile);
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
      }
    }
    if (!is_writable($dir)) {
      return;
    }
    $encoded = json_encode(
      $payload,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($encoded === false) {
      return;
    }
    @file_put_contents($this->cacheFile, $encoded);
  }
}
