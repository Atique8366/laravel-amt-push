<?php

namespace BiometricPush\AmtPush\Resolvers;

use BiometricPush\AmtPush\Contracts\EmployeeResolver;
use BiometricPush\AmtPush\Models\Device;

/**
 * Built-in resolver driven by config('amt-push.employees'):
 *   "none"  never links;  "pin"  the UserID is the employee id;
 *   "model" the UserID is looked up in model.pin_column (exactly one match links).
 */
class ConfigEmployeeResolver implements EmployeeResolver
{
    public function resolve(Device $device, string $userId, string $nameOnDevice): ?string
    {
        $mode = config('amt-push.employees.mode', 'none');
        if ($mode === 'pin') {
            return $userId;
        }
        if ($mode !== 'model' || !($query = $this->query())) {
            return null;
        }
        $keys = $query->where(config('amt-push.employees.pin_column', 'id'), $userId)->limit(2)
            ->pluck(config('amt-push.employees.key', 'id'));

        return $keys->count() === 1 ? (string) $keys->first() : null;   // several matches: never guessed
    }

    public function deviceUser(Device $device, string $employeeId): ?array
    {
        $mode = config('amt-push.employees.mode', 'none');
        if ($mode === 'pin') {
            return ['user_id' => $employeeId];
        }
        if ($mode !== 'model' || !($query = $this->query())) {
            return null;
        }
        $employee = $query->where(config('amt-push.employees.key', 'id'), $employeeId)->first();
        $userId = $employee ? $employee->getAttribute(config('amt-push.employees.pin_column', 'id')) : null;
        if ($userId === null || $userId === '') {
            return null;
        }
        $user = [
            'user_id' => (string) $userId,
            'name'    => (string) $employee->getAttribute(config('amt-push.employees.name_column', 'name')),
        ];
        foreach (['password' => 'password_column', 'card' => 'card_column', 'department' => 'department_column'] as $key => $setting) {
            if ($column = config('amt-push.employees.' . $setting)) {
                $user[$key] = $employee->getAttribute($column);
            }
        }

        return $user;
    }

    /** @return \Illuminate\Database\Eloquent\Builder|null */
    protected function query()
    {
        $model = config('amt-push.employees.model');

        return $model && class_exists($model) ? $model::query() : null;
    }
}
