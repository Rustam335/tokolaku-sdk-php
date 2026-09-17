<?php

declare(strict_types=1);

namespace Tokolaku\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Tokolaku\Exception\WebhookSignatureException;
use Tokolaku\Webhooks;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_rahasia_123';

    private static function body(): string
    {
        return json_encode(['event' => 'message.received', 'data' => ['text' => 'halo kak']], JSON_THROW_ON_ERROR);
    }

    private static function sign(string $secret, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function testVerifyValidSignatureReturnsTrue(): void
    {
        $body = self::body();
        $this->assertTrue(Webhooks::verifySignature($body, self::sign(self::SECRET, $body), self::SECRET));
    }

    public function testVerifyWrongSecretReturnsFalse(): void
    {
        $body = self::body();
        $this->assertFalse(Webhooks::verifySignature($body, self::sign('salah', $body), self::SECRET));
    }

    public function testVerifyBodyAlteredReturnsFalse(): void
    {
        $body = self::body();
        $this->assertFalse(Webhooks::verifySignature($body . 'x', self::sign(self::SECRET, $body), self::SECRET));
    }

    public function testVerifyMissingPrefixReturnsFalse(): void
    {
        $body = self::body();
        $sig = self::sign(self::SECRET, $body);
        $this->assertFalse(Webhooks::verifySignature($body, substr($sig, strlen('sha256=')), self::SECRET));
    }

    public function testVerifyNullHeaderReturnsFalse(): void
    {
        $this->assertFalse(Webhooks::verifySignature(self::body(), null, self::SECRET));
    }

    public function testVerifyWrongLengthHexReturnsFalseWithoutThrowing(): void
    {
        $this->assertFalse(Webhooks::verifySignature(self::body(), 'sha256=zzzz', self::SECRET));
    }

    public function testVerifyTrailingGarbageAfterValidHexReturnsFalse(): void
    {
        $body = self::body();
        $this->assertFalse(Webhooks::verifySignature($body, self::sign(self::SECRET, $body) . 'zz', self::SECRET));
    }

    public function testVerifyUppercaseHexIsAccepted(): void
    {
        $body = self::body();
        $sig = self::sign(self::SECRET, $body);
        $upper = 'sha256=' . strtoupper(substr($sig, strlen('sha256=')));
        $this->assertTrue(Webhooks::verifySignature($body, $upper, self::SECRET));
    }

    public function testConstructEventValidParsesEvent(): void
    {
        $body = self::body();
        $result = Webhooks::constructEvent($body, self::sign(self::SECRET, $body), self::SECRET);

        $this->assertSame('message.received', $result['event']['event']);
    }

    public function testConstructEventInvalidSignatureThrowsWebhookSignatureException(): void
    {
        $body = self::body();
        $this->expectException(WebhookSignatureException::class);
        Webhooks::constructEvent($body, 'sha256=' . str_repeat('d', 64), self::SECRET);
    }

    public function testConstructEventMissingSignatureThrowsWebhookSignatureException(): void
    {
        $this->expectException(WebhookSignatureException::class);
        Webhooks::constructEvent(self::body(), null, self::SECRET);
    }

    public function testConstructEventValidSignatureButNonJsonBodyThrowsJsonException(): void
    {
        $raw = 'bukan json';
        $this->expectException(JsonException::class);
        Webhooks::constructEvent($raw, self::sign(self::SECRET, $raw), self::SECRET);
    }
}
