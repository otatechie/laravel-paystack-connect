<?php

namespace Otatechie\PaystackConnect\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Otatechie\PaystackConnect\Exceptions\PaystackException;

/**
 * A thin client for Paystack's REST API. Every failure throws a
 * PaystackException with Paystack's own message; nothing fails silently.
 */
class PaystackClient
{
    public function __construct(
        private readonly ?string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
        private readonly int $timeout = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> The full response body: status, message, data and meta.
     */
    public function get(string $uri, array $query = []): array
    {
        // Reads are safe to repeat, so retry brief network failures.
        return $this->send('GET', $uri, fn (PendingRequest $http) => $http
            ->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false)
            ->get($uri, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = []): array
    {
        return $this->send('POST', $uri, fn (PendingRequest $http) => $http->post($uri, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $uri, array $data = []): array
    {
        return $this->send('PUT', $uri, fn (PendingRequest $http) => $http->put($uri, $data));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, callable $call): array
    {
        if (blank($this->secretKey)) {
            throw PaystackException::missingSecretKey();
        }

        $http = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw PaystackException::unreachable($method, $uri, $e);
        }

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ($body['status'] ?? null) !== true) {
            throw PaystackException::fromResponse($method, $uri, $response);
        }

        return $body;
    }
}
