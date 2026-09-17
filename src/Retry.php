<?php

declare(strict_types=1);

namespace Tokolaku;

use Tokolaku\Exception\ApiException;

/**
 * Kebijakan retry uang-sadar:
 * - "botReply": 429, 5xx, network error (tanpa efek samping bila gagal).
 * - "messages": HANYA 429 + network error (pesan mungkin sudah terkirim
 *   & tercharge pada timeout/5xx — API belum punya idempotency key).
 * - timeout (code "timeout") TIDAK pernah di-retry.
 */
final class Retry
{
    /**
     * Cap Retry-After: server (atau proxy nakal) yang mengirim nilai raksasa
     * (mis. 86400) tidak boleh membuat klien tidur berjam-jam.
     */
    public const RETRY_AFTER_CAP_SEC = 30;

    public static function shouldRetry(string $policy, ApiException $e): bool
    {
        if ($e->getErrorCode() === 'timeout') {
            return false;
        }
        if ($e->getStatus() === 429) {
            return true;
        }
        // "invalid_response" (200 OK tapi body JSON rusak) dan "response_read_error"
        // (header respons sudah diterima tapi baca body gagal) SENGAJA tidak match
        // rule apa pun di bawah ini — efek samping server sudah terjadi, jadi
        // non-retryable untuk kedua policy.
        if ($e->getErrorCode() === 'network_error') {
            return true;
        }
        if ($policy === 'botReply' && $e->getStatus() !== null && $e->getStatus() >= 500) {
            return true;
        }

        return false;
    }

    /** Exponential backoff + full jitter, base 250ms cap 1s; Retry-After menang (di-cap RETRY_AFTER_CAP_SEC). */
    public static function retryDelayMs(int $attempt, ?int $retryAfterSec): int
    {
        if ($retryAfterSec !== null) {
            return max(0, min($retryAfterSec, self::RETRY_AFTER_CAP_SEC) * 1000);
        }
        $cap = min(1000, 250 * 2 ** $attempt);

        return (int) round($cap * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5));
    }
}
