<?php

namespace BiometricPush\AmtPush\Events;

use BiometricPush\AmtPush\Models\Device;

/** An approved device registered or logged in. */
class DeviceConnected
{
    /** @var Device */
    public $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }
}
