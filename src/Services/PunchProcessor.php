<?php

namespace BiometricPush\AmtPush\Services;

use BiometricPush\AmtPush\Contracts\PunchHandler;
use BiometricPush\AmtPush\Jobs\ProcessPunches;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Models\DeviceUser;
use BiometricPush\AmtPush\Models\Punch;

/**
 * Hands stored punches to the configured PunchHandler, per device, in time order. One run per
 * device at a time (device row locked); each punch in its own transaction; handler exceptions are
 * retried up to punches.max_attempts, then the punch is marked "error: ...".
 */
class PunchProcessor
{
    public function handler(): ?PunchHandler
    {
        $class = config('amt-push.punches.handler');

        return $class ? app($class) : null;
    }

    /** Process now, queue a job, or nothing, as punches.process says. Never throws. */
    public function dispatch(Device $device): void
    {
        if (!$device->process_punches || !config('amt-push.punches.handler')) {
            return;
        }
        try {
            $mode = config('amt-push.punches.process', 'sync');
            if ($mode === 'sync') {
                $this->processPending($device);
            } elseif ($mode === 'queue') {
                $job = new ProcessPunches($device->id);
                if ($connection = config('amt-push.punches.connection')) {
                    $job->onConnection($connection);
                }
                if ($queue = config('amt-push.punches.queue')) {
                    $job->onQueue($queue);
                }
                app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array ['processed' => n, 'results' => [result => count]] */
    public function processPending(Device $device, ?int $limit = null): array
    {
        $handler = $this->handler();
        $out = ['processed' => 0, 'results' => []];
        if (!$handler) {
            return $out;
        }
        $limit = $limit ?: (int) config('amt-push.punches.batch_size', 200);
        $maxAttempts = max(1, (int) config('amt-push.punches.max_attempts', 3));
        $requireEmployee = (bool) config('amt-push.punches.require_employee', true);
        $connection = $device->getConnection();

        $connection->transaction(function () use ($connection, $device, $handler, $limit, $maxAttempts, $requireEmployee, &$out) {
            $locked = Device::whereKey($device->id)->lockForUpdate()->first();
            if (!$locked) {
                return;
            }
            $punches = Punch::where('device_id', $locked->id)->whereNull('processed_at')
                ->orderBy('punched_at')->orderBy('id')->limit($limit)->get();
            foreach ($punches as $punch) {
                $result = $this->processOne($connection, $locked, $punch, $handler, $maxAttempts, $requireEmployee);
                $out['processed']++;
                $out['results'][$result] = (isset($out['results'][$result]) ? $out['results'][$result] : 0) + 1;
            }
        });

        return $out;
    }

    /**
     * Mark punches as not processed so they run again.
     *
     * @param array|null $punchIds null = every punch of the device whose result is one of $results
     */
    public function reset(Device $device, ?array $punchIds = null, array $results = ['unmatched']): int
    {
        $query = Punch::where('device_id', $device->id)->whereNotNull('processed_at');
        $punchIds !== null ? $query->whereIn('id', $punchIds) : $query->whereIn('process_result', $results);

        return $query->update(['processed_at' => null, 'process_result' => null, 'process_attempts' => 0, 'updated_at' => now()]);
    }

    private function processOne($connection, Device $device, Punch $punch, PunchHandler $handler, int $maxAttempts, bool $requireEmployee): string
    {
        if ($device->punches_from && $punch->punched_at->lt($device->punches_from->copy()->startOfDay())) {
            return $this->finish($punch, 'before_punches_from');
        }
        if (!$punch->employee_id) {
            $employeeId = DeviceUser::where('device_id', $device->id)->where('user_id', $punch->user_id)->value('employee_id');
            $punch->employee_id = $employeeId !== null ? (string) $employeeId : null;
        }
        if ($requireEmployee && !$punch->employee_id) {
            return $this->finish($punch, 'unmatched');
        }

        try {
            $result = $connection->transaction(function () use ($handler, $punch) {
                return $handler->handle($punch);
            });

            return $this->finish($punch, $result !== null && $result !== '' ? mb_substr((string) $result, 0, 191) : 'processed');
        } catch (\Throwable $e) {
            report($e);
            $punch->process_attempts = (int) $punch->process_attempts + 1;
            if ($punch->process_attempts >= $maxAttempts) {
                return $this->finish($punch, mb_substr('error: ' . $e->getMessage(), 0, 191));
            }
            $punch->process_result = mb_substr('retry: ' . $e->getMessage(), 0, 191);
            $punch->save();

            return 'retry';
        }
    }

    private function finish(Punch $punch, string $result): string
    {
        $punch->processed_at = now();
        $punch->process_result = $result;
        $punch->save();

        return strpos($result, 'error: ') === 0 ? 'error' : $result;
    }
}
