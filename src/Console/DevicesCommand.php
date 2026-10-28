<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use BiometricPush\AmtPush\Models\Device;

class DevicesCommand extends Command
{
    protected $signature = 'amt:devices {--pending : only devices waiting for approval}';

    protected $description = 'List AMT / M50 devices';

    public function handle()
    {
        $devices = Device::query()->when($this->option('pending'), function ($q) {
            $q->where('registration_status', Device::STATUS_PENDING);
        })->orderBy('id')->get();

        $this->table(['ID', 'Serial', 'Name', 'Type', 'Status', 'Online', 'Read-only', 'Last LogID', 'Last seen'],
            $devices->map(function (Device $d) {
                return [
                    $d->id, $d->serial_number, $d->name, $d->terminal_type, $d->registration_status, $d->is_online ? 'yes' : 'no',
                    $d->read_only ? 'yes' : 'no', $d->last_log_id, $d->last_seen_at ? $d->last_seen_at->toDateTimeString() : '-',
                ];
            })->all());

        return 0;
    }
}
