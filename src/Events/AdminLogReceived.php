<?php

namespace BiometricPush\AmtPush\Events;

use BiometricPush\AmtPush\Models\AdminLog;
use BiometricPush\AmtPush\Models\Device;

/** An administrative action was done on the terminal (enrollment, deletion, menu access...). */
class AdminLogReceived
{
    /** @var Device */
    public $device;

    /** @var AdminLog */
    public $log;

    public function __construct(Device $device, AdminLog $log)
    {
        $this->device = $device;
        $this->log = $log;
    }
}
