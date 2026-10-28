<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use BiometricPush\AmtPush\AmtManager;

class ApproveCommand extends Command
{
    protected $signature = 'amt:approve {serial : device serial number}
                            {--block : block the device instead}
                            {--name= : device name}';

    protected $description = 'Approve (or block) an AMT device; registers it when it never connected';

    public function handle(AmtManager $amt)
    {
        $serial = (string) $this->argument('serial');
        $attributes = $this->option('name') ? ['name' => $this->option('name')] : [];

        if ($this->option('block')) {
            $amt->register($serial, $attributes, false)->block();
            $this->info("Device {$serial} blocked.");
        } else {
            $amt->register($serial, $attributes, true);
            $this->info("Device {$serial} approved. It is accepted at its next Register (within ~10 s).");
        }

        return 0;
    }
}
