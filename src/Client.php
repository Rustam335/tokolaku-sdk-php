<?php

declare(strict_types=1);

namespace Tokolaku;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use Tokolaku\Exception\ApiException;
use Tokolaku\Exception\ValidationException;

final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.tokolaku.id';
    private const DEFAULT_TIMEOUT = 30.0;
    private const DEFAULT_MAX_RETRIES = 2;

    /** cURL errno untuk operation timed out (CURLE_OPERATION_TIMEDOUT). */
    private const CURLE_OPERATION_TIMEDOUT = 28;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly ClientInterface $httpClient;

    /**
     * @param string|array{api_key?: string, base_url?: string, timeout?: float|int, max_retries?: int, http_client?: ClientInterface} $apiKeyOrOptions
     */
    public function __construct(string|array $apiKeyOrOptions)
    {
        $options = is_string($apiKeyOrOptions) ? ['api_key' => $apiKeyOrOptions] : $apiKeyOrOptions;

        $apiKey = $options['api_key'] ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            throw new ValidationException('apiKey wajib diisi', null, 'missing_api_key');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;
        $this->maxRetries = isset($options['max_retries']) ? (int) $options['max_retries'] : self::DEFAULT_MAX_RETRIES;
        $this->httpClient = $options['http_client'] ?? new GuzzleClient(['timeout' => $this->timeout]);
    }

    /**
     * Balasan AI bot tenant untuk satu pesan pelanggan (POST /api/v1/bot/reply).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function botReply(array $params): array
    {
        return $this->request('/api/v1/bot/reply', $params, 'botReply');
    }

    /**
     * Kirim pesan text/template via channel resmi (POST /api/v1/messages).
     * `type` diinferensi: field `text` -> "text", field `template` -> "template".
     * Idiom PHP: method datar `sendMessage` (bukan `messages->send` seperti TS).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function sendMessage(array $params): array
    {
        $hasText = array_key_exists('text', $params) && $params['text'] !== null;
        $hasTemplate = array_key_exists('template', $params) && $params['template'] !== null;
        if ($hasText === $hasTemplate) {
            throw new ValidationException(
                'Isi tepat satu: `text` (pesan sesi) ATAU `template` (business-initiated)',
                null,
                'invalid_params',
            );
        }

        $body = $params;
        $body['type'] = $hasText ? 'text' : 'template';

        return $this->request('/api/v1/messages', $body, 'messages');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $body, string $policy): array
    {
        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $retryAfterSec = null;
            try {
                return $this->once($path, $body, $retryAfterSec);
            } catch (ApiException $e) {
                $lastError = $e;
                if ($attempt >= $this->maxRetries || !Retry::shouldRetry($policy, $e)) {
                    throw $e;
                }
                usleep(Retry::retryDelayMs($attempt, $retryAfterSec) * 1000);
            }
        }

        // Tidak pernah tercapai (loop di atas selalu return atau throw), tapi
        // dibutuhkan agar analisis tipe statis puas & sebagai jaring pengaman.
        throw $lastError ?? new ApiException('Request gagal tanpa detail', null, 'network_error');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function once(string $path, array $body, ?int &$retryAfterSec): array
    {
        $url = $this->baseUrl . $path;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
                'timeout' => $this->timeout,
            ]);
        } catch (ConnectException $e) {
            if (self::isCurlTimeout($e)) {
                throw new ApiException("Timeout setelah {$this->timeout}s", null, 'timeout');
            }
            throw new ApiException('Network error: ' . $e->getMessage(), null, 'network_error');
        } catch (GuzzleException $e) {
            // Kelas GuzzleException lain pra-respons (mis. RequestException tanpa
            // respons, TooManyRedirectsException) — perlakukan sama seperti
            // ConnectException: kegagalan sebelum ada efek samping server.
            throw new ApiException('Network error: ' . $e->getMessage(), null, 'network_error');
        }

        $status = $response->getStatusCode();

        try {
            $text = $response->getBody()->getContents();
        } catch (Throwable $e) {
            // Header respons SUDAH diterima (request sampai server, efek samping —
            // mis. pesan terkirim & tercharge, reply AI dihasilkan — mungkin sudah
            // terjadi) tapi koneksi putus saat membaca body. BUKAN network error &
            // TIDAK boleh di-retry (lihat Retry::shouldRetry: sejajar dengan invalid_response).
            throw new ApiException('Gagal membaca body respons', $status, 'response_read_error');
        }

        if ($status < 200 || $status >= 300) {
            $retryAfterHeader = $response->getHeaderLine('Retry-After');
            $retryAfterSec = ($retryAfterHeader !== '' && ctype_digit($retryAfterHeader))
                ? (int) $retryAfterHeader
                : null;
            throw ApiException::fromResponse($status, $text);
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            // Respons HTTP sudah diterima (efek samping server, mis. pesan terkirim &
            // tercharge, SUDAH terjadi) — parse gagal BUKAN network error & TIDAK boleh di-retry.
            throw new ApiException('Respons server bukan JSON valid', $status, 'invalid_response');
        }

        return $decoded;
    }

    private static function isCurlTimeout(ConnectException $e): bool
    {
        $context = $e->getHandlerContext();

        return isset($context['errno']) && (int) $context['errno'] === self::CURLE_OPERATION_TIMEDOUT;
    }
}
