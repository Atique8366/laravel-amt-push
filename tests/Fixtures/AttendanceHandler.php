<?php

namespace BiometricPush\Adms\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use BiometricPush\Adms\Contracts\PunchHandler;
use BiometricPush\Adms\Models\Punch;

/**
 * The host application's attendance logic in tests: first punch of the day = check-in,
 * later ones move the check-out. PINs listed in $failFor throw (to test retries).
 */
class AttendanceHandler implements PunchHandler
{
    /** @var array pin => remaining failures */
    public static $failFor = [];

    public function handle(Punch $punch): ?string
    {
        if (!empty(self::$failFor[$punch->pin])) {
            self::$failFor[$punch->pin]--;
            DB::table('attendances')->insert([   // rolled back with the punch's transaction
                'employee_id' => $punch->employee_id, 'day' => 'rollback-me', 'check_in' => '00:00:00',
            ]);
            throw new \RuntimeException('attendance service down');
        }

        $day = $punch->punched_at->format('Y-m-d');
        $time = $punch->punched_at->format('H:i:s');
        $row = DB::table('attendances')->where('employee_id', $punch->employee_id)->where('day', $day)->first();
        if (!$row) {
            DB::table('attendances')->insert([
                'employee_id' => $punch->employee_id, 'day' => $day, 'check_in' => $time, 'method' => $punch->verifyModeName(),
            ]);
            return 'check_in';
        }
        DB::table('attendances')->where('id', $row->id)->update(['check_out' => $time]);

        return 'check_out';
    }
}
