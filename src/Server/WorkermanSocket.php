<?php

namespace BiometricPush\AmtPush\Server;

use Workerman\Connection\TcpConnection;

class WorkermanSocket implements Socket
{
    /** @var TcpConnection */
    private $connection;

    public function __construct(TcpConnection $connection)
    {
        $this->connection = $connection;
    }

    public function id(): int
    {
        return (int) $this->connection->id;
    }

    public function send(string $text): void
    {
        $this->connection->send($text);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function remoteIp(): ?string
    {
        return $this->connection->getRemoteIp() ?: null;
    }
}
