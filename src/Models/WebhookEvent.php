<?php

namespace Otatechie\PaystackConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every webhook Paystack sends, stored once. Paystack retries deliveries, so a
 * payload that has already been processed is recognised and skipped.
 *
 * @property int $id
 * @property string $event
 * @property string $payload_hash
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class WebhookEvent extends Model
{
    protected $table = 'paystack_webhook_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
