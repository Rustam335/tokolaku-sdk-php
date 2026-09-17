<?php

declare(strict_types=1);

namespace Tokolaku\Exception;

/** HTTP 400/422 + validasi klien (XOR text/template, apiKey wajib, dll). */
class ValidationException extends ApiException
{
}
