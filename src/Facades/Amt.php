<?php

namespace BiometricPush\AmtPush\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \BiometricPush\AmtPush\DeviceClient device(\BiometricPush\AmtPush\Models\Device|int|string $device)
 * @method static \BiometricPush\AmtPush\DeviceClient register(string $serialNumber, array $attributes = [], bool $approved = true)
 * @method static \Illuminate\Database\Eloquent\Builder devices()
 * @method static \Illuminate\Database\Eloquent\Builder punches()
 * @method static array syncEmployee($employeeId, $devices, array $options = [])
 * @method static array copyBiometrics($employeeId, $devices, bool $force = false, ?string $createdBy = null)
 * @method static array removeEmployee($employeeId, $devices = null, ?string $createdBy = null)
 * @method static \Illuminate\Support\Collection templates($employeeId)
 * @method static string|null photo($employeeId)
 * @method static array processPunches($device = null)
 * @method static \BiometricPush\AmtPush\Models\Command|null command(int $id)
 *
 * @see \BiometricPush\AmtPush\AmtManager
 */
class Amt extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \BiometricPush\AmtPush\AmtManager::class;
    }
}
