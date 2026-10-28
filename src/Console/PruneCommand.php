<?php

namespace BiometricPush\Adms\Console;

use Illuminate\Console\Command;
use BiometricPush\Adms\Models\Command as DeviceCommandModel;
use BiometricPush\Adms\Models\RequestLog;

class PruneCommand extends Command
{
    protected $signature = 'adms:prune {--days= : keep this many days (default request_log.keep_days)}
                            {--commands= : also delete finished commands older than this many days}';

    protected $description = 'Delete old ADMS request logs (and optionally finished commands)';

    public function handle()
    {
        $days = max(1, (int) ($this->option('days') ?: config('adms.request_log.keep_days', 7)));
        $logs = RequestLog::where('created_at', '<', now()->subDays($days))->delete();
        $this->info("{$logs} request log row(s) deleted.");

        if ($this->option('commands')) {
            $commands = DeviceCommandModel::whereNotIn('status', [DeviceCommandModel::STATUS_QUEUED, DeviceCommandModel::STATUS_SENT])
                ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('commands'))))->delete();
            $this->info("{$commands} finished command(s) deleted.");
        }

        return 0;
    }
}
