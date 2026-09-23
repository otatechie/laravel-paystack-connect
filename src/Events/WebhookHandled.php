<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once a webhook has been handled and the payment saved. Listen here
 * when you need the payment's new state; WebhookReceived fires before it.
 */
class WebhookHandled
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $event, public readonly array $payload) {}
}
