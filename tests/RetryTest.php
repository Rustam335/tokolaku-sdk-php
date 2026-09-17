<?php

declare(strict_types=1);

namespace Tokolaku\Tests;

use PHPUnit\Framework\TestCase;
use Tokolaku\Exception\ApiException;
use Tokolaku\Retry;

final class RetryTest extends TestCase
{
    // --- botReply --------------------------------------------------------

    public function testBotReplyRetries429(): void
    {
        $e = new ApiException('rate limited', 429, 'rate_limited');
        $this->assertTrue(Retry::shouldRetry('botReply', $e));
    }

    public function testBotReplyRetries5xx(): void
    {
        $e = new ApiException('unavailable', 503, 'unavailable');
        $this->assertTrue(Retry::shouldRetry('botReply', $e));
    }

    public function testBotReplyRetriesNetworkError(): void
    {
        $e = new ApiException('network error', null, 'network_error');
        $this->assertTrue(Retry::shouldRetry('botReply', $e));
    }

    public function testBotReplyDoesNotRetryTimeout(): void
    {
        $e = new ApiException('timeout', null, 'timeout');
        $this->assertFalse(Retry::shouldRetry('botReply', $e));
    }

    public function testBotReplyDoesNotRetryInvalidResponse(): void
    {
        $e = new ApiException('bad json', 200, 'invalid_response');
        $this->assertFalse(Retry::shouldRetry('botReply', $e));
    }

    public function testBotReplyDoesNotRetryResponseReadError(): void
    {
        $e = new ApiException('read failed', 200, 'response_read_error');
        $this->assertFalse(Retry::shouldRetry('botReply', $e));
    }

    // --- messages ----------------------------------------------------------

    public function testMessagesRetries429(): void
    {
        $e = new ApiException('rate limited', 429, 'rate_limited');
        $this->assertTrue(Retry::shouldRetry('messages', $e));
    }

    public function testMessagesDoesNotRetry5xx(): void
    {
        $e = new ApiException('unavailable', 503, 'unavailable');
        $this->assertFalse(Retry::shouldRetry('messages', $e));
    }

    public function testMessagesRetriesNetworkError(): void
    {
        $e = new ApiException('network error', null, 'network_error');
        $this->assertTrue(Retry::shouldRetry('messages', $e));
    }

    public function testMessagesDoesNotRetryTimeout(): void
    {
        $e = new ApiException('timeout', null, 'timeout');
        $this->assertFalse(Retry::shouldRetry('messages', $e));
    }

    public function testMessagesDoesNotRetryInvalidResponse(): void
    {
        $e = new ApiException('bad json', 200, 'invalid_response');
        $this->assertFalse(Retry::shouldRetry('messages', $e));
    }

    public function testMessagesDoesNotRetryResponseReadError(): void
    {
        $e = new ApiException('read failed', 200, 'response_read_error');
        $this->assertFalse(Retry::shouldRetry('messages', $e));
    }

    // --- retryDelayMs --------------------------------------------------

    public function testRetryAfterHeaderWins(): void
    {
        $this->assertSame(3000, Retry::retryDelayMs(0, 3));
    }

    public function testRetryDelayIsFullJitterBoundedByCap(): void
    {
        $d = Retry::retryDelayMs(1, null);
        $this->assertGreaterThanOrEqual(250, $d);
        $this->assertLessThanOrEqual(1000, $d);
    }

    public function testRetryDelayAttemptZeroBoundedByBase(): void
    {
        $d = Retry::retryDelayMs(0, null);
        $this->assertGreaterThanOrEqual(125, $d);
        $this->assertLessThanOrEqual(250, $d);
    }
}
