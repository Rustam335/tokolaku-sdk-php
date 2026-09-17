<?php

declare(strict_types=1);

namespace Tokolaku\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tokolaku\Client;
use Tokolaku\Exception\AuthenticationException;
use Tokolaku\Exception\ValidationException;
use Tokolaku\Tests\Support\GuzzleMock;

final class ClientTest extends TestCase
{
    use GuzzleMock;

    public function testBotReplyUrlHeaderBodyAndTypedResponse(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['reply' => 'Halo!', 'parts' => ['Halo!']], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'tk_test_sk_abc', 'http_client' => $http]);
        $res = $tk->botReply(['message' => 'halo', 'session_id' => 's1']);

        $this->assertSame('Halo!', $res['reply']);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('https://api.tokolaku.id/api/v1/bot/reply', (string) $request->getUri());
        $this->assertSame('Bearer tk_test_sk_abc', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['message' => 'halo', 'session_id' => 's1'],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testMessagesSendTextInfersTypeText(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, [], json_encode([
                'id' => 'm1', 'channel_id' => 'c1', 'to' => '628', 'type' => 'text',
                'status' => 'sent', 'provider_message_id' => null, 'charged_idr' => 0,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'k', 'http_client' => $http]);
        $res = $tk->sendMessage(['to' => '628', 'text' => 'hai']);

        $this->assertSame('sent', $res['status']);
        $request = $history[0]['request'];
        $this->assertSame('https://api.tokolaku.id/api/v1/messages', (string) $request->getUri());
        $this->assertSame(
            ['to' => '628', 'text' => 'hai', 'type' => 'text'],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testMessagesSendTemplateInfersTypeTemplateAndForwardsFields(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, [], json_encode([
                'id' => 'm2', 'channel_id' => 'c1', 'to' => '628', 'type' => 'template',
                'status' => 'sent', 'provider_message_id' => 'wamid.x', 'charged_idr' => 350,
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'k', 'http_client' => $http]);
        $tk->sendMessage([
            'to' => '628',
            'template' => ['name' => 'order_update', 'language' => 'id', 'category' => 'utility'],
            'channel_id' => 'ch1',
            'country_code' => 'ID',
        ]);

        $request = $history[0]['request'];
        $this->assertSame(
            [
                'to' => '628',
                'template' => ['name' => 'order_update', 'language' => 'id', 'category' => 'utility'],
                'channel_id' => 'ch1',
                'country_code' => 'ID',
                'type' => 'template',
            ],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testMessagesSendBothOrNeitherThrowsValidationExceptionBeforeAnyRequest(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'k', 'http_client' => $http]);

        $this->expectException(ValidationException::class);
        try {
            $tk->sendMessage([
                'to' => '628',
                'text' => 'x',
                'template' => ['name' => 'n', 'language' => 'id', 'category' => 'utility'],
            ]);
        } finally {
            $this->assertCount(0, $history);
        }
    }

    public function testMessagesSendNeitherThrowsValidationExceptionBeforeAnyRequest(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, [], json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'k', 'http_client' => $http]);

        $this->expectException(ValidationException::class);
        try {
            $tk->sendMessage(['to' => '628']);
        } finally {
            $this->assertCount(0, $history);
        }
    }

    public function testErrorResponseMappedToClass401AuthenticationException(): void
    {
        $http = self::mockClient([
            new Response(401, [], json_encode(['error' => ['code' => 'invalid_key', 'message' => 'API key tidak valid']], JSON_THROW_ON_ERROR)),
        ]);

        $tk = new Client(['api_key' => 'salah', 'http_client' => $http]);

        try {
            $tk->botReply(['message' => 'hai']);
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('invalid_key', $e->getErrorCode());
            $this->assertSame(401, $e->getStatus());
        }
    }

    public function testConstructorAcceptsStringApiKey(): void
    {
        $tk = new Client('tk_live_sk_x');
        $this->assertInstanceOf(Client::class, $tk);
    }

    public function testConstructorCustomBaseUrl(): void
    {
        $history = [];
        $http = self::mockClient([
            new Response(200, [], json_encode(['reply' => 'ok', 'parts' => ['ok']], JSON_THROW_ON_ERROR)),
        ], $history);

        $tk = new Client(['api_key' => 'k', 'base_url' => 'http://localhost:3011', 'http_client' => $http]);
        $tk->botReply(['message' => 'hai']);

        $this->assertSame('http://localhost:3011/api/v1/bot/reply', (string) $history[0]['request']->getUri());
    }

    public function testConstructorRejectsMissingApiKey(): void
    {
        $this->expectException(ValidationException::class);
        new Client(['base_url' => 'http://localhost']);
    }
}
