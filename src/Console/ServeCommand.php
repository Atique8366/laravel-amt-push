<?php

namespace BiometricPush\AmtPush\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Server\Kernel;
use BiometricPush\AmtPush\Server\WorkermanSocket;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * The long-running WebSocket server the devices connect to.
 *
 *   php artisan amt:serve            foreground
 *   php artisan amt:serve start -d   daemon (Linux / macOS)
 *   php artisan amt:serve stop | restart | reload | status
 *
 * One worker process: live connections and the one-request-in-flight state live in its memory.
 */
class ServeCommand extends Command
{
    protected $signature = 'amt:serve {action=start : start|stop|restart|reload|status|connections}
                            {--d|daemon : run as a daemon (Linux / macOS)}';

    protected $description = 'Run the WebSocket server for AMT / M50 attendance devices';

    public function handle()
    {
        global $argv;
        $argv = [isset($argv[0]) ? $argv[0] : 'artisan', (string) $this->argument('action')];
        if ($this->option('daemon')) {
            $argv[] = '-d';
        }

        $c = config('amt-push.server');
        Worker::$pidFile = $c['pid_file'] ?: storage_path('framework/amt-push-server.pid');
        Worker::$logFile = $c['log_file'] ?: storage_path('logs/amt-push-server.log');

        $context = [];
        if (!empty($c['ssl'])) {
            $context['ssl'] = ['local_cert' => $c['ssl_cert'], 'local_pk' => $c['ssl_key'], 'verify_peer' => false];
        }
        $worker = new Worker('websocket://' . $c['host'] . ':' . (int) $c['port'], $context);
        if (!empty($c['ssl'])) {
            $worker->transport = 'ssl';
        }
        $worker->name = $c['process_name'];
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($c) {
            $this->onWorkerStart($c);
        };
        $worker->onConnect = function (TcpConnection $connection) {
            $this->kernel()->open(new WorkermanSocket($connection));
        };
        $worker->onMessage = function (TcpConnection $connection, $data) {
            $this->kernel()->message(new WorkermanSocket($connection), (string) $data);
        };
        $worker->onClose = function (TcpConnection $connection) {
            $this->kernel()->close(new WorkermanSocket($connection));
        };

        Worker::runAll();

        return 0;
    }

    private function onWorkerStart(array $c): void
    {
        try {
            DB::reconnect();   // don't share the parent's connection after the fork
            Device::where('is_online', true)->update(['is_online' => false, 'session_id' => null]);
        } catch (\Throwable $e) {
            report($e);
        }

        Timer::add(max(1, (int) $c['tick_seconds']), function () {
            try {
                $this->kernel()->tick();
            } catch (\Throwable $e) {
                report($e);
            }
        });
        Timer::add(30, function () use ($c) {
            $this->kernel()->sweep((int) $c['connection_timeout']);
        });
        Timer::add(max(10, (int) $c['db_heartbeat_seconds']), function () {
            try {
                DB::select('SELECT 1');
            } catch (\Throwable $e) {
                try {
                    DB::reconnect();
                } catch (\Throwable $inner) {
                    report($inner);
                }
            }
        });

        echo '[amt-push] listening on ' . (!empty($c['ssl']) ? 'wss' : 'ws') . '://' . $c['host'] . ':' . (int) $c['port'] . PHP_EOL;
    }

    private function kernel(): Kernel
    {
        return app(Kernel::class);
    }
}
