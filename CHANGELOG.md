# Changelog

## 1.0.0 - 2026-10-28

First release.

- `amt:serve` WebSocket server (Workerman 4 / 5) for M50 WebSocket SDK terminals (AMT800 / AMF800 / F500 family), with the device conversation in a transport-independent kernel.
- Register / Login with per-Register tokens, pending / approved / blocked devices, CloudId restriction, KeepAlive, SetTime and catch-up pull on connect.
- Punches from `TimeLog_v2` and log pulls: duplicate protection, LogID-reset detection (log epochs), `PunchesReceived`, optional `PunchHandler` (sync / queue / manual) with per-punch transactions and retries.
- Command queue: one request in flight per device, batches in order with abort on failure, retries, timeouts, cancel, read-only guard, `CommandFinished`.
- Users: partial `SetUserData` writes, delete, `GetUserData`, full user listing with deleted-on-device detection, `EmployeeResolver` (none / pin / model or your own).
- Biometrics: read back after enrollments (probe or user-data strategy), stored once per employee, copied to other devices (`copyBiometrics`).
- Safe log clears (pulled first, sent only when every punch is stored), device info, departments, lock control, raw requests.
- `Amt` facade / `DeviceClient`, artisan `amt:serve`, `amt:devices`, `amt:approve`, `amt:command`, `amt:process`, `amt:prune`.
- Tested on Laravel 8, 9, 10, 11 and 12 (PHP 7.4 to 8.3), SQLite and MySQL, including the real server over a real WebSocket.
