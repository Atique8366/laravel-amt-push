<?php

namespace BiometricPush\Adms\Console;

use Illuminate\Console\Command;
use BiometricPush\Adms\AdmsManager;

class DeviceCommand extends Command
{
    protected $signature = 'adms:command {serial : device serial number}
                            {action : restart | info | users | recollect | clear-attendance | raw}
                            {raw? : the PUSH command text for action "raw"}';

    protected $description = 'Queue a command for an ADMS device (it runs on the device\'s next poll)';

    public function handle(AdmsManager $adms)
    {
        $device = $adms->device((string) $this->argument('serial'));
        $action = (string) $this->argument('action');

        switch ($action) {
            case 'restart':
                $cmd = $device->restart();
                break;
            case 'info':
                $cmd = $device->refreshInfo();
                break;
            case 'users':
                $cmd = $device->refreshUsers();
                break;
            case 'recollect':
                $cmd = $device->recollectAttendance();
                break;
            case 'clear-attendance':
                if (!$this->confirm('This deletes every punch stored ON THE DEVICE. Continue?')) {
                    return 1;
                }
                $cmd = $device->clearAttendance();
                break;
            case 'raw':
                if (!$this->argument('raw')) {
                    $this->error('Give the command text, e.g. adms:command SN raw "SET OPTION Delay=30"');
                    return 1;
                }
                $cmd = $device->command((string) $this->argument('raw'));
                break;
            default:
                $this->error("Unknown action {$action}.");
                return 1;
        }

        $this->info("Command #{$cmd->id} queued ({$cmd->type}).");

        return 0;
    }
}
