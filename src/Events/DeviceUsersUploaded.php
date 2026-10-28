<?php

namespace BiometricPush\Adms\Events;

use Illuminate\Support\Collection;
use BiometricPush\Adms\Models\Device;

/** A device uploaded user records (after enrollment, an edit on the device, or a user query). */
class DeviceUsersUploaded
{
    /** @var Device */
    public $device;

    /** @var Collection|\BiometricPush\Adms\Models\DeviceUser[] */
    public $users;

    public function __construct(Device $device, Collection $users)
    {
        $this->device = $device;
        $this->users = $users;
    }
}
