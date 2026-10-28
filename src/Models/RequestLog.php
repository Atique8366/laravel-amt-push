<?php

namespace BiometricPush\Adms\Models;

/** One /iclock request and the reply (bodies capped, templates masked). */
class RequestLog extends AdmsModel
{
    protected $baseTable = 'request_logs';

    protected $fillable = [
        'device_id', 'serial_number', 'method', 'path', 'query_string', 'table_name', 'body', 'body_size',
        'response_code', 'response_body', 'records_parsed', 'records_saved', 'error', 'ip', 'duration_ms',
    ];
}
