# Laravel ADMS (ZKTeco PUSH / iclock)

Receive attendance from ZKTeco devices over **HTTP PUSH (ADMS / "Cloud Server")** in any Laravel 8–12+ app, and manage those devices from PHP.

The device connects to **your** server. You don't open ports to the device, and you don't need a VPN or static IPs on site. You get:

- punches stored (duplicates ignored) and handed to your own attendance logic;
- users and PINs read from and written to devices, linked to your employees;
- fingerprint / face / palm templates and user photos captured: **enroll once, punch at any device**;
- a command queue (reboot, info, re-collect punches, clear log, raw commands) with results;
- safety guards: read-only devices, "never delete before all punches are uploaded", and reading the device first so existing admins and passwords are kept.

| | `jmrashed/zkteco` (UDP/TCP pull) | this library (HTTP PUSH) |
|---|---|---|
| Who connects | your server → device IP:4370 | device → your server URL |
| Network | same LAN / VPN / port forward | any internet connection |
| Calls | synchronous (`$zk->getAttendance()`) | queued: the device runs them on its next poll (a few seconds) |
| Punches | you poll | real time, plus buffered while offline |

Requirements: PHP 7.4 / 8.x, Laravel 8, 9, 10, 11 or 12, MySQL / MariaDB / PostgreSQL / SQLite. Devices need **ADMS / Cloud Server** in their menu: most current ZKTeco terminals (MB / SpeedFace / ProFace / uFace / iClock / K-series with push firmware).

---

## 1. Install

```bash
composer require biometric-push/laravel-adms
php artisan migrate
```

The service provider and the `Adms` facade are auto-discovered. To change the defaults, publish the config (and the migration, if you want to edit it):

```bash
php artisan vendor:publish --tag=adms-config
php artisan vendor:publish --tag=adms-migrations   # then set 'run_migrations' => false in config/adms.php
```

Tables (prefix configurable): `adms_devices`, `adms_punches`, `adms_device_users`, `adms_commands`, `adms_request_logs`, `adms_biometric_templates`, `adms_device_templates`.

## 2. Server setup

Devices call these URLs at the **root** of the address you give them:

```
/iclock/cdata        handshake + uploads (punches, users, templates, options)
/iclock/getrequest   poll for commands
/iclock/devicecmd    command results
/iclock/ping
```

They are registered automatically, with no auth, CSRF or throttle middleware: devices are identified by serial number and must be approved.

- **Same domain as your app.** Nothing to do. The device uses your domain and port 80/443.
- **Dedicated port (recommended).** Add a second web-server block on, say, port 8085 that serves the same Laravel `public/index.php`. Many devices only speak plain HTTP, so check before forcing HTTPS.

  ```nginx
  server {
      listen 8085;
      root /var/www/your-app/public;
      location / { try_files $uri /index.php?$query_string; }
      location ~ \.php$ {
          include fastcgi_params;
          fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
          fastcgi_param SCRIPT_NAME /index.php;
          fastcgi_pass unix:/run/php/php8.2-fpm.sock;
      }
  }
  ```

- **Your own route group.** Set `iclock.enabled = false` and register the routes yourself:

  ```php
  // routes/web.php or a route file loaded WITHOUT the web/CSRF middleware
  Route::prefix('push')->group(fn () => \BiometricPush\Adms\Facades\Adms::routes());
  ```

  The device must then point at `/push`. Most firmware can't use a path, so a prefix only works behind a proxy that rewrites `/iclock` to `/push/iclock`.

## 3. Connect a device

On the terminal, go to **Menu → Comm. → Cloud Server Setting (ADMS)**:

| Setting | Value |
|---|---|
| Server mode | ADMS |
| Enable domain name | on if you use a host name |
| Server address | your host or IP |
| Server port | 80 / 443 / your dedicated port |
| Proxy | off |

The device appears as **pending** on its first request. Approve it:

```bash
php artisan adms:devices --pending
php artisan adms:approve CQZ7232460123 --name="Head office"
```

or from code: `Adms::register('CQZ7232460123', ['name' => 'Head office'])`. You can approve before the device ever connects. If you trust your network, set `ADMS_APPROVED_SERIALS=SN1,SN2` or `devices.auto_approve = true`.

## 4. Link device users to your employees

Each user on a device has a **PIN**. Tell the library how a PIN maps to your people, in `config/adms.php`:

```php
'employees' => [
    'mode'        => 'model',                 // none | pin | model
    'model'       => App\Models\Employee::class,
    'key'         => 'id',                    // your employee id
    'pin_column'  => 'employee_code',         // the value used as device PIN
    'name_column' => 'name',
    'card_column' => 'rfid',                  // optional
    'password_column' => null,
],
```

- `pin`: the PIN *is* your employee id.
- `model`: a PIN is linked when exactly one employee has that code. Several matches are never guessed.
- `none`: link by hand with `Adms::device($sn)->linkUser('1001', $employeeId)`.

For anything more complex (multi-tenant codes, branches, active-status rules), write a resolver:

```php
use BiometricPush\Adms\Contracts\EmployeeResolver;
use BiometricPush\Adms\Models\Device;

class MyResolver implements EmployeeResolver
{
    public function resolve(Device $device, string $pin, string $nameOnDevice): ?string
    {
        return Employee::where('company_id', $device->tenant_id)->where('code', $pin)->value('id');
    }

    public function deviceUser(Device $device, string $employeeId): ?array
    {
        $e = Employee::find($employeeId);
        if (!$e || !$e->active) {
            return null;                             // not allowed on this device
        }
        return ['pin' => $e->code, 'name' => $e->name, 'card' => $e->rfid];
    }
}
// config: 'employees' => ['resolver' => App\Adms\MyResolver::class, ...]
```

`tenant_id` and `meta` on `adms_devices` are free for your own use (company, branch, location, ...).

## 5. Turn punches into attendance

Every punch is saved in `adms_punches` and the `PunchesReceived` event is fired. Pick **one** of two ways to use them.

**A. A handler.** Gives you ordering, retries and status tracking:

```php
use BiometricPush\Adms\Contracts\PunchHandler;
use BiometricPush\Adms\Models\Punch;

class RecordAttendance implements PunchHandler
{
    public function handle(Punch $punch): ?string
    {
        // $punch->employee_id, ->punched_at (device local time), ->verify_mode (1 finger, 15 face, ...),
        // ->punch_status (0 in, 1 out, ... 255 = device has no in/out keys), ->serial_number
        Attendance::record($punch->employee_id, $punch->punched_at, $punch->verifyModeName());
        return 'recorded';                            // saved in adms_punches.process_result
    }
}
```

```php
'punches' => [
    'handler' => App\Adms\RecordAttendance::class,
    'process' => 'sync',    // sync (in the request) | queue (job, needs a worker) | manual (adms:process / scheduler)
],
```

- Punches arrive per device, in time order. Each one runs in its own DB transaction: throw to roll back, and the punch is retried up to `max_attempts` times.
- Punches of unlinked PINs are marked `unmatched`. Linking the PIN later reprocesses them automatically.
- `punches_from` (per device) skips history older than the day you went live.

**B. An event listener.** Use this if you only want the raw data:

```php
Event::listen(\BiometricPush\Adms\Events\PunchesReceived::class, function ($e) {
    foreach ($e->punches as $punch) { /* ... */ }
});
```

## 6. Managing devices: the API

Push devices **poll** for work. Every write is queued and returns a `Command` model. The device runs it within `delay_seconds` and the result lands on that model, and also fires `CommandFinished`:

```php
use BiometricPush\Adms\Facades\Adms;

$device = Adms::device('CQZ7232460123');     // serial number, id or model

$cmd = $device->restart();
$cmd->fresh()->status;                       // queued → sent → success | failed | expired | cancelled
$cmd->fresh()->return_code;                  // device Return= code (Command::returnMeaning())
```

| zkteco (pull) habit | ADMS equivalent |
|---|---|
| `$zk->getAttendance()` | `$device->attendance($from, $to, $pin)` (already stored) |
| `$zk->getUser()` | `$device->users()` / `$device->user($pin)`; `$device->refreshUsers()` re-reads the device |
| `$zk->setUser(...)` | `$device->setUser('1001', 'Jane Doe', ['privilege' => 'admin', 'card' => '123', 'password' => '4321'])` |
| `$zk->removeUser($uid)` | `$device->removeUser('1001')` |
| `$zk->clearAttendance()` | `$device->clearAttendance()` (held until every punch is uploaded) |
| `$zk->restart()` | `$device->restart()` |
| `$zk->version()` etc. | `$device->refreshInfo()`, then `$device->model()->firmware_version`, `user_count`, `fp_algorithm`, ... |
| — | `$device->recollectAttendance()`: the device re-sends every punch it holds (only missing ones are added) |
| — | `$device->command('SET OPTION Delay=30')`: any raw PUSH command |

More on the device client: `by($userId)` records who queued the command, plus `setUsers([...])`, `linkUser`, `unlinkUser`, `pullTemplates`, `pushTemplates`, `processPunches`, `reprocessPunches`, `commands`, `cancel($id)`, `pendingUploads()`, `approve`, `block`, `readOnly`, `update([...])` and `isOnline()`.

Employee-level helpers that work across devices:

```php
Adms::syncEmployee($employeeId, ['SN1', 'SN2']);           // user record from your resolver
Adms::syncEmployee($employeeId, 'SN1', ['user' => ['privilege' => 'admin']]);
Adms::copyBiometrics($employeeId, ['SN2', 'SN3']);          // user + stored templates (enroll once, punch anywhere)
Adms::removeEmployee($employeeId);                          // from every device they are on
Adms::templates($employeeId);                               // stored templates
Adms::photo($employeeId);                                   // base64 JPG captured from a device
Adms::processPunches();                                     // all devices (for 'manual' mode / scheduler)
```

### Safety built in

- **Read first.** With `users.read_first` on (the default), writing a user first reads the device's users. Anything you don't pass (admin level, password, card, access time-zone) keeps the device's current value, so an admin enrolled on the device is never demoted by a sync. Several writes queued before the device polls share one read.
- **No punch loss.** `removeUser` and `clearAttendance` are only delivered once the punches stored here are at least the count the device reports (`commands.wait_for_uploads`).
- **Read-only devices.** `$device->readOnly()` is for shared or parallel-run devices. They still upload punches, users and templates, but any write throws `ReadOnlyDeviceException` and waiting writes are cancelled.
- **Unanswered commands.** Commands are re-sent up to `max_attempts` times. `REBOOT` and `CLEAR` are never repeated.
- **Deleted on the device.** After a full user read, users that are no longer on the device are marked `removed` and their templates `missing`, so the next copy restores them.

### Templates

Templates uploaded by a device are stored once per employee. Fingerprints, faces and palms are keyed by algorithm version; photos are kept too. Unlinked users' templates are kept per device PIN and move to the employee when the PIN is linked. Templates are only sent to devices that report the same algorithm major version. Incompatible ones are reported, never sent. Algorithms below 12 use the `FINGERTMP` / `FACE` tables; 12+ and other types use `BIODATA`.

## 7. Events

| Event | When |
|---|---|
| `DeviceRegistered` | an unknown serial number connected |
| `PunchesReceived` | new punches stored (duplicates excluded) |
| `DeviceUsersUploaded` | the device uploaded user records |
| `BiometricsCaptured` | new or changed templates / photos stored |
| `CommandFinished` | a command succeeded, failed or expired |

## 8. Artisan

```bash
php artisan adms:devices [--pending]
php artisan adms:approve SN [--name=..] [--block]
php artisan adms:command SN restart|info|users|recollect|clear-attendance|raw "SET OPTION Delay=30"
php artisan adms:process [SN]
php artisan adms:prune [--days=7] [--commands=30]
```

Suggested schedule (`manual` punch mode, plus log cleanup):

```php
$schedule->command('adms:process')->everyMinute()->withoutOverlapping();
$schedule->command('adms:prune --commands=30')->daily();
```

## 9. Configuration reference

See `config/adms.php`. Every key is commented. The most used:

| Key | Default | |
|---|---|---|
| `devices.auto_register` / `auto_approve` / `approved_serials` | true / false / [] | first-contact policy |
| `devices.timezone_offset` | null (app timezone) | sent to the device as TimeZone |
| `devices.delay_seconds` | 10 | poll interval, so command latency |
| `punches.handler` / `process` | null / sync | see §5 |
| `employees.*` | mode none | see §4 |
| `users.read_first` | true | see Safety |
| `commands.wait_for_uploads` | true | see Safety |
| `biometrics.capture` / `read_after_enroll` | true / true | template capture |
| `request_log.enabled` / `keep_days` | true / 7 | raw traffic log (templates masked) |
| `table_prefix` / `connection` | adms_ / default | |

## 10. Troubleshooting

- **Device never shows up.** Open `http://your-host:port/iclock/cdata?SN=TEST&options=all` in a browser: it should answer `Device Unauthorized` and create a pending `TEST` device. If it doesn't, the web server or port is wrong. Also check the device's own network settings (gateway, DNS).
- **Pending forever.** Approve it (§3).
- **Commands stay `queued`.** The device isn't polling. Check `last_seen_at` (`adms:devices`).
- **`removeUser` / `clearAttendance` waiting.** The device still holds punches that aren't uploaded yet. See the command's `response`. Use `recollectAttendance()`.
- **Every request is logged** in `adms_request_logs` (polls with nothing to do are skipped), so check there first.
- **Wrong times.** Punch times are the device's local clock. The handshake sends `TimeZone` from `devices.timezone_offset`.

## Testing

```bash
composer install
vendor/bin/phpunit                       # SQLite in memory
ADMS_TEST_DB=mysql ADMS_TEST_DB_DATABASE=throwaway_db vendor/bin/phpunit   # MySQL (drops all tables in that DB!)
```

The suites are `Unit` (protocol, models, parsing), `Feature` (each endpoint and API over HTTP with a device simulator) and `Integration` (a full two-device lifecycle against a host-app employee table and attendance handler).

## License

MIT
