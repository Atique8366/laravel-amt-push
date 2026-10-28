<?php

namespace BiometricPush\AmtPush\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** The host application's employee model in tests. */
class Employee extends Model
{
    protected $table = 'employees';

    public $timestamps = false;

    protected $guarded = [];
}
