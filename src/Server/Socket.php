<?php

namespace BiometricPush\AmtPush\Server;

/** One device connection, independent of the WebSocket library. */
interface Socket
{
    public function id(): int;

    public function send(string $text): void;

    public function close(): void;

    public function remoteIp(): ?string;
}
