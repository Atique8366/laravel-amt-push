<?php

namespace BiometricPush\Adms\Tests\Fixtures;

use BiometricPush\Adms\Contracts\PunchHandler;
use BiometricPush\Adms\Models\Punch;

/** Records which punches it was given. */
class RecordingHandler implements PunchHandler
{
    /** @var array pins handled */
    public static $seen = [];

    public function handle(Punch $punch): ?string
    {
        self::$seen[] = $punch->pin;

        return 'seen';
    }
}
