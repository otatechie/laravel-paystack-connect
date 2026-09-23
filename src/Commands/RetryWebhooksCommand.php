<?php

namespace Otatechie\PaystackConnect\Commands;

use Illuminate\Console\Command;
use Otatechie\PaystackConnect\Models\WebhookEvent;
use Otatechie\PaystackConnect\WebhookProcessor;
use Throwable;

class RetryWebhooksCommand extends Command
{
    protected $signature = 'paystack-connect:retry-webhooks';

    protected $description = 'Process again every stored webhook that failed, without waiting for Paystack to resend it';

    public function handle(WebhookProcessor $processor): int
    {
        $retried = 0;
        $failing = 0;

        WebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNotNull('error')
            ->oldest('id')
            ->each(function (WebhookEvent $event) use ($processor, &$retried, &$failing) {
                try {
                    $processor->process($event);
                    $retried++;
                } catch (Throwable $e) {
                    $failing++;
                    $this->warn("#{$event->id} {$event->event}: {$e->getMessage()}");
                }
            });

        $this->info("{$retried} retried, {$failing} still failing.");

        return $failing ? self::FAILURE : self::SUCCESS;
    }
}
