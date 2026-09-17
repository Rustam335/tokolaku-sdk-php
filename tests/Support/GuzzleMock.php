<?php

declare(strict_types=1);

namespace Tokolaku\Tests\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Helper bersama untuk test yang butuh Guzzle client dengan respons/exception
 * yang di-mock (NOL network) + counter jumlah request via history middleware.
 */
trait GuzzleMock
{
    /**
     * @param array<int, mixed> $queue Item MockHandler (ResponseInterface|\Throwable|PromiseInterface)
     * @param array<int, array<string, mixed>> $history diisi (by-ref) satu entri per request
     */
    private static function mockClient(array $queue, array &$history = []): GuzzleClient
    {
        $history = [];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new GuzzleClient(['handler' => $stack]);
    }
}
