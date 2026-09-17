<?php

declare(strict_types=1);

namespace Tokolaku\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tokolaku\Exception\ApiException;
use Tokolaku\Exception\AuthenticationException;
use Tokolaku\Exception\InsufficientBalanceException;
use Tokolaku\Exception\PermissionException;
use Tokolaku\Exception\RateLimitException;
use Tokolaku\Exception\ValidationException;

final class ApiExceptionTest extends TestCase
{
    private static function envelope(string $code, string $message): string
    {
        return json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_THROW_ON_ERROR);
    }

    public function testMapsStatus401ToAuthenticationExceptionWithEnvelopeCode(): void
    {
        $e = ApiException::fromResponse(401, self::envelope('invalid_key', 'API key tidak valid'));

        $this->assertInstanceOf(AuthenticationException::class, $e);
        $this->assertInstanceOf(ApiException::class, $e);
        $this->assertSame(401, $e->getStatus());
        $this->assertSame('invalid_key', $e->getErrorCode());
        $this->assertSame('API key tidak valid', $e->getMessage());
    }

    public function testMapsFullStatusCategoryTable(): void
    {
        $this->assertInstanceOf(ValidationException::class, ApiException::fromResponse(400, self::envelope('invalid_body', 'x')));
        $this->assertInstanceOf(ValidationException::class, ApiException::fromResponse(422, self::envelope('ai_not_configured', 'x')));
        $this->assertInstanceOf(InsufficientBalanceException::class, ApiException::fromResponse(402, self::envelope('insufficient_balance', 'x')));
        $this->assertInstanceOf(PermissionException::class, ApiException::fromResponse(403, self::envelope('invalid_scope', 'x')));
        $this->assertInstanceOf(RateLimitException::class, ApiException::fromResponse(429, self::envelope('quota_exceeded', 'x')));
    }

    public function testStatus500And404MapToBaseApiExceptionNotSubclass(): void
    {
        $e = ApiException::fromResponse(500, self::envelope('send_failed', 'x'));
        $this->assertSame(ApiException::class, get_class($e));

        $e404 = ApiException::fromResponse(404, '');
        $this->assertSame(404, $e404->getStatus());
        $this->assertSame(ApiException::class, get_class($e404));
    }

    public function testNonJsonBodyYieldsNullCodeAndMessageTruncatedTo500Chars(): void
    {
        $e = ApiException::fromResponse(502, 'Bad Gateway ' . str_repeat('y', 600));

        $this->assertNull($e->getErrorCode());
        $this->assertLessThanOrEqual(500, mb_strlen($e->getMessage()));
    }

    public function testEmptyBodyYieldsHttpStatusMessage(): void
    {
        $e = ApiException::fromResponse(503, '');

        $this->assertSame('HTTP 503', $e->getMessage());
        $this->assertNull($e->getErrorCode());
    }
}
