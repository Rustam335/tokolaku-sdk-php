<?php

declare(strict_types=1);

namespace Tokolaku\Tests;

use PHPUnit\Framework\TestCase;
use Tokolaku\Client;

final class SanityTest extends TestCase
{
    public function testClientClassExists(): void
    {
        $this->assertTrue(class_exists(Client::class));
    }
}
