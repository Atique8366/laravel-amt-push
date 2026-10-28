<?php

namespace BiometricPush\Adms\Events;

use BiometricPush\Adms\Models\Device;

/** An unknown serial number connected and was saved (pending, unless auto-approved). */
class DeviceRegistered
{
    /** @var Device */
    public $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }
}
