<?php

namespace BiometricPush\Adms\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use BiometricPush\Adms\AdmsManager;
use BiometricPush\Adms\Events\DeviceRegistered;
use BiometricPush\Adms\Events\PunchesReceived;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Models\RequestLog;
use BiometricPush\Adms\Services\BiometricService;
use BiometricPush\Adms\Services\CommandService;
use BiometricPush\Adms\Services\DeviceInfoService;
use BiometricPush\Adms\Services\PunchProcessor;
use BiometricPush\Adms\Services\PunchService;
use BiometricPush\Adms\Services\UserService;
use BiometricPush\Adms\Support\Protocol;

/**
 * Device-facing endpoints of the ZKTeco ADMS / PUSH (iclock) protocol.
 *
 * The device is the client: it calls these URLs at the root of the server address / port
 * configured in its "Cloud Server / ADMS" menu. Replies are plain text, as the firmware expects.
 */
class IclockController extends Controller
{
    /** Request-log fields gathered while handling the current request. */
    private $log = [];

    /** Routine polls with nothing to report are not logged. */
    private $skipLog = false;

    /** GET = handshake / misc, POST = data upload. */
    public function cdata(Request $request)
    {
        return $this->handle($request, function (Device $device) use ($request) {
            if ($request->isMethod('get')) {
                return $this->handshake($request, $device);
            }

            return $this->upload($request, $device);
        });
    }

    /** Device polls for commands (and reports INFO counters). */
    public function getrequest(Request $request)
    {
        return $this->handle($request, function (Device $device) use ($request) {
            $notable = false;

            $info = Protocol::param($request, 'INFO');
            if ($info !== null && $info !== '') {
                $device->fill(Protocol::parseInfo($info));
                if ($device->isDirty()) {
                    $notable = true;   // counters / firmware changed
                }
                $device->save();
            }

            $this->pruneRequestLogs($device);

            // leftover punches (after a failure or a reprocess request), handled on a poll
            if (config('adms.punches.process') === 'sync' && $device->process_punches && config('adms.punches.handler')
                && $device->punches()->whereNull('processed_at')->exists()) {
                $result = app(PunchProcessor::class)->processPending($device->fresh(), 50);
                $notable = $notable || $result['processed'] > 0;
            }

            $delivery = app(CommandService::class)->deliver($device);
            if ($delivery['count'] > 0) {
                $notable = true;
                $this->log['records_parsed'] = $delivery['count'];
            }

            $this->skipLog = !$notable && config('adms.request_log.skip_idle_polls', true);

            return $this->text($delivery['body']);
        });
    }

    /** Device returns command results (ID=..&Return=..&CMD=..). */
    public function devicecmd(Request $request)
    {
        return $this->handle($request, function (Device $device) use ($request) {
            $this->log['records_parsed'] = app(CommandService::class)->record($device, $request->getContent());

            return $this->text("OK\n");
        });
    }

    /** Keep-alive during long uploads. */
    public function ping(Request $request)
    {
        return $this->handle($request, function () {
            return $this->text("OK\n");
        }, false);
    }

    /** Any other /iclock/* path: logged, so unexpected firmware behaviour is visible. */
    public function fallback(Request $request)
    {
        return $this->handle($request, function () {
            return $this->text("OK\n");
        }, false);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function handshake(Request $request, Device $device)
    {
        if (strcasecmp((string) Protocol::param($request, 'options'), 'all') !== 0) {
            return $this->text("OK\n");
        }

        $pushver = Protocol::param($request, 'pushver');
        $language = Protocol::param($request, 'language');
        if ($pushver || $language) {
            $device->fill(array_filter([
                'push_version' => $pushver ? mb_substr($pushver, 0, 20) : null,
                'language'     => $language ? mb_substr($language, 0, 10) : null,
            ]))->save();
        }

        return $this->text(Protocol::handshakeOptions($device));
    }

    private function upload(Request $request, Device $device)
    {
        $table = strtoupper((string) Protocol::param($request, 'table'));
        $stamp = Protocol::param($request, 'Stamp');
        if ($stamp === null) {
            $stamp = Protocol::param($request, 'OpStamp');
        }
        $body = $request->getContent();

        switch ($table) {
            case 'ATTLOG':
                try {
                    $result = app(PunchService::class)->storeAttlog($device, $body, $stamp);
                } catch (\Throwable $e) {
                    // nothing saved, stamp unchanged → the device re-sends later
                    $this->log['error'] = 'ATTLOG save failed: ' . $e->getMessage();
                    Log::error('[adms] ATTLOG save failed', ['sn' => $device->serial_number, 'error' => $e->getMessage()]);

                    return $this->text("ERROR\n", 500);
                }
                $this->log['records_parsed'] = $result['parsed'];
                $this->log['records_saved'] = $result['saved'];
                if ($result['bad_lines']) {
                    $this->log['error'] = count($result['bad_lines']) . ' unreadable line(s): '
                        . implode(' | ', array_slice($result['bad_lines'], 0, 5));
                }
                if ($result['saved'] > 0) {
                    $this->safely('PunchesReceived listener', function () use ($device, $result) {
                        event(new PunchesReceived($device, $result['punches']));
                    });
                    app(PunchProcessor::class)->dispatch($device->fresh());
                }

                return $this->text("OK\n");

            case 'OPTIONS':
                $this->log['records_parsed'] = app(DeviceInfoService::class)->applyOptions($device, $body);

                return $this->text("OK\n");

            case 'OPERLOG':
            case 'USERINFO':
            case 'USER':
                // USER lines → device users; FP / FACE / BIODATA lines → templates;
                // OPLOG enrollment events → that person's templates are read back
                $lines = Protocol::lines($body);
                $this->log['records_parsed'] = count($lines);
                $this->safely('user import', function () use ($device, $lines) {
                    $this->log['records_saved'] = app(UserService::class)->importUserLines($device, $lines)->count();
                });
                $this->captureBiometrics($device, $lines, $table, true);
                if ($table === 'OPERLOG') {
                    $this->saveStamp($device, 'operlog_stamp', $stamp);
                }

                return $this->text("OK\n");

            case 'ATTPHOTO':
                $this->saveStamp($device, 'attphoto_stamp', $stamp);

                return $this->text("OK\n");

            case 'ERRORLOG':
                $this->saveStamp($device, 'errorlog_stamp', $stamp);
                $this->log['records_parsed'] = count(Protocol::lines($body));

                return $this->text("OK\n");

            case 'BIODATA':
            case 'FINGERTMP':
            case 'FACE':
            case 'USERPIC':   // "USER PIN=..\tFileName=..\tContent=.." here: a photo, never a user record
                $lines = Protocol::lines($body);
                $this->log['records_parsed'] = count($lines);
                $this->captureBiometrics($device, $lines, $table, false);

                return $this->text("OK\n");

            default:
                // BIOPHOTO and unknown tables: logged only
                $this->log['records_parsed'] = count(Protocol::lines($body));

                return $this->text("OK\n");
        }
    }

    /** Store uploaded templates (+ read back after an enrollment). Never fails the upload. */
    private function captureBiometrics(Device $device, array $lines, string $table, bool $withOplog): void
    {
        if (!$device->capture_biometrics || !config('adms.biometrics.capture', true)) {
            return;
        }
        $this->safely('biometric capture', function () use ($device, $lines, $table, $withOplog) {
            $bio = app(BiometricService::class);
            $counts = $bio->captureLines($device, $lines, $table);
            if ($counts['stored'] + $counts['updated'] > 0) {
                $this->log['records_saved'] = (int) (isset($this->log['records_saved']) ? $this->log['records_saved'] : 0)
                    + $counts['stored'] + $counts['updated'];
            }
            if ($withOplog && config('adms.biometrics.read_after_enroll', true) && !$device->isReadOnly()) {
                $bio->onOperationLog($device, $lines);
            }
        });
    }

    /** Run a side task; a failure is logged and never breaks the device conversation. */
    private function safely(string $what, \Closure $task): void
    {
        try {
            $task();
        } catch (\Throwable $e) {
            $this->log['error'] = trim((isset($this->log['error']) ? $this->log['error'] : '') . ' ' . $what . ' failed: ' . $e->getMessage());
            Log::error('[adms] ' . $what . ' failed', ['error' => $e->getMessage()]);
        }
    }

    private function saveStamp(Device $device, string $column, ?string $stamp): void
    {
        if ($stamp !== null && ctype_digit($stamp)) {
            Device::whereKey($device->id)->update([$column => $stamp]);
        }
    }

    /** About once an hour per device: drop request-log rows older than request_log.keep_days. */
    private function pruneRequestLogs(Device $device): void
    {
        if ($device->last_log_prune_at && $device->last_log_prune_at->gt(now()->subHour())) {
            return;
        }
        try {
            RequestLog::where('device_id', $device->id)
                ->where('created_at', '<', now()->subDays(max(1, (int) config('adms.request_log.keep_days', 7))))
                ->limit(5000)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('[adms] request log prune failed', ['error' => $e->getMessage()]);
        }
        $device->forceFill(['last_log_prune_at' => now()])->save();
    }

    /**
     * Common flow: identify the device by SN, register unknown ones, refuse unapproved
     * ones, run the handler, log request + reply.
     */
    private function handle(Request $request, \Closure $handler, bool $requireApproved = true)
    {
        $started = microtime(true);
        $this->skipLog = false;
        $sn = Protocol::param($request, 'SN');
        $body = $request->getContent();
        $table = Protocol::param($request, 'table');
        $this->log = [
            'serial_number' => $sn !== null && $sn !== '' ? mb_substr($sn, 0, 64) : null,
            'method'        => $request->method(),
            'path'          => mb_substr('/' . ltrim($request->path(), '/'), 0, 191),
            'query_string'  => $request->getQueryString(),
            'table_name'    => $table ? mb_substr($table, 0, 32) : null,
            'body'          => Protocol::bodyForLog($body),
            'body_size'     => strlen($body),
            'ip'            => $request->ip(),
        ];

        try {
            if (!$this->log['serial_number']) {
                $response = $requireApproved ? $this->text("Missing SN\n", 400) : $handler(new Device());
            } else {
                $device = $this->resolveDevice($this->log['serial_number'], $request->ip());
                if (!$device) {
                    $response = $this->text("Device Unknown\n", 401);
                } else {
                    $this->log['device_id'] = $device->id;
                    $response = $requireApproved && !$device->canPush()
                        ? $this->text("Device Unauthorized\n", 401)
                        : $handler($device);
                }
            }
        } catch (\Throwable $e) {
            $this->skipLog = false;
            $this->log['error'] = $e->getMessage();
            Log::error('[adms] request failed', ['sn' => $sn, 'path' => $request->path(), 'error' => $e->getMessage()]);
            $response = $this->text("ERROR\n", 500);
        }

        if (!$this->skipLog && config('adms.request_log.enabled', true)) {
            $this->writeLog($response, $started);
        }

        return $response;
    }

    /** Find by SN (registering a new SN when allowed) and record the check-in. */
    private function resolveDevice(string $sn, ?string $ip): ?Device
    {
        $device = Device::where('serial_number', $sn)->first();

        if (!$device) {
            $approved = config('adms.devices.auto_approve', false)
                || in_array($sn, (array) config('adms.devices.approved_serials', []), true);
            if (!$approved && !config('adms.devices.auto_register', true)) {
                return null;
            }
            try {
                $device = Device::create(array_merge(AdmsManager::newDeviceDefaults($sn), [
                    'registration_status' => $approved ? Device::STATUS_APPROVED : Device::STATUS_PENDING,
                ]));
                $this->safely('DeviceRegistered listener', function () use ($device) {
                    event(new DeviceRegistered($device));
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // two first requests at once: the other one created it
                $device = Device::where('serial_number', $sn)->firstOrFail();
            }
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->save();

        return $device;
    }

    private function text(string $body, int $code = 200)
    {
        return response($body, $code)
            ->header('Content-Type', 'text/plain')
            ->header('Date', gmdate('D, d M Y H:i:s') . ' GMT');
    }

    private function writeLog($response, float $started): void
    {
        try {
            RequestLog::create(array_filter($this->log, function ($v) {
                return $v !== null;
            }) + [
                'response_code' => $response->getStatusCode(),
                'response_body' => mb_substr((string) $response->getContent(), 0, 5000),
                'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            // logging must never break the device conversation
            Log::warning('[adms] request log write failed', ['error' => $e->getMessage()]);
        }
    }
}
