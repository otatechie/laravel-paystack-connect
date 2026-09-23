<?php

namespace Otatechie\PaystackConnect\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Otatechie\PaystackConnect\Events\WebhookReceived;
use Otatechie\PaystackConnect\Models\WebhookEvent;
use Otatechie\PaystackConnect\PaymentReconciler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives Paystack webhooks after VerifyPaystackSignature has checked them.
 *
 * Each payload is stored once. A retry of a payload that was already processed
 * is acknowledged and skipped, so nothing is fulfilled or emailed twice. If
 * processing fails, the event is kept and Paystack's next retry tries again.
 */
class WebhookController
{
    public function __invoke(Request $request, PaymentReconciler $reconciler): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['event'])) {
            return response()->json(['status' => 'ignored', 'reason' => 'Not a Paystack event.'], 400);
        }

        $event = $this->record($payload, $raw);

        if ($event->processed_at) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            if ($payload['event'] === 'charge.success' && isset($payload['data'])) {
                $payment = $reconciler->reconcile($payload['data']);

                if (! $payment) {
                    $this->log()->info('Paystack charge has no matching payment; it was probably created outside this package.', [
                        'reference' => $payload['data']['reference'] ?? null,
                    ]);
                }
            }

            if ($payload['event'] === 'refund.processed' && isset($payload['data'])) {
                $reconciler->reconcileRefund($payload['data']);
            }

            WebhookReceived::dispatch($payload['event'], $payload);

            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $event->update(['error' => $e->getMessage()]);

            $this->log()->error('Paystack webhook processing failed; Paystack will retry.', [
                'event' => $payload['event'],
                'webhook_event_id' => $event->id,
                'exception' => $e,
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
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

    private function log(): LoggerInterface
    {
        return Log::channel(config('paystack-connect.log_channel'));
    }
}
