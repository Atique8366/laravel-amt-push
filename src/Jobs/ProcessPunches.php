<?php

namespace BiometricPush\Adms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use BiometricPush\Adms\Models\Device;
use BiometricPush\Adms\Services\PunchProcessor;

/** punches.process = "queue": hands one device's waiting punches to the PunchHandler. */
class ProcessPunches implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /** @var int */
    public $deviceId;

    public function __construct(int $deviceId)
    {
        $this->deviceId = $deviceId;
    }

    public function handle(PunchProcessor $processor)
    {
        $device = Device::find($this->deviceId);
        if ($device && $device->process_punches) {
            $processor->processPending($device);
        }
    }
}
