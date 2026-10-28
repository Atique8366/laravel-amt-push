<?php

namespace BiometricPush\AmtPush\Server;

use Illuminate\Support\Str;
use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;

/** Live state of one device connection (held in the server process). */
class Session
{
    /** @var Socket */
    public $socket;

    /** @var string our id for this connection; given to the device as its Token on Register */
    public $id;

    /** @var Device|null set once Register / Login succeeded */
    public $device;

    /** @var string|null */
    public $serialNumber;

    /** @var int unix time of the last frame from the device */
    public $lastAliveAt;

    /** @var Command|null the request in flight: the protocol has no correlation id, so one at a time */
    public $pending;

    /** @var int unix time the pending request was sent */
    public $pendingSince = 0;

    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
        $this->id = (string) Str::uuid();
        $this->lastAliveAt = time();
    }

    public function touch(): void
    {
        $this->lastAliveAt = time();
    }

    public function isAuthenticated(): bool
    {
        return $this->device !== null;
    }

    public function clearPending(): void
    {
        $this->pending = null;
        $this->pendingSince = 0;
    }
}
