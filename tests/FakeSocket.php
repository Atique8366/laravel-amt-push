<?php

namespace BiometricPush\AmtPush\Tests;

use BiometricPush\AmtPush\Server\Socket;

class FakeSocket implements Socket
{
    private static $nextId = 1;

    /** @var int */
    private $id;

    /** @var string[] everything the server sent */
    public $sent = [];

    /** @var bool */
    public $closed = false;

    public function __construct()
    {
        $this->id = self::$nextId++;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function send(string $text): void
    {
        $this->sent[] = $text;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function remoteIp(): ?string
    {
        return '192.168.1.50';
    }
}
