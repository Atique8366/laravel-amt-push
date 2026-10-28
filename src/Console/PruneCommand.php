<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use BiometricPush\AmtPush\Models\Command as DeviceCommandModel;
use BiometricPush\AmtPush\Server\MessageLog;

class PruneCommand extends Command
{
    protected $signature = 'amt:prune {--days= : keep this many days of messages (default message_log.keep_days)}
                            {--commands= : also delete finished commands older than this many days}';

    protected $description = 'Delete old AMT message logs (and optionally finished commands)';

    public function handle(MessageLog $messages)
    {
        $deleted = 0;
        do {
            $batch = $messages->prune($this->option('days') ? (int) $this->option('days') : null);
            $deleted += $batch;
        } while ($batch >= 10000);
        $this->info("{$deleted} message(s) deleted.");

        if ($this->option('commands')) {
            $commands = DeviceCommandModel::whereNotIn('status', [DeviceCommandModel::STATUS_PENDING, DeviceCommandModel::STATUS_SENT])
                ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('commands'))))->delete();
            $this->info("{$commands} finished command(s) deleted.");
        }

        return 0;
    }
}
