<?php

namespace Otatechie\PaystackConnect\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PaystackException extends RuntimeException
{
    /** @var array<string, mixed>|null */
    public ?array $body = null;

    public static function missingSecretKey(): self
    {
        return new self('No Paystack secret key is set. Add PAYSTACK_SECRET_KEY to your .env file.');
    }

    public static function fromResponse(string $method, string $uri, Response $response): self
    {
        $message = $response->json('message') ?: $response->reason();

        $exception = new self("Paystack {$method} {$uri} failed ({$response->status()}): {$message}", $response->status());
        $exception->body = $response->json();

        return $exception;
    }

    public static function unreachable(string $method, string $uri, \Throwable $previous): self
    {
        return new self("Could not reach Paystack for {$method} {$uri}: {$previous->getMessage()}", 0, $previous);
    }
}
