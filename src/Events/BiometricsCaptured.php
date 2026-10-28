<?php

namespace BiometricPush\Adms\Events;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Models\Device;

/** New or changed templates / photos were stored from a device upload. */
class BiometricsCaptured
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\Adms\Models\BiometricTemplate[] */
    public $templates;

    public function __construct(Device $device, Collection $templates)
    {
        $this->device = $device;
        $this->templates = $templates;
    }
}
