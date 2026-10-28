<?php

namespace BiometricPush\AmtPush\Events;

use BiometricPush\AmtPush\Models\Command;
use BiometricPush\AmtPush\Models\Device;

/** A command succeeded, failed, timed out or was cancelled. Check $command->status. */
class CommandFinished
{
    /** @var Device */
    public $device;

    /** @var Command */
    public $command;

    /** @var array tags of the device answer (empty when there was none) */
    public $response;

    public function __construct(Device $device, Command $command, array $response = [])
    {
        $this->device = $device;
        $this->command = $command;
        $this->response = $response;
    }
}
