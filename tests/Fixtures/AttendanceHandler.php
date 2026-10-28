<?php

namespace BiometricPush\AmtPush\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use BiometricPush\AmtPush\Contracts\PunchHandler;
use BiometricPush\AmtPush\Models\Punch;

/**
 * The host application's attendance logic in tests: first punch of the day = check-in,
 * later ones move the check-out. UserIDs listed in $failFor throw (to test retries).
 */
class AttendanceHandler implements PunchHandler
{
    /** @var array user id => remaining failures */
    public static $failFor = [];

    public function handle(Punch $punch): ?string
    {
        if (!empty(self::$failFor[$punch->user_id])) {
            self::$failFor[$punch->user_id]--;
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
                'employee_id' => $punch->employee_id, 'day' => $day, 'check_in' => $time, 'method' => implode('+', $punch->methods()),
            ]);
            return 'check_in';
        }
        DB::table('attendances')->where('id', $row->id)->update(['check_out' => $time]);

        return 'check_out';
    }
}
