<?php

namespace BiometricPush\Adms\Console;

use Illuminate\Console\Command;
use BiometricPush\Adms\Models\Device;

class DevicesCommand extends Command
{
    protected $signature = 'adms:devices {--pending : only devices waiting for approval}';

    protected $description = 'List ADMS / PUSH devices';

    public function handle()
    {
        $devices = Device::query()
            ->when($this->option('pending'), function ($q) {
                $q->where('registration_status', Device::STATUS_PENDING);
            })->orderBy('id')->get();

        $this->table(
            ['ID', 'Serial', 'Name', 'Status', 'Online', 'Read-only', 'Users', 'Punches (device)', 'Last seen'],
            $devices->map(function (Device $d) {
                return [
                    $d->id, $d->serial_number, $d->name, $d->registration_status, $d->isOnline() ? 'yes' : 'no',
                    $d->read_only ? 'yes' : 'no', $d->user_count, $d->attlog_count,
                    $d->last_seen_at ? $d->last_seen_at->toDateTimeString() : '-',
                ];
            })->all()
        );

        return 0;
    }
}
