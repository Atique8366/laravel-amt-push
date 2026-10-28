<?php

namespace BiometricPush\AmtPush\Server;

/** All live sessions of the server process, keyed by socket id. */
class Sessions
{
    /** @var array<int, Session> */
    private $sessions = [];

    public function add(Session $session): void
    {
        $this->sessions[$session->socket->id()] = $session;
    }

    public function remove(Session $session): void
    {
        unset($this->sessions[$session->socket->id()]);
    }

    public function find(int $socketId): ?Session
    {
        return isset($this->sessions[$socketId]) ? $this->sessions[$socketId] : null;
    }

    public function forDevice(int $deviceId): ?Session
    {
        foreach ($this->sessions as $s) {
            if ($s->device && (int) $s->device->id === $deviceId) {
                return $s;
            }
        }

        return null;
    }

    /** @return Session[] */
    public function all(): array
    {
        return $this->sessions;
    }

    /** Other sessions bound to the same device (a reboot without a clean close): returned for closing. */
    public function duplicatesOf(Session $keep): array
    {
        $out = [];
        foreach ($this->sessions as $s) {
            if ($s !== $keep && $s->device && $keep->device && (int) $s->device->id === (int) $keep->device->id) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
