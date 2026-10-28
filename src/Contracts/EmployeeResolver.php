<?php

namespace BiometricPush\Adms\Contracts;

use BiometricPush\Adms\Models\Device;

/**
 * Connects device users (PINs) to the people of your application.
 *
 * Bind your own implementation in config('adms.employees.resolver').
 */
interface EmployeeResolver
{
    /**
     * A user uploaded by a device: which employee is it?
     *
     * @return string|null employee id, or null to leave the device user unlinked
     */
    public function resolve(Device $device, string $pin, string $nameOnDevice): ?string;

    /**
     * What to write to the device for an employee (Adms::syncEmployee()).
     *
     * Return null when the employee must not be on this device. Keys:
     *   pin (required), name, card, password, privilege (0 user, 2 enroller, 6 admin, 14 super admin)
     * Keys left out keep the value the device already has.
     */
    public function deviceUser(Device $device, string $employeeId): ?array;
}
