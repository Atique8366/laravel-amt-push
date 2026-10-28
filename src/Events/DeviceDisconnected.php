<?php

namespace BiometricPush\AmtPush\Events;

use BiometricPush\AmtPush\Models\Device;

/** The last connection of a device closed (or went silent). */
class DeviceDisconnected
{
    /** @var Device */
    public $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }
}
