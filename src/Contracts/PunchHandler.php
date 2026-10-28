<?php

namespace BiometricPush\AmtPush\Contracts;

use BiometricPush\AmtPush\Models\Punch;

/**
 * Turns one stored punch into your own attendance record.
 *
 * Punches arrive per device in time order. Each call runs in its own database transaction:
 * throw to roll back and retry later (up to punches.max_attempts).
 */
interface PunchHandler
{
    /** @return string|null short result saved in process_result (e.g. "check_in") */
    public function handle(Punch $punch): ?string;
}
