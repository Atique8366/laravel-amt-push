<?php

namespace BiometricPush\Adms\Contracts;

use BiometricPush\Adms\Models\Punch;

/**
 * Turns one stored punch into your own attendance record.
 *
 * Punches arrive per device in time order. Each call runs inside its own database
 * transaction: throw to roll back and retry later (up to punches.max_attempts).
 */
interface PunchHandler
{
    /**
     * @return string|null short result saved in punches.process_result (e.g. "check_in", "duplicate")
     */
    public function handle(Punch $punch): ?string;
}
