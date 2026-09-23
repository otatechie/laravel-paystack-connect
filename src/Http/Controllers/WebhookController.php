<?php

namespace Otatechie\PaystackConnect\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Otatechie\PaystackConnect\Models\WebhookEvent;
use Otatechie\PaystackConnect\WebhookProcessor;
use Throwable;

/**
 * Receives Paystack webhooks after VerifyPaystackSignature has checked them.
 *
 * Each payload is stored once. A retry of a payload that was already processed
 * is acknowledged and skipped, so nothing is fulfilled or emailed twice. If
 * processing fails, the event is kept for Paystack's next retry, or for
 * `paystack-connect:retry-webhooks`.
 */
class WebhookController
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['event'])) {
            return response()->json(['status' => 'ignored', 'reason' => 'Not a Paystack event.'], 400);
        }

        $event = $this->record($payload, $raw);

        try {
            return response()->json(['status' => $processor->process($event)]);
        } catch (Throwable) {
            // Recorded and logged by the processor; Paystack retries on a non-2xx response.
            return response()->json(['status' => 'error'], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    private function record(array $payload, string $raw): WebhookEvent
    {
        $hash = hash('sha256', $raw);

        try {
            return WebhookEvent::firstOrCreate(
                ['payload_hash' => $hash],
                ['event' => $payload['event'], 'payload' => $payload],
            );
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same payload arrived at the same moment.
            return WebhookEvent::where('payload_hash', $hash)->firstOrFail();
        }
    }
}
