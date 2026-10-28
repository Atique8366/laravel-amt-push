# Laravel AMT Push (M50 WebSocket SDK)

Connect **AMT800 / AMF800 / F500-family attendance terminals** (devices that speak the *M50 WebSocket SDK* protocol) to any Laravel 8–12+ app.

The terminals open a WebSocket to **your** server and keep it open. Punches arrive in real time, and you manage the devices from PHP:

- punches stored (duplicates ignored, LogID resets detected) and handed to your own attendance logic;
- device users written, deleted, read and linked to your employees, **without wiping cards, passwords or QR codes you didn't touch**;
- fingerprint, face and palm templates and user photos read back after an enrollment on a terminal: **enroll once, punch at any device**;
- a command queue: one request in flight per device, batches that stop at the first failure, retries and timeouts;
- log pulls (catch-up after downtime, full backfill, per-user) and **safe log clears** that wait until every punch is stored;
- a read-only guard for shared or parallel-run devices.

| | ZKTeco UDP libraries | this library |
|---|---|---|
| Who connects | your server → device IP | device → your server (`ws://` / `wss://`) |
| Network | LAN / VPN / port forward | any internet connection |
| Calls | synchronous | queued; sent within ~1 s while the device is online, otherwise on reconnect |
| Punches | you poll | pushed live, plus pulled after an outage |

Requirements: PHP 7.4 / 8.x, Laravel 8, 9, 10, 11 or 12, MySQL / MariaDB / PostgreSQL / SQLite. Uses [Workerman](https://github.com/walkor/workerman) 4 or 5 for the socket server (installed automatically).

---

## 1. Install

```bash
composer require biometric-push/laravel-amt-push
php artisan migrate
```

The service provider and the `Amt` facade are auto-discovered. To change the defaults:

```bash
php artisan vendor:publish --tag=amt-push-config
php artisan vendor:publish --tag=amt-push-migrations   # optional; then set 'run_migrations' => false
```

Tables (prefix `amt_push_`, configurable): `devices`, `punches`, `admin_logs`, `commands`, `device_users`, `biometrics`, `device_biometrics`, `messages`.

## 2. Run the device server

The terminals talk to a long-running process, separate from your web server:

```bash
php artisan amt:serve            # foreground (development, Windows)
php artisan amt:serve start -d   # daemon (Linux / macOS)
php artisan amt:serve status | stop | restart
```

It listens on `AMT_PUSH_HOST:AMT_PUSH_PORT` (default `0.0.0.0:8090`). In production, keep it running with **supervisor** or **systemd**:

```ini
; /etc/supervisor/conf.d/amt-push.conf
[program:amt-push]
command=php /var/www/your-app/artisan amt:serve
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/www/your-app/storage/logs/amt-push-supervisor.log
```

```ini
# /etc/systemd/system/amt-push.service
[Service]
ExecStart=/usr/bin/php /var/www/your-app/artisan amt:serve
Restart=always
User=www-data
[Install]
WantedBy=multi-user.target
```

Restart it after every deploy, as you would `queue:restart`. Your web requests only write to the database; the server picks queued commands up every second.

**TLS (`wss://`).** Either set `AMT_PUSH_SSL=true` with `AMT_PUSH_SSL_CERT` / `AMT_PUSH_SSL_KEY`, or terminate TLS in nginx:

```nginx
location /amt-ws/ {
    proxy_pass http://127.0.0.1:8090/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 600s;
}
```

## 3. Connect a terminal

In the terminal menu (**Comm. → Server / Cloud server**), enter the WebSocket URL, for example `ws://203.0.113.10:8090/` or `wss://example.com/amt-ws/`. The device connects within about 10 seconds and sends `Register`.

An unknown serial number is saved as **pending** and refused. The device retries every ~10 s; approve it and it is accepted on its next try:

```bash
php artisan amt:devices --pending
php artisan amt:approve AMT800-0001 --name="Head office"
```

or from code: `Amt::register('AMT800-0001', ['name' => 'Head office'])`. You can approve before the device ever connects. Other options:
- `AMT_PUSH_APPROVED_SERIALS=SN1,SN2` approves those serials on first contact;
- `devices.auto_approve` approves every new device;
- `AMT_PUSH_CLOUD_ID` accepts only devices that send this CloudId.

On every connect the server sets the device clock (`SetTime`, in `devices.timezone` or the device's own `timezone`). If a LogID is already known, it also pulls any punches the device holds after it.

## 4. Link device users to your employees

Each user on a terminal has a numeric **UserID** (1–99999999). Map it to your people in `config/amt-push.php`:

```php
'employees' => [
    'mode'            => 'model',                  // none | pin | model
    'model'           => App\Models\Employee::class,
    'key'             => 'id',
    'pin_column'      => 'employee_code',          // the value used as device UserID
    'name_column'     => 'name',
    'password_column' => 'attendance_pin',         // optional: device PWD
    'card_column'     => null,
    'department_column' => null,
],
```

- `pin`: the UserID *is* your employee id.
- `model`: linked when exactly one employee has that code. Several matches are never guessed.
- `none`: link by hand: `Amt::device($sn)->linkUser('1001', $employeeId)`.

For anything more specific (multi-tenant codes, branches, status rules), write a resolver:

```php
use BiometricPush\AmtPush\Contracts\EmployeeResolver;
use BiometricPush\AmtPush\Models\Device;

class MyResolver implements EmployeeResolver
{
    public function resolve(Device $device, string $userId, string $nameOnDevice): ?string
    {
        return Employee::where('company_id', $device->tenant_id)->where('code', $userId)->value('id');
    }

    public function deviceUser(Device $device, string $employeeId): ?array
    {
        $e = Employee::find($employeeId);

        return $e && $e->active
            ? ['user_id' => $e->code, 'name' => $e->name, 'privilege' => 'User', 'password' => $e->pin]
            : null;                                   // not allowed on this device
    }
}
// config: 'employees' => ['resolver' => App\Amt\MyResolver::class, ...]
```

`tenant_id` and `meta` on the devices table are free for your own use (company, branch, site...).

## 5. Turn punches into attendance

Every punch is saved in `amt_push_punches` and `PunchesReceived` is fired. Its `realtime` flag is true for a live `TimeLog_v2` and false for a pulled log. Pick one way to use them:

**A handler**, which gives you ordering, retries and per-punch status:

```php
use BiometricPush\AmtPush\Contracts\PunchHandler;
use BiometricPush\AmtPush\Models\Punch;

class RecordAttendance implements PunchHandler
{
    public function handle(Punch $punch): ?string
    {
        // $punch->employee_id, ->user_id, ->punched_at (device local time), ->action ("FP", "FACE", "FACE+CD"...),
        // ->methods() (['face', 'card']), ->attend_stat ("Duty On", "Duty Off", "In", "Out"...), ->latitude / ->longitude
        Attendance::record($punch->employee_id, $punch->punched_at, $punch->methods());

        return 'recorded';                            // saved in process_result
    }
}
```

```php
'punches' => [
    'handler' => App\Amt\RecordAttendance::class,
    'process' => 'sync',     // sync (in the server process) | queue (a job; needs a worker) | manual (amt:process)
],
```

- Punches run per device in time order, each in its own DB transaction. Throw to roll back; the punch is retried up to `max_attempts` times.
- Unlinked UserIDs are marked `unmatched`. Linking the user later reprocesses them automatically.
- `punches_from` (per device) skips history older than your go-live day.

**An event listener**, if you only want the raw data:

```php
Event::listen(\BiometricPush\AmtPush\Events\PunchesReceived::class, function ($e) {
    foreach ($e->punches as $punch) { /* ... */ }
});
```

## 6. Managing devices: the API

Every call that talks to a device **queues a request** and returns a `Command` model. The server sends it within a second while the device is online (or when it reconnects). The answer lands on the model and fires `CommandFinished`:

```php
use BiometricPush\AmtPush\Facades\Amt;

$device = Amt::device('AMT800-0001');          // serial number, id or model

$cmd = $device->setUser('1001', 'Jane Doe', ['privilege' => 'Manager', 'password' => '2468']);
$cmd->fresh()->status;                          // pending → sent → succeeded | failed | timed_out | cancelled
$cmd->fresh()->error;                           // e.g. "device replied: Fail No Certificates"
```

| Task | Call |
|---|---|
| Add / update a user | `setUser($userId, $name, [privilege, department, enabled, password, card, qr, timesets, period, allow_no_certificate])` (only what you pass is written) |
| Delete a user (and their templates) | `removeUser($userId)` |
| Read users from the device | `refreshUsers()` (all, marks users deleted on the device), `readUser($userId)` |
| Stored users | `users()`, `user($userId)`, `linkUser($userId, $employeeId)`, `unlinkUser($userId)` |
| Stored punches | `attendance($from, $to, $userId)` |
| Fetch punches from the device | `pullAttendance()` (after the last LogID), `pullAttendance(true)` (everything), `pullAttendanceFor($userId, $from, $to)` |
| Clear the device log | `clearAttendance()` (pulled first, sent only when nothing would be lost); `clearAllData()`, `clearUsers()`, `clearAdminLog()` |
| Clock | `syncTime()` |
| Info | `refreshInfo()` (firmware, settings, user / finger / face counts, log position on the device row) |
| Enable / disable punching | `enable(false)` / `enable()` |
| Restart / door | `restart()`, `lockControl($mode)` (1 open, 2 close, 3 normal open, 4 auto recover, 5 reboot, 6 cancel alarm) |
| Departments | `setDepartment(2, 'Sales')` |
| Templates | `pullTemplates($userId, ['finger', 'face', 'palm', 'photo'])`, `pushTemplates($employeeId)` |
| Anything else in the protocol | `command('SetDeviceInfo', ['ParamName' => 'SoundVolume', 'Value' => 5])` |
| Queue management | `commands()`, `cancel($id)`, `by($userId)` (who queued it) |
| Registration | `approve()`, `block()`, `readOnly()`, `update([...])`, `isOnline()` |

Employee helpers across devices:

```php
Amt::syncEmployee($employeeId, ['AMT800-0001', 'AMT800-0002']);   // user record from your resolver
Amt::copyBiometrics($employeeId, ['AMT800-0002']);                 // user + templates, in one batch
Amt::removeEmployee($employeeId);                                  // from every device they are on
Amt::templates($employeeId);   Amt::photo($employeeId);   Amt::processPunches();
```

### Enroll once, punch anywhere

1. The employee enrolls a finger or face at any terminal. The terminal reports it (`AdminLog_v2`) and the server reads the templates back automatically.
2. `Amt::copyBiometrics($employeeId, [...other devices])` sends the user record, then each template, in order. The batch stops at the first failure.
3. Only changed templates are re-sent later. A template is kept per employee, so it follows the person, not the device.

`biometrics.pull_strategy`:
- `probe` (default) asks for finger slots 0–9 and the face. This is proven on AMT800.
- `user_data` reads `GetUserData` first and then only the enrolled fingers, face and palms (per spec).

### Safety built in

- **Partial user writes.** `SetUserData` contains only the fields you pass. A card, password or QR on the device is never blanked by an update.
- **No punch loss.** `EmptyTimeLog` and `EmptyAllData` are queued after a pull. They are only sent when the punches stored for the current log epoch are at least the device's own `LogCount`.
- **LogID resets.** When someone clears the log on the terminal menu, LogIDs restart at 1. A known LogID that arrives with a different user or time starts a new *log epoch* instead of being dropped as a duplicate.
- **Correlation.** The protocol has no request id, so one request is in flight per device. An answer whose name doesn't match the request in flight (a late reply) is ignored.
- **Read-only devices.** Reads and live punches work. Writes throw `ReadOnlyDeviceException`, and waiting writes are cancelled.
- **Tokens.** A fresh token is issued on every Register. Blocking a device revokes it.

## 7. Events

| Event | When |
|---|---|
| `DeviceRegistered` | an unknown serial number sent Register |
| `DeviceConnected` / `DeviceDisconnected` | an approved device logged in / its last socket closed |
| `PunchesReceived` | new punches stored (`realtime` true for live, false for pulled) |
| `AdminLogReceived` | an admin action on the terminal (enroll, delete, menu access) |
| `DeviceUsersUploaded` | user records read from a device |
| `BiometricsCaptured` | templates / photos read from a device |
| `CommandFinished` | a request succeeded, failed, timed out or was cancelled |

The server runs as its own process. Listeners for device-side events (punches, admin logs, connections) run **in the server process**, so keep them quick, or queue the work.

## 8. Artisan

```bash
php artisan amt:serve [start|stop|restart|status] [-d]
php artisan amt:devices [--pending]
php artisan amt:approve SN [--name=..] [--block]
php artisan amt:command SN sync-time|info|users|pull|backfill|restart|enable|disable|clear-attendance|raw GetTime
php artisan amt:process [SN]
php artisan amt:prune [--days=7] [--commands=30]
```

## 9. Configuration reference

See `config/amt-push.php`. Every key is commented. The most used:

| Key | Default | |
|---|---|---|
| `server.host` / `port` / `ssl` | 0.0.0.0 / 8090 / false | WebSocket listener |
| `devices.auto_register` / `auto_approve` / `approved_serials` / `cloud_id` | true / false / [] / null | first-contact policy |
| `devices.timezone` | app timezone | device clock |
| `devices.set_time_on_connect` / `pull_on_connect` / `pull_every_minutes` | true / true / 0 | |
| `punches.handler` / `process` | null / sync | see §5 |
| `employees.*` | mode none | see §4 |
| `users.default_privilege` / `allow_no_certificate` | User / true | |
| `commands.wait_for_uploads` | true | safe clears |
| `biometrics.pull_after_enroll` / `pull_strategy` | true / probe | |
| `message_log.enabled` / `keep_days` | true / 7 | raw XML in / out (templates masked) |

## 10. What is proven on hardware

The protocol layer follows the *M50 WebSocket SDK Communication Protocol* (2025-08). The wire formats of these requests come from code that ran against **AMT800** terminals, including the observed quirks (un-padded `-T` times, `Get*Data` answers without a `Result` tag, `EmptyTimeLog` rather than `ClearAllGLog`): Register / Login / tokens, `TimeLog_v2` + acknowledgement, `AdminLog_v2`, KeepAlive, `SetTime`, `SetUserData` (Set / Delete), `GetFingerData`, `GetFaceData`, `SetFingerData`, `SetFaceData`, `GetGlogPosInfo`, `GetFirstGlog` / `GetNextGlog`, `EnableDevice`, `SetDepartment` and the `Empty*` clears.

Implemented from the specification and covered by tests, but not yet run on a terminal: the user listing (`GetFirstUserData` / `GetNextUserData`), `GetUserData`, palm templates, `SetUserPhoto` / `GetUserPhoto`, `LockControl` (restart / door), `GetDeviceInfoAll` / `GetDeviceStatusAll`, `GetFirmwareVersion` and `AllowNoCertificate`. Check these once on your model before relying on them. This library itself has been tested against simulated terminals and its real server over a real WebSocket, not yet against a physical terminal.

## 11. Troubleshooting

- **Device never connects.** Is `amt:serve` running and the port open in the firewall? Check the URL in the device menu (`ws://host:port/`). Test the listener with `curl -i -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: x3JJHMbDL1EzLkh9GBhXDw==" http://host:port/`.
- **Connects but "pending".** Approve it (§3).
- **Commands stay `pending`.** The device isn't online (`amt:devices`), or an earlier step of the same batch is unfinished.
- **A clear never runs.** Its `error` says how many punches are still missing. Run `pullAttendance(true)` once (a full backfill), or set `commands.wait_for_uploads = false` if you accept the loss.
- **Everything is in `amt_push_messages`**: the raw XML in both directions, with templates masked.

## Testing

```bash
composer install
vendor/bin/phpunit                                   # SQLite
AMT_TEST_DB=mysql AMT_TEST_DB_DATABASE=throwaway vendor/bin/phpunit   # MySQL (drops all tables in that DB!)
```

The suites:
- `Unit`: encodings, frames, the command builder, models, the resolver.
- `Feature`: the device conversation through the protocol kernel with simulated terminals.
- `Integration`: a two-terminal lifecycle, plus the **real `amt:serve` Workerman server** in its own process with a real WebSocket client.

## License

MIT
