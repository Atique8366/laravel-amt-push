<?php

namespace BiometricPush\AmtPush\Server;

use Illuminate\Support\Facades\Log;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Protocol\CommandBuilder;
use BiometricPush\AmtPush\Services\ResultApplier;

/**
 * Sends queued commands to connected devices, one in flight per device (the protocol's
 * responses carry no correlation id). Batches run in sequence order.
 */
class Dispatcher
{
    /** @var CommandBuilder */
    private $builder;

    /** @var MessageLog */
    private $messages;

    public function __construct(CommandBuilder $builder, MessageLog $messages)
    {
        $this->builder = $builder;
        $this->messages = $messages;
    }

    /** @return int commands sent */
    public function tick(Sessions $sessions, ?int $max = null): int
    {
        $max = $max ?: (int) config('amt-push.server.max_commands_per_tick', 50);
        $this->expireTimedOut($sessions);

        $sent = 0;
        foreach ($sessions->all() as $session) {
            if ($sent >= $max) {
                break;
            }
            if (!$session->device || $session->pending) {
                continue;
            }
            $cmd = $this->nextFor($session->device->fresh());
            if ($cmd && $this->send($session, $cmd)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Sent and unanswered past its timeout: retry later or give up. */
    public function expireTimedOut(Sessions $sessions): void
    {
        foreach ($sessions->all() as $session) {
            if (!$session->pending) {
                continue;
            }
            $cmd = $session->pending->fresh();
            if (!$cmd || $cmd->status !== Command::STATUS_SENT) {
                $session->clearPending();
                continue;
            }
            if (time() - $session->pendingSince < max(1, (int) $cmd->timeout_seconds)) {
                continue;
            }
            $cmd->markTimedOut();
            $session->clearPending();
            if ($cmd->isFinished()) {
                app(ResultApplier::class)->afterFinished($session->device, $cmd, null);
            }
        }
    }

    /** The next command this device may run now, or null. */
    public function nextFor(Device $device): ?Command
    {
        $candidates = Command::where('device_id', $device->id)->where('status', Command::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')->limit(50)->get();

        foreach ($candidates as $cmd) {
            if ($cmd->batch_id && Command::where('device_id', $device->id)->where('batch_id', $cmd->batch_id)
                    ->where('sequence', '<', $cmd->sequence)
                    ->whereIn('status', [Command::STATUS_PENDING, Command::STATUS_SENT])->exists()) {
                continue;   // an earlier step of its batch is not done yet
            }
            if ($device->isReadOnly() && !in_array($cmd->request, Command::READ_REQUESTS, true)) {
                $cmd->forceFill(['status' => Command::STATUS_CANCELLED, 'error' => 'Cancelled: device is read-only', 'finished_at' => now()])->save();
                app(ResultApplier::class)->afterFinished($device, $cmd, null);
                continue;
            }
            if (in_array($cmd->request, Command::NEED_ALL_LOGS, true) && config('amt-push.commands.wait_for_uploads', true)
                && ($wait = $this->uploadsMissing($device)) !== null) {
                $cmd->forceFill(['available_at' => now()->addSeconds(30), 'error' => $wait])->save();
                continue;
            }

            return $cmd;
        }

        return null;
    }

    public function send(Session $session, Command $cmd): bool
    {
        try {
            $xml = $this->builder->build($cmd, $session->device);
        } catch (\Throwable $e) {
            $cmd->markFailed('build: ' . $e->getMessage(), true);
            app(ResultApplier::class)->afterFinished($session->device, $cmd, null);

            return false;
        }

        $cmd->markSent();
        $session->pending = $cmd;
        $session->pendingSince = time();
        try {
            $session->socket->send($xml);
            $this->messages->write($session, 'out', $cmd->request, $xml);
        } catch (\Throwable $e) {
            Log::warning('[amt-push] socket write failed', ['command' => $cmd->id, 'error' => $e->getMessage()]);
            $session->clearPending();
            $cmd->markFailed('write: ' . $e->getMessage());
            if ($cmd->isFinished()) {
                app(ResultApplier::class)->afterFinished($session->device, $cmd, null);
            }

            return false;
        }

        return true;
    }

    /** Why a log clear must wait (null = every punch the device reports is stored). */
    private function uploadsMissing(Device $device): ?string
    {
        if ($device->log_count === null) {
            return 'Waiting: device has not reported its log count yet';
        }
        $missing = (int) $device->log_count - $device->storedPunchCount();

        return $missing > 0 ? "Waiting: {$missing} punch(es) on the device are not stored yet" : null;
    }
}
