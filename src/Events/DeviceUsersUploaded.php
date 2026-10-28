<?php

namespace BiometricPush\AmtPush\Events;

use Illuminate\Support\Collection;
use BiometricPush\AmtPush\Models\Device;

/** User records were read from a device (GetUserData / user listing). */
class DeviceUsersUploaded
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\AmtPush\Models\DeviceUser[] */
    public $users;

    public function __construct(Device $device, Collection $users)
    {
        $this->device = $device;
        $this->users = $users;
    }
}
