<?php

namespace Otatechie\PaystackConnect\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired for every verified webhook, so your app can handle events this package doesn't. */
class WebhookReceived
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $event, public readonly array $payload) {}
}
