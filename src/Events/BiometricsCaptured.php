<?php

namespace BiometricPush\AmtPush\Events;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Models\Device;

/** New or changed templates / photos were read from a device. */
class BiometricsCaptured
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\AmtPush\Models\Biometric[] */
    public $templates;

    public function __construct(Device $device, Collection $templates)
    {
        $this->device = $device;
        $this->templates = $templates;
    }
}
