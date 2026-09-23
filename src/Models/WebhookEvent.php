<?php

namespace Otatechie\PaystackConnect\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every webhook Paystack sends, stored once. Paystack retries deliveries, so a
 * payload that has already been processed is recognised and skipped.
 *
 * Old processed events are removed by `php artisan model:prune`.
 *
 * @property int $id
 * @property string $event
 * @property string $payload_hash
 * @property array<string, mixed> $payload
 * @property Carbon|null $claimed_at Set while a request is processing the event.
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class WebhookEvent extends Model
{
    use MassPrunable;

    protected $table = 'paystack_webhook_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Processed events older than webhook.keep_days. Unprocessed ones are
     * kept so they can still be retried.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('paystack-connect.webhook.keep_days');

        return static::query()
            ->whereNotNull('processed_at')
            ->when($days === null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('processed_at', '<', now()->subDays((int) $days));
    }
}
