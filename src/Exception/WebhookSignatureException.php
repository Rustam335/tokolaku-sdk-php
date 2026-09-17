<?php

declare(strict_types=1);

namespace Tokolaku\Exception;

use Exception;

/** Signature webhook tidak valid (Tokolaku\Webhooks::constructEvent). */
class WebhookSignatureException extends Exception
{
    public function __construct(string $message = 'Signature webhook tidak valid')
    {
        parent::__construct($message);
    }
}
