<?php

namespace BiometricPush\AmtPush\Server;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use BiometricPush\AmtPush\AmtManager;
use BiometricPush\AmtPush\Events\AdminLogReceived;
use BiometricPush\AmtPush\Events\DeviceConnected;
use BiometricPush\AmtPush\Events\DeviceDisconnected;
use BiometricPush\AmtPush\Events\DeviceRegistered;
use BiometricPush\AmtPush\Events\PunchesReceived;
use BiometricPush\AmtPush\Models\AdminLog;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;
use BiometricPush\AmtPush\Protocol\DeviceTime;
use BiometricPush\AmtPush\Protocol\Frame;
use BiometricPush\AmtPush\Protocol\MessageBuilder;
use BiometricPush\AmtPush\Services\BiometricService;
use BiometricPush\AmtPush\Services\LogService;
use BiometricPush\AmtPush\Services\PunchProcessor;
use BiometricPush\AmtPush\Services\PunchService;
use BiometricPush\AmtPush\Services\ResultApplier;

/**
 * The device conversation, independent of the WebSocket library (the amt:serve command feeds it
 * from Workerman; tests feed it directly).
 *
 *   Register  → Token (approved devices only)          Login → OK / Fail / FailUnknownToken
 *   TimeLog_v2 → stored, acknowledged with TransID      AdminLog_v2 → stored, templates read back
 *   KeepAlive → ServerTime                              Response → the command in flight
 */
class Kernel
{
    /** @var Sessions */
    public $sessions;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var MessageLog */
    private $messages;

    /** @var int unix time of the last periodic job */
    private $lastPeriodic = 0;

    public function __construct(Sessions $sessions, Dispatcher $dispatcher, MessageLog $messages)
    {
        $this->sessions = $sessions;
        $this->dispatcher = $dispatcher;
        $this->messages = $messages;
    }

    // ── Connection lifecycle ─────────────────────────────────────────────

    public function open(Socket $socket): Session
    {
        $session = new Session($socket);
        $this->sessions->add($session);

        return $session;
    }

    public function message(Socket $socket, string $raw): void
    {
        $session = $this->sessions->find($socket->id()) ?: $this->open($socket);
        $session->touch();

        $frame = Frame::parse($raw);
        if (!$frame) {
            Log::warning('[amt-push] frame is not XML', ['raw' => mb_substr($raw, 0, 300)]);
            return;
        }
        if ($session->serialNumber === null && $frame->get('DeviceSerialNo') !== '') {
            $session->serialNumber = mb_substr($frame->get('DeviceSerialNo'), 0, 64);
        }
        $this->messages->write($session, 'in', $frame->name(), $raw);

        try {
            switch ($frame->type()) {
                case 'request':
                    $this->request($session, $frame);
                    break;
                case 'event':
                    $this->event($session, $frame);
                    break;
                case 'response':
                    $this->response($session, $frame);
                    break;
                default:
                    Log::info('[amt-push] frame without Request / Event / Response', ['sn' => $session->serialNumber]);
            }
        } catch (\Throwable $e) {
            Log::error('[amt-push] handling ' . $frame->name() . ' failed', ['sn' => $session->serialNumber, 'error' => $e->getMessage()]);
            report($e);
        }

        // an answer frees the device: send its next command right away
        if (!$session->pending && $session->device) {
            try {
                $cmd = $this->dispatcher->nextFor($session->device->fresh());
                if ($cmd) {
                    $this->dispatcher->send($session, $cmd);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function close(Socket $socket): void
    {
        $session = $this->sessions->find($socket->id());
        if (!$session) {
            return;
        }
        $this->sessions->remove($session);
        if ($session->pending) {
            // the answer can't come any more: retry later (or fail when out of attempts)
            $cmd = $session->pending->fresh();
            if ($cmd && $cmd->status === Command::STATUS_SENT) {
                $cmd->markFailed('connection closed before the answer');
                if ($cmd->isFinished()) {
                    app(ResultApplier::class)->afterFinished($session->device, $cmd, null);
                }
            }
            $session->clearPending();
        }
        if ($session->device && !$this->sessions->forDevice((int) $session->device->id)) {
            Device::whereKey($session->device->id)->update(['is_online' => false, 'session_id' => null]);
            event(new DeviceDisconnected($session->device));
        }
    }

    /** Called every server tick: timeouts, queued commands, periodic pulls, pruning. */
    public function tick(): int
    {
        $sent = $this->dispatcher->tick($this->sessions);

        if (time() - $this->lastPeriodic >= 60) {
            $this->lastPeriodic = time();
            $this->periodicPulls();
            try {
                $this->messages->prune();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /** Close sockets silent for longer than $seconds. */
    public function sweep(int $seconds): int
    {
        $closed = 0;
        foreach ($this->sessions->all() as $session) {
            if ($session->lastAliveAt < time() - $seconds) {
                try {
                    $session->socket->close();
                } catch (\Throwable $e) {
                }
                $this->close($session->socket);
                $closed++;
            }
        }

        return $closed;
    }

    // ── Requests ─────────────────────────────────────────────────────────

    private function request(Session $session, Frame $frame): void
    {
        switch ($frame->get('Request')) {
            case 'Register':
                $this->register($session, $frame);
                break;
            case 'Login':
                $this->login($session, $frame);
                break;
            default:
                $this->send($session, MessageBuilder::response('ErrorReport')->add('Error', 'UnknownRequest')->toXml(), 'ErrorReport');
        }
    }

    private function register(Session $session, Frame $frame): void
    {
        $sn = mb_substr($frame->get('DeviceSerialNo'), 0, 64);
        $reply = function ($result, $token = null) use ($session, $sn) {
            $this->send($session, MessageBuilder::response('Register')->add('DeviceSerialNo', $sn)
                ->add('Token', $token)->add('Result', $result)->toXml(), 'Register');
        };
        if ($sn === '' || !$this->cloudIdAccepted($frame)) {
            $reply('Fail');
            return;
        }

        $device = Device::where('serial_number', $sn)->first();
        if (!$device) {
            $approved = config('amt-push.devices.auto_approve', false)
                || in_array($sn, (array) config('amt-push.devices.approved_serials', []), true);
            if (!$approved && !config('amt-push.devices.auto_register', true)) {
                $reply('Fail');
                return;
            }
            $device = Device::create(array_merge(AmtManager::newDeviceDefaults($sn), [
                'registration_status' => $approved ? Device::STATUS_APPROVED : Device::STATUS_PENDING,
            ]));
            $this->safely(function () use ($device) {
                event(new DeviceRegistered($device));
            });
        }

        $device->fill($this->reportedInfo($frame) + ['last_ip' => $session->socket->remoteIp(), 'last_seen_at' => now()]);
        if (!$device->isApproved()) {
            $device->save();
            $reply('Fail');   // the device retries every ~10 s; it is served once approved
            return;
        }

        // A fresh token on every Register: a reset device must not keep its old credential.
        $session->id = (string) \Illuminate\Support\Str::uuid();
        $device->fill(['token' => $session->id, 'registered_at' => now()]);
        $this->bind($session, $device);
        $reply('OK', $session->id);
        $this->afterConnect($device);
    }

    private function login(Session $session, Frame $frame): void
    {
        $sn = mb_substr($frame->get('DeviceSerialNo'), 0, 64);
        $token = $frame->get('Token');
        $reply = function ($result) use ($session, $sn) {
            $this->send($session, MessageBuilder::response('Login')->add('DeviceSerialNo', $sn)->add('Result', $result)->toXml(), 'Login');
        };

        $device = $token !== '' ? Device::where('token', $token)->first() : null;
        if (!$device) {
            $reply('FailUnknownToken');   // the device registers again
            return;
        }
        if (($sn !== '' && $device->serial_number !== $sn) || !$device->isApproved() || !$this->cloudIdAccepted($frame)) {
            Log::warning('[amt-push] login refused', ['sn' => $sn, 'device' => $device->serial_number, 'status' => $device->registration_status]);
            $reply('Fail');
            return;
        }

        $session->id = $token;
        $device->fill($this->reportedInfo($frame) + ['last_ip' => $session->socket->remoteIp(), 'last_seen_at' => now()]);
        $this->bind($session, $device);
        $reply('OK');
        $this->afterConnect($device);
    }

    private function bind(Session $session, Device $device): void
    {
        $device->fill(['is_online' => true, 'session_id' => $session->id])->save();
        $session->device = $device;
        $session->serialNumber = $device->serial_number;

        foreach ($this->sessions->duplicatesOf($session) as $old) {   // rebooted without closing its socket
            $this->sessions->remove($old);
            try {
                $old->socket->close();
            } catch (\Throwable $e) {
            }
        }
        $this->safely(function () use ($device) {
            event(new DeviceConnected($device));
        });
    }

    private function afterConnect(Device $device): void
    {
        $this->safely(function () use ($device) {
            $queue = app(\BiometricPush\AmtPush\Services\CommandQueue::class);
            if (config('amt-push.devices.set_time_on_connect', true) && !$device->isReadOnly()
                && !$queue->waiting($device, 'SetTime', [])) {
                $queue->queue($device, 'SetTime', [], ['source' => 'auto']);
            }
            if (config('amt-push.devices.pull_on_connect', true)) {
                app(LogService::class)->pullIfKnown($device);
            }
        });
    }

    // ── Events ───────────────────────────────────────────────────────────

    private function event(Session $session, Frame $frame): void
    {
        $name = $frame->get('Event');
        if ($name === 'KeepAlive') {
            $this->keepAlive($session, $frame);
            return;
        }

        $device = $session->device ?: $this->bindBySerial($session, $frame);
        if (!$device) {
            $this->send($session, MessageBuilder::response('ErrorReport')->add('Error', 'DeviceNotRegistered')->toXml(), 'ErrorReport');
            return;
        }

        switch ($name) {
            case 'TimeLog_v2':
            case 'TimeLog':
                $this->timeLog($session, $device, $frame, $name);
                break;
            case 'AdminLog_v2':
            case 'AdminLog':
                $this->adminLog($session, $device, $frame, $name);
                break;
            default:
                Log::info('[amt-push] unhandled event ' . $name, ['sn' => $device->serial_number]);
        }
    }

    private function timeLog(Session $session, Device $device, Frame $frame, string $name): void
    {
        try {
            list($punch, $isNew) = app(PunchService::class)->storeFrame($device, $frame, 'push');
        } catch (\Throwable $e) {
            report($e);
            return;   // not acknowledged: the device sends it again
        }

        $this->send($session, MessageBuilder::response($name)->add('TransID', $frame->opt('TransID'))
            ->add('UserID', $frame->opt('UserID'))->add('Result', 'OK')->toXml(), $name);

        if ($punch && $isNew) {
            $this->safely(function () use ($device, $punch) {
                event(new PunchesReceived($device->fresh(), new Collection([$punch]), true));
            });
            app(PunchProcessor::class)->dispatch($device->fresh());
        }
    }

    private function adminLog(Session $session, Device $device, Frame $frame, string $name): void
    {
        $time = DeviceTime::parse($frame->get('Time'));
        $log = AdminLog::create([
            'device_id'   => $device->id,
            'log_epoch'   => (int) $device->log_epoch,
            'log_id'      => $frame->int('LogID'),
            'admin_id'    => $frame->opt('AdminID'),
            'user_id'     => $frame->opt('UserID'),
            'action'      => $frame->opt('Action') !== null ? mb_substr($frame->opt('Action'), 0, 64) : null,
            'stat'        => $frame->opt('Stat'),
            'occurred_at' => $time ? $time->format('Y-m-d H:i:s') : null,
        ]);

        $this->send($session, MessageBuilder::response($name)->add('TransID', $frame->opt('TransID'))->add('Result', 'OK')->toXml(), $name);

        $this->safely(function () use ($device, $log) {
            event(new AdminLogReceived($device, $log));
            app(BiometricService::class)->onAdminLog($device, $log);
        });
    }

    private function keepAlive(Session $session, Frame $frame): void
    {
        if ($session->device && (!$session->device->last_seen_at || $session->device->last_seen_at->lt(now()->subSeconds(30)))) {
            Device::whereKey($session->device->id)->update(['last_seen_at' => now(), 'is_online' => true]);
            $session->device->last_seen_at = now();
            $session->device->syncOriginalAttribute('last_seen_at');
        }
        $tz = $session->device ? $session->device->clockTimezone() : (config('app.timezone') ?: 'UTC');
        $this->send($session, MessageBuilder::response('KeepAlive')->add('Result', 'OK')->add('DevTime', $frame->opt('DevTime'))
            ->add('ServerTime', DeviceTime::format(now($tz)))->toXml(), 'KeepAlive');
    }

    // ── Responses ────────────────────────────────────────────────────────

    private function response(Session $session, Frame $frame): void
    {
        $cmd = $session->pending ? $session->pending->fresh() : null;
        if (!$cmd || $cmd->status !== Command::STATUS_SENT) {
            $session->clearPending();
            Log::info('[amt-push] answer with nothing in flight', ['sn' => $session->serialNumber, 'response' => $frame->get('Response')]);
            return;
        }
        // a late answer to an earlier (timed-out) request must not settle the current one
        $name = $frame->get('Response');
        if ($name !== '' && strcasecmp($name, $cmd->request) !== 0 && strcasecmp($name, 'ErrorReport') !== 0) {
            Log::info('[amt-push] answer does not match the request in flight', ['sent' => $cmd->request, 'received' => $name]);
            return;
        }

        $session->clearPending();
        app(ResultApplier::class)->onResponse($session->device->fresh(), $cmd, $frame);
    }

    // ─────────────────────────────────────────────────────────────────────

    /** An event on a socket that never logged in (e.g. after a server restart): accept approved serials. */
    private function bindBySerial(Session $session, Frame $frame): ?Device
    {
        $sn = $frame->get('DeviceSerialNo');
        $device = $sn !== '' ? Device::where('serial_number', $sn)->first() : null;
        if (!$device || !$device->isApproved()) {
            return null;
        }
        $device->fill(['last_ip' => $session->socket->remoteIp(), 'last_seen_at' => now()]);
        $this->bind($session, $device);

        return $device;
    }

    private function cloudIdAccepted(Frame $frame): bool
    {
        $required = config('amt-push.devices.cloud_id');

        return $required === null || $required === '' || hash_equals((string) $required, $frame->get('CloudId'));
    }

    private function reportedInfo(Frame $frame): array
    {
        return array_filter([
            'cloud_id'         => $frame->opt('CloudId'),
            'terminal_type'    => $frame->opt('TerminalType') !== null ? mb_substr($frame->opt('TerminalType'), 0, 32) : null,
            'terminal_id'      => $frame->int('TerminalID'),
            'product_name'     => $frame->opt('ProductName') !== null ? mb_substr($frame->opt('ProductName'), 0, 100) : null,
            'firmware_version' => $frame->opt('FirmwareVersion') !== null ? mb_substr($frame->opt('FirmwareVersion'), 0, 100) : null,
        ], function ($v) {
            return $v !== null;
        });
    }

    private function send(Session $session, string $xml, string $kind): void
    {
        $session->socket->send($xml);
        $this->messages->write($session, 'out', $kind, $xml);
    }

    private function periodicPulls(): void
    {
        $minutes = (int) config('amt-push.devices.pull_every_minutes', 0);
        if ($minutes <= 0) {
            return;
        }
        foreach ($this->sessions->all() as $session) {
            $device = $session->device ? $session->device->fresh() : null;
            if ($device && (!$device->last_pull_at || $device->last_pull_at->lt(now()->subMinutes($minutes)))) {
                $this->safely(function () use ($device) {
                    app(LogService::class)->pullIfKnown($device);
                    $device->forceFill(['last_pull_at' => now()])->save();
                });
            }
        }
    }

    private function safely(\Closure $task): void
    {
        try {
            $task();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
