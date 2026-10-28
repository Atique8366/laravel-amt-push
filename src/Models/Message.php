<?php

namespace BiometricPush\AmtPush\Models;

/** Raw XML exchanged with a device (templates and photos masked). */
class Message extends AmtModel
{
    protected $baseTable = 'messages';

    protected $fillable = ['device_id', 'serial_number', 'direction', 'kind', 'payload'];
}
