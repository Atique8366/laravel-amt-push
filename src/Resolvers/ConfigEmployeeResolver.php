<?php

namespace BiometricPush\Adms\Resolvers;

use BiometricPush\Adms\Contracts\EmployeeResolver;
use BiometricPush\Adms\Models\Device;

/**
 * Built-in resolver driven by config('adms.employees'):
 *   mode "none"  never links;
 *   mode "pin"   the device PIN is the employee id;
 *   mode "model" the PIN is looked up in model.pin_column (exactly one match links).
 */
class ConfigEmployeeResolver implements EmployeeResolver
{
    public function resolve(Device $device, string $pin, string $nameOnDevice): ?string
    {
        $mode = config('adms.employees.mode', 'none');

        if ($mode === 'pin') {
            return $pin;
        }
        if ($mode !== 'model' || !($query = $this->query())) {
            return null;
        }

        $keys = $query->where(config('adms.employees.pin_column', 'id'), $pin)
            ->limit(2)->pluck(config('adms.employees.key', 'id'));

        return $keys->count() === 1 ? (string) $keys->first() : null;   // several matches: never guessed
    }

    public function deviceUser(Device $device, string $employeeId): ?array
    {
        $mode = config('adms.employees.mode', 'none');

        if ($mode === 'model' && ($query = $this->query())) {
            $employee = $query->where(config('adms.employees.key', 'id'), $employeeId)->first();
            if (!$employee) {
                return null;
            }
            $pin = $employee->getAttribute(config('adms.employees.pin_column', 'id'));
            if ($pin === null || $pin === '') {
                return null;
            }
            $user = [
                'pin'  => (string) $pin,
                'name' => (string) $employee->getAttribute(config('adms.employees.name_column', 'name')),
            ];
            if ($column = config('adms.employees.card_column')) {
                $user['card'] = (string) $employee->getAttribute($column);
            }
            if ($column = config('adms.employees.password_column')) {
                $user['password'] = (string) $employee->getAttribute($column);
            }

            return $user;
        }

        if ($mode === 'pin') {
            return ['pin' => $employeeId];
        }

        return null;
    }

    /** @return \Illuminate\Database\Eloquent\Builder|null */
    protected function query()
    {
        $model = config('adms.employees.model');

        return $model && class_exists($model) ? $model::query() : null;
    }
}
