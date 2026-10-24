<?php
declare(strict_types=1);

namespace SCM\Modules\Tools;

use RuntimeException;

/**
 * Cliente cURL de la calculadora de mora (copia de herramientas/cal-mora/lib/HttpClient.php).
 * No repite la petición sin verificar el certificado TLS: el original lo hacía para XAMPP sin CA.
 */
final class SuperfinHttpClient
{
    private string $userAgent;
    private int $timeoutSeconds;

    public function __construct(
        string $userAgent = 'SKCSuCasaCalMora/1.0',
        int $timeoutSeconds = 25
    ) {
        $this->userAgent = $userAgent;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function get(string $url): string
    {
        return $this->request($url);
    }

    public function getBinary(string $url): string
    {
        return $this->request($url);
    }

    private function request(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP no tiene disponible la extension curl.');
        }
        $result = $this->requestOnce($url, true);

        if ($result['errno'] !== 0) {
            throw new RuntimeException('Error de red: ' . $result['error']);
        }

        if ($result['status'] >= 400 || $result['status'] === 0) {
            throw new RuntimeException('Respuesta HTTP no valida: ' . $result['status'] . ' para ' . $url);
        }

        if (!is_string($result['body']) || $result['body'] === '') {
            throw new RuntimeException('Respuesta vacia para ' . $url);
        }

        return $result['body'];
    }

    /**
     * @return array{body:string|false, errno:int, error:string, status:int}
     */
    private function requestOnce(string $url, bool $verifyTls): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-CO,es;q=0.9,en;q=0.7',
                'Cache-Control: no-cache',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'body' => $body,
            'errno' => $errno,
            'error' => $error,
            'status' => $status,
        ];
    }
}
