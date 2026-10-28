<?php

namespace BiometricPush\AmtPush\Contracts;

use BiometricPush\AmtPush\Models\Device;

/**
 * Connects device UserIDs to the people of your application.
 * Bind your own implementation in config('amt-push.employees.resolver').
 */
interface EmployeeResolver
{
    /**
     * A UserID seen on a device (punch, user read): which employee is it?
     *
     * @return string|null employee id, or null to leave it unlinked
     */
    public function resolve(Device $device, string $userId, string $nameOnDevice): ?string;

    /**
     * What to write to the device for an employee (Amt::syncEmployee()), or null when they must
     * not be on this device. Keys: user_id (required, 1-99999999), name, privilege
     * (User | Manager | Administrator), department, enabled, password, card, qr, timesets, period.
     * Keys left out keep the device's current value.
     */
    public function deviceUser(Device $device, string $employeeId): ?array;
}
