<?php

namespace BiometricPush\Adms\Services;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\DeviceUser;
use BiometricPush\Adms\Models\Punch;
use BiometricPush\Adms\Support\Protocol;

/**
 * Stores ATTLOG uploads as raw punches.
 *
 * Rows and the device's attlog_stamp are written in ONE transaction: if the insert fails
 * the stamp does not move, the device gets an error and re-sends the same records later.
 * Re-sent punches are ignored by the unique (serial_number, pin, punched_at) key.
 */
class PunchService
{
    /**
     * @return array{parsed:int, saved:int, skipped:int, bad_lines:array, punches:Collection}
     * @throws \Throwable on database failure (nothing saved, stamp unchanged)
     */
    public function storeAttlog(Device $device, string $body, ?string $stamp): array
    {
        $rows = [];
        $badLines = [];
        $now = now()->format('Y-m-d H:i:s');

        foreach (Protocol::lines($body) as $line) {
            $parsed = Protocol::parseAttlogLine($line);
            if ($parsed === null) {
                $badLines[] = mb_substr($line, 0, 200);
                continue;
            }
            // fields 7/8 are mask / temperature only on devices that have those features
            if (!$device->mask_detection) {
                $parsed['mask_flag'] = null;
            }
            if (!$device->temperature_detection) {
                $parsed['temperature'] = null;
            }
            $rows[] = $parsed + [
                'device_id'     => $device->id,
                'serial_number' => $device->serial_number,
                'employee_id'   => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        // employee of each PIN, as linked on this device right now
        if ($rows) {
            $links = DeviceUser::where('device_id', $device->id)->whereNotNull('employee_id')
                ->whereIn('pin', array_unique(array_column($rows, 'pin')))->pluck('employee_id', 'pin');
            foreach ($rows as $i => $row) {
                $rows[$i]['employee_id'] = isset($links[$row['pin']]) ? (string) $links[$row['pin']] : null;
            }
        }

        $saved = 0;
        $lastId = 0;
        $table = Punch::tableName();
        $connection = (new Punch())->getConnection();
        $connection->transaction(function () use ($connection, $table, $device, $rows, $stamp, &$saved, &$lastId) {
            $lastId = (int) $connection->table($table)->max('id');
            foreach (array_chunk($rows, 500) as $chunk) {
                $saved += $connection->table($table)->insertOrIgnore($chunk);
            }

            $update = [];
            if ($stamp !== null && ctype_digit($stamp)) {
                $update['attlog_stamp'] = $stamp;
            }
            if (!empty($rows)) {
                $update['last_attlog_at'] = now();
            }
            if ($update) {
                Device::whereKey($device->id)->update($update);
            }
        });

        return [
            'parsed'    => count($rows),
            'saved'     => $saved,
            'skipped'   => count($rows) - $saved,   // duplicates already stored
            'bad_lines' => $badLines,
            'punches'   => $saved > 0 ? $this->newRows($device, $rows, $lastId) : new Collection(),
        ];
    }

    /** The rows of this upload that were really inserted (duplicates are older rows). */
    private function newRows(Device $device, array $rows, int $lastId): Collection
    {
        $wanted = [];
        foreach ($rows as $row) {
            $wanted[$row['pin'] . '|' . $row['punched_at']] = true;
        }

        return Punch::where('device_id', $device->id)->where('id', '>', $lastId)
            ->whereIn('pin', array_unique(array_column($rows, 'pin')))
            ->orderBy('punched_at')->orderBy('id')->get()
            ->filter(function (Punch $p) use ($wanted) {
                return isset($wanted[$p->pin . '|' . $p->punched_at->format('Y-m-d H:i:s')]);
            })->values();
    }
}
