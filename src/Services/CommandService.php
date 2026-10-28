<?php

namespace BiometricPush\Adms\Services;

use Illuminate\Support\Facades\DB;
use BiometricPush\Adms\Events\CommandFinished;
use BiometricPush\Adms\Exceptions\ReadOnlyDeviceException;
use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceTemplate;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Support\Protocol;

/**
 * Command queue for ADMS devices. Devices are polled, not called:
 *
 *  queue()   → row in the commands table (status queued)
 *  deliver() → on getrequest: "C:<id>:<command>\n" lines, rows → sent
 *  record()  → on devicecmd:  "ID=<id>&Return=<code>&CMD=..." → success / failed
 *
 * Unanswered commands: retriable types are re-queued (up to commands.max_attempts),
 * REBOOT / CLEAR are never re-sent (→ expired).
 */
class CommandService
{
    /** Commands that can make the device lose punches it hasn't uploaded yet. */
    public const NEED_ALL_LOGS_UPLOADED = ['user_delete', 'clear_log'];

    /**
     * @param array $attrs device_user_id, employee_id, meta, source, created_by
     * @throws ReadOnlyDeviceException for a write on a read-only device
     */
    public function queue(Device $device, string $type, string $command, array $attrs = []): Command
    {
        if ($device->isReadOnly() && !in_array($type, Command::READ_TYPES, true)) {
            throw ReadOnlyDeviceException::forDevice($device->serial_number);
        }

        return Command::create(array_merge([
            'device_id' => $device->id,
            'type'      => $type,
            'command'   => $command,
            'status'    => Command::STATUS_QUEUED,
            'queued_at' => now(),
            'source'    => 'manual',
        ], $attrs));
    }

    /** True when every punch the device reports is stored (unknown count = not confirmed). */
    public function allLogsUploaded(Device $device): bool
    {
        return $this->pendingLogCount($device) === 0;
    }

    /** Punches the device holds that are not stored yet (null = device never reported its count). */
    public function pendingLogCount(Device $device): ?int
    {
        if ($device->attlog_count === null) {
            return null;
        }
        $stored = Punch::where('device_id', $device->id)->count();

        return max(0, (int) $device->attlog_count - $stored);
    }

    /** Text to answer a getrequest with: queued commands, or "OK". */
    public function deliver(Device $device): array
    {
        $this->handleUnanswered($device);
        if ($device->isReadOnly()) {
            $this->cancelWrites($device, 'Cancelled: device is read-only');
        }

        $commands = Command::where('device_id', $device->id)
            ->where('status', Command::STATUS_QUEUED)
            ->orderBy('id')
            ->limit((int) config('adms.commands.max_per_poll', 50))
            ->get();

        $maxBytes = (int) config('adms.commands.max_bytes_per_poll', 1048576);
        $waitForUploads = (bool) config('adms.commands.wait_for_uploads', true);
        $logsSafe = null;
        $heldUsers = [];   // device users with a held command: their later commands wait too (keep order)
        $lines = [];
        $ids = [];
        $bytes = 0;
        foreach ($commands as $cmd) {
            if ($cmd->device_user_id && isset($heldUsers[$cmd->device_user_id])) {
                continue;
            }
            if ($waitForUploads && in_array($cmd->type, self::NEED_ALL_LOGS_UPLOADED, true)) {
                if ($logsSafe === null) {
                    $logsSafe = $this->allLogsUploaded($device);
                }
                if (!$logsSafe) {
                    $pending = $this->pendingLogCount($device);
                    $note = $pending === null
                        ? 'Waiting: device has not reported its punch count yet'
                        : "Waiting: {$pending} punch(es) on the device not uploaded yet";
                    if ($cmd->response !== $note) {
                        $cmd->response = $note;
                        $cmd->save();
                    }
                    if ($cmd->device_user_id) {
                        $heldUsers[$cmd->device_user_id] = true;
                    }
                    continue;   // stays queued, retried on the next poll
                }
            }
            // a command must be one line on the wire
            $line = 'C:' . $cmd->id . ':' . str_replace(["\r", "\n"], ' ', $cmd->command) . "\n";
            if ($bytes + strlen($line) > $maxBytes && $ids) {
                break;
            }
            $lines[] = $line;
            $ids[] = $cmd->id;
            $bytes += strlen($line);
        }

        if ($ids) {
            Command::whereIn('id', $ids)->update([
                'status'     => Command::STATUS_SENT,
                'sent_at'    => now(),
                'attempts'   => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        }

        return ['body' => $ids ? implode('', $lines) : "OK\n", 'count' => count($ids)];
    }

    /**
     * devicecmd body: one "ID=..&Return=..&CMD=.." line per command; any other
     * lines belong to the result above them (e.g. INFO's key=value data).
     *
     * @return int number of results recorded
     */
    public function record(Device $device, string $body): int
    {
        $results = [];
        $current = null;
        foreach (Protocol::lines($body) as $line) {
            if (stripos(ltrim($line), 'ID=') === 0) {
                if ($current) {
                    $results[] = $current;
                }
                $current = ['line' => trim($line), 'fields' => Protocol::resultFields($line), 'payload' => []];
            } elseif ($current) {
                $current['payload'][] = $line;
            }
        }
        if ($current) {
            $results[] = $current;
        }

        $recorded = 0;
        foreach ($results as $r) {
            $id = isset($r['fields']['ID']) ? $r['fields']['ID'] : null;
            if ($id === null || !ctype_digit($id)) {
                continue;
            }
            $cmd = Command::where('id', (int) $id)->where('device_id', $device->id)->first();
            if (!$cmd || in_array($cmd->status, [Command::STATUS_SUCCESS, Command::STATUS_CANCELLED], true)) {
                continue;
            }

            $ret = isset($r['fields']['Return']) && preg_match('/^-?\d+$/', $r['fields']['Return'])
                ? (int) $r['fields']['Return'] : null;

            $cmd->return_code = $ret;
            $cmd->response = mb_substr($r['line'] . ($r['payload'] ? "\n" . implode("\n", $r['payload']) : ''), 0, 60000);
            $cmd->answered_at = now();
            $cmd->status = ($ret !== null && $ret >= 0) ? Command::STATUS_SUCCESS : Command::STATUS_FAILED;
            $cmd->save();

            $this->afterResult($device, $cmd, $r['payload']);
            $recorded++;
        }

        return $recorded;
    }

    /** Cancel a command that was not sent yet; puts its user / template back. */
    public function cancel(Command $cmd, string $reason = 'Cancelled'): bool
    {
        if ($cmd->status !== Command::STATUS_QUEUED) {
            return false;
        }
        $cmd->update(['status' => Command::STATUS_CANCELLED, 'response' => $reason]);
        if ($cmd->type === 'bio_update') {
            app(BiometricService::class)->afterResult($cmd, $reason);
        }
        if ($cmd->device_user_id) {
            DeviceUser::where('id', $cmd->device_user_id)->where('last_command_id', $cmd->id)
                ->whereIn('sync_status', ['queued', 'removing'])
                ->update(['sync_status' => 'pending', 'last_error' => $reason]);
        }

        return true;
    }

    /** Cancel every write still waiting for this device (e.g. read_only switched on). */
    public function cancelWrites(Device $device, string $reason): int
    {
        $writes = Command::where('device_id', $device->id)->where('status', Command::STATUS_QUEUED)
            ->whereNotIn('type', Command::READ_TYPES)->get();
        foreach ($writes as $cmd) {
            $this->cancel($cmd, $reason);
        }

        return $writes->count();
    }

    /** Sent but never answered: re-queue (retriable, under max_attempts) or expire. */
    private function handleUnanswered(Device $device): void
    {
        $stale = Command::where('device_id', $device->id)
            ->where('status', Command::STATUS_SENT)
            ->where('sent_at', '<', now()->subSeconds((int) config('adms.commands.answer_timeout_seconds', 300)))
            ->get();

        foreach ($stale as $cmd) {
            if (in_array($cmd->type, Command::RETRIABLE, true) && $cmd->attempts < (int) config('adms.commands.max_attempts', 3)) {
                $cmd->status = Command::STATUS_QUEUED;
                $cmd->save();
                continue;
            }
            $cmd->status = Command::STATUS_EXPIRED;
            $cmd->response = 'No answer from device after ' . $cmd->attempts . ' attempt(s)';
            $cmd->save();
            $this->afterResult($device, $cmd, []);
        }
    }

    /** Apply a finished command to the device / device user / template it concerns. */
    private function afterResult(Device $device, Command $cmd, array $payload): void
    {
        $ok = $cmd->status === Command::STATUS_SUCCESS;

        if ($cmd->type === 'info' && $ok && $payload) {
            app(DeviceInfoService::class)->applyOptions($device, implode("\n", $payload), false);
        }

        if ($cmd->type === 'query_users' && $ok) {
            $users = app(UserService::class);
            // A full user read is complete: users not in it were deleted on the device itself.
            if (trim($cmd->command) === 'DATA QUERY USERINFO') {
                $users->markMissingAfterFullRead($device, $cmd);
            }
            // Read-first chain: the device's current users are imported, now apply the waiting writes.
            if (is_array($cmd->meta) && !empty($cmd->meta['then_set_users'])) {
                $users->applyDeferred($device->fresh(), $cmd);
            }
        }

        if ($cmd->type === 'bio_update') {
            app(BiometricService::class)->afterResult($cmd, $this->errorText($cmd));
        } elseif ($cmd->device_user_id) {
            $this->applyToUser($device, $cmd, $ok);
        }

        event(new CommandFinished($device, $cmd, $payload));
    }

    private function applyToUser(Device $device, Command $cmd, bool $ok): void
    {
        $user = DeviceUser::find($cmd->device_user_id);
        if (!$user || (int) $user->last_command_id !== (int) $cmd->id) {
            return;   // a newer command superseded this one
        }

        if ($cmd->type === 'user_update') {
            if ($ok) {
                $meta = $cmd->meta ?: [];
                $user->fill([
                    'sync_status' => 'synced',
                    'sync_hash'   => isset($meta['hash']) ? $meta['hash'] : null,
                    'name'        => array_key_exists('name', $meta) ? $meta['name'] : $user->name,
                    'privilege'   => isset($meta['pri']) ? (string) $meta['pri'] : $user->privilege,
                    'password'    => array_key_exists('passwd', $meta) ? $meta['passwd'] : $user->password,
                    'card'        => array_key_exists('card', $meta) ? $meta['card'] : $user->card,
                    'last_error'  => null,
                    'synced_at'   => now(),
                    'removed_at'  => null,
                ]);
            } else {
                $user->fill(['sync_status' => 'failed', 'last_error' => $this->errorText($cmd)]);
            }
        } elseif ($cmd->type === 'user_delete') {
            // -10 "user not found" = already gone, which is what we wanted
            if ($ok || (int) $cmd->return_code === -10) {
                $user->fill(['sync_status' => 'removed', 'sync_hash' => null, 'removed_at' => now(), 'last_error' => null]);
                // the device drops the user's templates / photo with them: a later copy re-sends them
                DeviceTemplate::where('device_id', $device->id)->where('pin', $user->pin)
                    ->whereIn('status', ['on_device', 'success'])
                    ->update(['status' => 'missing', 'last_error' => 'User removed from the device', 'updated_at' => now()]);
            } else {
                $user->fill(['sync_status' => 'failed', 'last_error' => $this->errorText($cmd)]);
            }
        }
        $user->save();
    }

    public function errorText(Command $cmd): string
    {
        if ($cmd->status === Command::STATUS_EXPIRED) {
            return 'No answer from device';
        }
        if ($cmd->status === Command::STATUS_CANCELLED) {
            return (string) $cmd->response;
        }

        return Command::returnMeaning($cmd->return_code) . ' (Return=' . $cmd->return_code . ')';
    }
}
