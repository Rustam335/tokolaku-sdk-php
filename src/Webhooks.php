<?php

declare(strict_types=1);

namespace Tokolaku;

use JsonException;
use Tokolaku\Exception\WebhookSignatureException;

final class Webhooks
{
    /**
     * Verifikasi header `x-tokolaku-signature` (format `sha256=<hex>`,
     * HMAC-SHA256(secret, rawBody)) — compare timing-safe. $rawBody HARUS
     * string mentah persis seperti diterima (bukan hasil re-serialize).
     * Hex format strict: exactly 64 hex chars, case-insensitive. Never-throw.
     */
    public static function verifySignature(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }
        $hex = substr($signatureHeader, strlen('sha256='));
        if (!preg_match('/\A[0-9a-f]{64}\z/i', $hex)) {
            return false; // strict: exactly 64 hex chars, reject trailing garbage
        }
        $expected = hash_hmac('sha256', $rawBody, $secret, true);
        $given = hex2bin($hex);
        if ($given === false) {
            return false;
        }

        return hash_equals($expected, $given);
    }

    /**
     * Verify + parse. Signature invalid -> throw WebhookSignatureException.
     * Signature VALID tapi $rawBody bukan JSON valid -> JsonException dari
     * json_decode(..., JSON_THROW_ON_ERROR) (sengaja tidak dibungkus — itu
     * bug payload, bukan soal keamanan).
     *
     * @return array{event: mixed}
     *
     * @throws WebhookSignatureException
     * @throws JsonException
     */
    public static function constructEvent(string $rawBody, ?string $signatureHeader, string $secret): array
    {
        if (!self::verifySignature($rawBody, $signatureHeader, $secret)) {
            throw new WebhookSignatureException();
        }

        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        return ['event' => $event];
    }
}
