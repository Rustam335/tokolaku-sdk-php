<?php

declare(strict_types=1);

namespace Tokolaku\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tokolaku\Client;
use Tokolaku\Exception\ApiException;
use Tokolaku\Exception\RateLimitException;
use Tokolaku\Tests\Support\GuzzleMock;

final class RetryFlowTest extends TestCase
{
    use GuzzleMock;

    private const OK_REPLY_BODY = ['reply' => 'ok', 'parts' => ['ok']];
    private const OK_MSG_BODY = [
        'id' => 'm', 'channel_id' => 'c', 'to' => '628', 'type' => 'text',
        'status' => 'sent', 'provider_message_id' => null, 'charged_idr' => 0,
    ];

    private static function jsonResponse(int $status, array $body): Response
    {
        return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function err429(): Response
    {
        return self::jsonResponse(429, ['error' => ['code' => 'rate_limited', 'message' => 'pelan-pelan']]);
    }

    private static function err503(): Response
    {
        return self::jsonResponse(503, ['error' => ['code' => 'unavailable', 'message' => 'sebentar']]);
    }

    private static function malformedJson(): Response
    {
        return new Response(200, [], '{not valid json');
    }

    /** Simulasi koneksi terputus sebelum respons diterima (network error). */
    private static function networkError(): ConnectException
    {
        return new ConnectException('Could not resolve host', new Request('POST', 'x'));
    }

    /** Simulasi timeout via cURL errno 28 (CURLE_OPERATION_TIMEDOUT) di handler context. */
    private static function timeoutError(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
            new Request('POST', 'x'),
            null,
            ['errno' => 28],
        );
    }

    /** Respons 200 dengan body stream yang gagal dibaca pasca-header. */
    private static function bodyReadFailure(): Response
    {
        $stream = FnStream::decorate(Utils::streamFor(''), [
            'getContents' => function (): string {
                throw new RuntimeException('boom');
            },
        ]);

        return new Response(200, [], $stream);
    }

    public function testBotReply429ThenSuccessIsRetriedTwoCalls(): void
    {
        $history = [];
        $http = self::mockClient([self::err429(), self::jsonResponse(200, self::OK_REPLY_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        $res = $tk->botReply(['message' => 'hai']);

        $this->assertSame('ok', $res['reply']);
        $this->assertCount(2, $history);
    }

    public function testBotReply503AndNetworkErrorAreRetried(): void
    {
        $history = [];
        $http = self::mockClient([self::err503(), self::networkError(), self::jsonResponse(200, self::OK_REPLY_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        $tk->botReply(['message' => 'hai']);

        $this->assertCount(3, $history);
    }

    public function testBotReplyMaxRetriesRespected429RepeatedThrowsAfterThreeCalls(): void
    {
        $history = [];
        $http = self::mockClient([self::err429(), self::err429(), self::err429(), self::err429()], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        $this->expectException(RateLimitException::class);
        try {
            $tk->botReply(['message' => 'hai']);
        } finally {
            $this->assertCount(3, $history); // 1 asli + 2 retry
        }
    }

    public function testMessagesSend429AndNetworkErrorAreRetried(): void
    {
        $history = [];
        $http = self::mockClient([self::err429(), self::networkError(), self::jsonResponse(200, self::OK_MSG_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        $tk->sendMessage(['to' => '628', 'text' => 'hai']);

        $this->assertCount(3, $history);
    }

    public function testMessagesSend503IsNotRetried(): void
    {
        $history = [];
        $http = self::mockClient([self::err503(), self::jsonResponse(200, self::OK_MSG_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        $this->expectException(ApiException::class);
        try {
            $tk->sendMessage(['to' => '628', 'text' => 'hai']);
        } finally {
            $this->assertCount(1, $history);
        }
    }

    public function testTimeoutIsNotRetriedOnBotReply(): void
    {
        $history = [];
        $http = self::mockClient([self::timeoutError(), self::jsonResponse(200, self::OK_REPLY_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->botReply(['message' => 'x']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('timeout', $e->getErrorCode());
        }
        $this->assertCount(1, $history);
    }

    public function testTimeoutIsNotRetriedOnMessagesSend(): void
    {
        $history = [];
        $http = self::mockClient([self::timeoutError(), self::jsonResponse(200, self::OK_MSG_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->sendMessage(['to' => '628', 'text' => 'x']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('timeout', $e->getErrorCode());
        }
        $this->assertCount(1, $history);
    }

    public function testMalformedJsonBodyIsNotRetriedOnMessagesSend(): void
    {
        $history = [];
        $http = self::mockClient([self::malformedJson(), self::jsonResponse(200, self::OK_MSG_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->sendMessage(['to' => '628', 'text' => 'hai']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
        $this->assertCount(1, $history);
    }

    public function testMalformedJsonBodyIsNotRetriedOnBotReply(): void
    {
        $history = [];
        $http = self::mockClient([self::malformedJson(), self::jsonResponse(200, self::OK_REPLY_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->botReply(['message' => 'hai']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('invalid_response', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
        $this->assertCount(1, $history);
    }

    public function testBodyReadFailurePostHeaderIsNotRetriedOnMessagesSend(): void
    {
        $history = [];
        $http = self::mockClient([self::bodyReadFailure(), self::jsonResponse(200, self::OK_MSG_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->sendMessage(['to' => '628', 'text' => 'hai']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('response_read_error', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
        $this->assertCount(1, $history);
    }

    public function testBodyReadFailurePostHeaderIsNotRetriedOnBotReply(): void
    {
        $history = [];
        $http = self::mockClient([self::bodyReadFailure(), self::jsonResponse(200, self::OK_REPLY_BODY)], $history);
        $tk = new Client(['api_key' => 'k', 'http_client' => $http, 'max_retries' => 2]);

        try {
            $tk->botReply(['message' => 'hai']);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('response_read_error', $e->getErrorCode());
            $this->assertSame(200, $e->getStatus());
        }
        $this->assertCount(1, $history);
    }
}
