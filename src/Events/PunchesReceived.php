<?php

namespace BiometricPush\AmtPush\Events;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Models\Device;

/** New punches were stored (duplicates excluded). $realtime: TimeLog_v2 event (true) or a log pull (false). */
class PunchesReceived
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\AmtPush\Models\Punch[] */
    public $punches;

    /** @var bool */
    public $realtime;

    public function __construct(Device $device, Collection $punches, bool $realtime)
    {
        $this->device = $device;
        $this->punches = $punches;
        $this->realtime = $realtime;
    }
}
