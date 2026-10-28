<?php

namespace BiometricPush\AmtPush\Server;

use Illuminate\Support\Facades\Log;
use BiometricPush\AmtPush\Models\Message;
use BiometricPush\AmtPush\Protocol\Encoding;

/** Raw XML in / out per device (templates masked). Never breaks the conversation. */
class MessageLog
{
    public function write(Session $session, string $direction, ?string $kind, string $xml): void
    {
        if (!config('amt-push.message_log.enabled', true)
            || ($kind === 'KeepAlive' && !config('amt-push.message_log.log_keepalive', false))) {
            return;
        }
        try {
            Message::create([
                'device_id'     => $session->device ? $session->device->id : null,
                'serial_number' => $session->serialNumber,
                'direction'     => $direction,
                'kind'          => $kind !== null ? mb_substr($kind, 0, 48) : null,
                'payload'       => mb_substr(Encoding::maskPayload($xml), 0, 65000),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[amt-push] message log write failed', ['error' => $e->getMessage()]);
        }
    }

    public function prune(?int $days = null): int
    {
        $days = max(1, $days ?: (int) config('amt-push.message_log.keep_days', 7));

        return Message::where('created_at', '<', now()->subDays($days))->limit(10000)->delete();
    }
}
