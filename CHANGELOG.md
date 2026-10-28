# Changelog

## 1.0.0 - 2026-10-28

First release.

- `/iclock` PUSH endpoints (handshake, uploads, command polling, results, ping, `.aspx` aliases, catch-all), auto-registered or via `Adms::routes()`.
- Device registry with pending / approved / blocked, auto-approve and pre-approved serials, read-only guard.
- Punch storage with duplicate protection and stamp handling; `PunchesReceived` event; optional `PunchHandler` in sync, queue or manual mode, with per-punch transactions, retries, `unmatched` handling and `punches_from`.
- Device users: import, PIN ↔ employee linking (`EmployeeResolver`, built-in none / pin / model modes), read-first writes that keep device-only values, delete with upload gate, deleted-on-device detection.
- Command queue with delivery limits, retries / expiry, cancel, `CommandFinished` event.
- Biometric templates (fingerprint, face, palm, BIODATA types) and user photos: capture, read-back after enrollment, compatibility check, copy to other devices.
- `Adms` facade / `DeviceClient`, artisan commands `adms:devices`, `adms:approve`, `adms:command`, `adms:process`, `adms:prune`.
- Tested on Laravel 8, 9, 10, 11 and 12 (PHP 7.4 to 8.3), SQLite and MySQL 8.4.
