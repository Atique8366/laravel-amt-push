<?php

namespace BiometricPush\Adms\Events;

use BiometricPush\Adms\Models\Command;
use BiometricPush\Adms\Models\Device;

/** The device answered a command, or it expired without an answer. Check $command->status. */
class CommandFinished
{
    /** @var Device */
    public $device;

    /** @var Command */
    public $command;

    /** @var array data lines the device returned with the result (e.g. INFO key=value lines) */
    public $payload;

    public function __construct(Device $device, Command $command, array $payload = [])
    {
        $this->device = $device;
        $this->command = $command;
        $this->payload = $payload;
    }
}
