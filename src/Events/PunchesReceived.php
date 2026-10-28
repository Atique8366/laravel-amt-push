<?php

namespace BiometricPush\Adms\Events;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Models\Device;

/** New punches were stored for a device (duplicates are not included). */
class PunchesReceived
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\Adms\Models\Punch[] */
    public $punches;

    public function __construct(Device $device, Collection $punches)
    {
        $this->device = $device;
        $this->punches = $punches;
    }
}
