<?php

namespace BiometricPush\Adms\Console;

use Illuminate\Console\Command;
use BiometricPush\Adms\AdmsManager;

class ProcessCommand extends Command
{
    protected $signature = 'adms:process {serial? : one device only}';

    protected $description = 'Hand waiting punches to the configured PunchHandler';

    public function handle(AdmsManager $adms)
    {
        if (!config('adms.punches.handler')) {
            $this->warn('No punches.handler configured: nothing to process.');
            return 0;
        }
        foreach ($adms->processPunches($this->argument('serial')) as $serial => $result) {
            $this->line($serial . ': ' . $result['processed'] . ' processed ' . json_encode($result['results']));
        }

        return 0;
    }
}
