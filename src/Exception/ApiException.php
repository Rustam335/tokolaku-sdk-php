<?php

declare(strict_types=1);

namespace Tokolaku\Exception;

use Exception;

/**
 * Base error semua kegagalan API. `status` null = kegagalan sebelum ada
 * respons HTTP (network/timeout). `code` = kode envelope BE, mis.
 * "insufficient_balance"; null bila body bukan JSON envelope.
 */
class ApiException extends Exception
{
    private ?int $status;
    private ?string $errorCode;

    public function __construct(string $message, ?int $status, ?string $errorCode)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Terjemahkan respons non-2xx jadi error class. Envelope BE:
     * `{ error: { code, message } }`. Body non-JSON dipotong 500 char.
     */
    public static function fromResponse(int $status, string $bodyText): self
    {
        $code = null;
        $message = $bodyText !== '' ? mb_substr($bodyText, 0, 500) : "HTTP {$status}";

        $parsed = json_decode($bodyText, true);
        if (is_array($parsed) && isset($parsed['error']) && is_array($parsed['error'])) {
            $error = $parsed['error'];
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : $message;
        }

        $class = self::classForStatus($status);

        return new $class($message, $status, $code);
    }

    /**
     * @return class-string<self>
     */
    private static function classForStatus(int $status): string
    {
        return match ($status) {
            400, 422 => ValidationException::class,
            401 => AuthenticationException::class,
            402 => InsufficientBalanceException::class,
            403 => PermissionException::class,
            429 => RateLimitException::class,
            default => self::class,
        };
    }
}
