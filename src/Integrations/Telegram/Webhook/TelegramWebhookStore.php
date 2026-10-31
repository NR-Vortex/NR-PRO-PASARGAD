<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Webhook;

interface TelegramWebhookStore
{
    /**
     * Atomically enqueue a command, unless this update_id has already been enqueued.
     * Return true if newly enqueued; false if a duplicate.
     * @param array<string,int|string> $payload
     */
    public function enqueueOnce(int $updateId, array $payload): bool;
}
