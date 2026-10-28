<?php

namespace BiometricPush\Adms\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \BiometricPush\Adms\DeviceClient device(\BiometricPush\Adms\Models\Device|int|string $device)
 * @method static \BiometricPush\Adms\DeviceClient register(string $serialNumber, array $attributes = [], bool $approved = true)
 * @method static \Illuminate\Database\Eloquent\Builder devices()
 * @method static \Illuminate\Database\Eloquent\Builder punches()
 * @method static array syncEmployee($employeeId, $devices, array $options = [])
 * @method static array removeEmployee($employeeId, $devices = null, ?string $createdBy = null)
 * @method static array copyBiometrics($employeeId, $devices, bool $force = false, ?string $createdBy = null)
 * @method static \Illuminate\Support\Collection templates($employeeId)
 * @method static string|null photo($employeeId)
 * @method static array processPunches($device = null)
 * @method static \BiometricPush\Adms\Models\Command|null command(int $id)
 * @method static void routes()
 *
 * @see \BiometricPush\Adms\AdmsManager
 */
class Adms extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \BiometricPush\Adms\AdmsManager::class;
    }
}
