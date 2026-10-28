<?php

namespace BiometricPush\AmtPush\Models;

use Illuminate\Database\Eloquent\Model;

/** Base model: table = config('amt-push.table_prefix') + $baseTable, connection = config('amt-push.connection'). */
abstract class AmtModel extends Model
{
    /** @var string table name without the prefix */
    protected $baseTable;

    public function getTable()
    {
        return config('amt-push.table_prefix', 'amt_push_') . $this->baseTable;
    }

    public function getConnectionName()
    {
        return config('amt-push.connection') ?: parent::getConnectionName();
    }

    public static function tableName(): string
    {
        return (new static())->getTable();
    }
}
