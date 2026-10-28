<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use BiometricPush\AmtPush\AmtManager;

class ProcessCommand extends Command
{
    protected $signature = 'amt:process {serial? : one device only}';

    protected $description = 'Hand waiting AMT punches to the configured PunchHandler';

    public function handle(AmtManager $amt)
    {
        if (!config('amt-push.punches.handler')) {
            $this->warn('No punches.handler configured: nothing to process.');
            return 0;
        }
        foreach ($amt->processPunches($this->argument('serial')) as $serial => $result) {
            $this->line($serial . ': ' . $result['processed'] . ' processed ' . json_encode($result['results']));
        }

        return 0;
    }
}
