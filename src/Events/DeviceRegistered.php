<?php

namespace BiometricPush\AmtPush\Events;

use BiometricPush\AmtPush\Models\Device;

/** An unknown serial number sent Register and was saved (pending unless auto-approved). */
class DeviceRegistered
{
    /** @var Device */
    public $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }
}
