<?php

namespace Otatechie\PaystackConnect;

use Illuminate\Support\Facades\Log;
use Otatechie\PaystackConnect\Events\WebhookHandled;
use Otatechie\PaystackConnect\Events\WebhookReceived;
use Otatechie\PaystackConnect\Models\WebhookEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a stored webhook event to the matching payment, once.
 *
 * Used by the webhook controller when Paystack delivers an event, and by the
 * retry command for events that failed. If processing fails, the error is
 * recorded on the event and the exception is rethrown.
 */
class WebhookProcessor
{
    public function __construct(private readonly PaymentReconciler $reconciler) {}

    /**
     * @return string "ok" when handled now, "duplicate" when already handled,
     *                "processing" when another request is handling it.
     *
     * @throws Throwable Whatever the handling or a listener threw.
     */
    public function process(WebhookEvent $event): string
    {
        if ($event->processed_at) {
            return 'duplicate';
        }

        // Claim the event, so an overlapping delivery of the same payload is
        // turned away instead of running listeners twice. A claim older than
        // a minute belongs to a request that died, and can be taken over.
        $claimed = WebhookEvent::query()
            ->whereKey($event->id)
            ->whereNull('processed_at')
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinute()))
            ->update(['claimed_at' => now()]);

        if (! $claimed) {
            return 'processing';
        }

        $payload = $event->payload;

        try {
            if ($event->event === 'charge.success' && isset($payload['data'])) {
                $payment = $this->reconciler->reconcile($payload['data']);

                if (! $payment) {
                    $this->log()->info('Paystack charge has no matching payment; it was probably created outside this package.', [
                        'reference' => $payload['data']['reference'] ?? null,
                    ]);
                }
            }

            if ($event->event === 'refund.processed' && isset($payload['data'])) {
                $this->reconciler->reconcileRefund($payload['data']);
            }

            if ($event->event === 'refund.failed' && isset($payload['data'])) {
                $this->reconciler->releaseRefund($payload['data']);
            }

            WebhookReceived::dispatch($event->event, $payload);

            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $event->update(['error' => $e->getMessage(), 'claimed_at' => null]);

            $this->log()->error('Paystack webhook processing failed; it will be retried.', [
                'event' => $event->event,
                'webhook_event_id' => $event->id,
                'exception' => $e,
            ]);

            throw $e;
        }

        WebhookHandled::dispatch($event->event, $payload);

        return 'ok';
    }

    private function log(): LoggerInterface
    {
        return Log::channel(config('paystack-connect.log_channel'));
    }
}
