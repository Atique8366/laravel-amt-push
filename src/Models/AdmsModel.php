<?php

namespace BiometricPush\Adms\Models;

use Illuminate\Database\Eloquent\Model;

/** Base model: table name = config('adms.table_prefix') + $baseTable, connection = config('adms.connection'). */
abstract class AdmsModel extends Model
{
    /** @var string table name without the prefix */
    protected $baseTable;

    public function getTable()
    {
        return config('adms.table_prefix', 'adms_') . $this->baseTable;
    }

    public function getConnectionName()
    {
        return config('adms.connection') ?: parent::getConnectionName();
    }

    /** Full table name, for query-builder use. */
    public static function tableName(): string
    {
        return (new static())->getTable();
    }
}
