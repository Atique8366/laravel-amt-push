<?php

namespace BiometricPush\Adms\Console;

use Illuminate\Console\Command;
use BiometricPush\Adms\AdmsManager;

class ApproveCommand extends Command
{
    protected $signature = 'adms:approve {serial : device serial number}
                            {--block : block the device instead}
                            {--name= : device name}';

    protected $description = 'Approve (or block) an ADMS device; registers it when it never connected';

    public function handle(AdmsManager $adms)
    {
        $serial = (string) $this->argument('serial');
        $attributes = $this->option('name') ? ['name' => $this->option('name')] : [];

        if ($this->option('block')) {
            $adms->register($serial, $attributes, false)->block();
            $this->info("Device {$serial} blocked.");
        } else {
            $adms->register($serial, $attributes, true);
            $this->info("Device {$serial} approved. It is served on its next connection.");
        }

        return 0;
    }
}
