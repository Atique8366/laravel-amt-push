<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use BiometricPush\AmtPush\AmtManager;

class DeviceCommand extends Command
{
    protected $signature = 'amt:command {serial : device serial number}
                            {action : sync-time | info | users | pull | backfill | restart | enable | disable | clear-attendance | raw}
                            {request? : request name for action "raw", e.g. GetTime}';

    protected $description = 'Queue a request for an AMT device (sent as soon as it is connected)';

    public function handle(AmtManager $amt)
    {
        $device = $amt->device((string) $this->argument('serial'));

        switch ((string) $this->argument('action')) {
            case 'sync-time':
                $result = $device->syncTime();
                break;
            case 'info':
                $result = $device->refreshInfo()->last();
                break;
            case 'users':
                $result = $device->refreshUsers();
                break;
            case 'pull':
            case 'backfill':
                $batch = $device->pullAttendance($this->argument('action') === 'backfill');
                $this->info($batch ? "Pull queued (batch {$batch})." : 'A pull is already running.');
                return 0;
            case 'restart':
                $result = $device->restart();
                break;
            case 'enable':
            case 'disable':
                $result = $device->enable($this->argument('action') === 'enable');
                break;
            case 'clear-attendance':
                if (!$this->confirm('This deletes every punch stored ON THE DEVICE (after pulling them). Continue?')) {
                    return 1;
                }
                $result = $device->clearAttendance();
                break;
            case 'raw':
                if (!$this->argument('request')) {
                    $this->error('Give the request name, e.g. amt:command SN raw GetTime');
                    return 1;
                }
                $result = $device->command((string) $this->argument('request'));
                break;
            default:
                $this->error('Unknown action ' . $this->argument('action') . '.');
                return 1;
        }

        $this->info("Request #{$result->id} queued ({$result->request}).");

        return 0;
    }
}
