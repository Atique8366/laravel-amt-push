<?php

namespace BiometricPush\AmtPush\Tests\Support;

/** A minimal RFC 6455 client, playing the attendance terminal against the real server. */
class WsClient
{
    /** @var resource */
    private $stream;

    /** @var string */
    private $buffer = '';

    public static function connect(string $host, int $port, float $timeout = 2.0): ?self
    {
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout);
        if (!$stream) {
            return null;
        }
        $key = base64_encode(random_bytes(16));
        fwrite($stream, "GET / HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $client = new self();
        $client->stream = $stream;
        $head = $client->readUntil("\r\n\r\n", 5.0);
        if ($head === null || strpos($head, ' 101 ') === false) {
            fclose($stream);
            return null;
        }

        return $client;
    }

    public function send(string $text): void
    {
        $length = strlen($text);
        $header = chr(0x81);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('NN', 0, $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $text[$i] ^ $mask[$i % 4];
        }
        fwrite($this->stream, $header . $mask . $masked);
    }

    /** Next text frame, or null after $timeout seconds. */
    public function receive(float $timeout = 5.0): ?string
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (($frame = $this->takeFrame()) !== null) {
                list($opcode, $payload) = $frame;
                if ($opcode === 0x1) {
                    return $payload;
                }
                if ($opcode === 0x9) {
                    fwrite($this->stream, chr(0x8A) . chr(0x80 | strlen($payload)) . "\0\0\0\0" . $payload);   // pong
                }
                continue;
            }
            $this->fill($deadline - microtime(true));
        }

        return null;
    }

    /** Next frame whose XML contains $needle (other frames are dropped). */
    public function receiveContaining(string $needle, float $timeout = 8.0): ?string
    {
        $deadline = microtime(true) + $timeout;
        while (($left = $deadline - microtime(true)) > 0) {
            $text = $this->receive($left);
            if ($text !== null && strpos($text, $needle) !== false) {
                return $text;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, chr(0x88) . chr(0x80) . "\0\0\0\0");
            fclose($this->stream);
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /** @return array|null [opcode, payload] */
    private function takeFrame(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }
        $opcode = ord($this->buffer[0]) & 0x0F;
        $length = ord($this->buffer[1]) & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }
            $length = unpack('N', substr($this->buffer, 6, 4))[1];
            $offset = 10;
        }
        if (strlen($this->buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = (string) substr($this->buffer, $offset + $length);

        return [$opcode, $payload];
    }

    private function fill(float $timeout): void
    {
        if ($timeout <= 0) {
            return;
        }
        $read = [$this->stream];
        $write = $except = null;
        $sec = (int) floor($timeout);
        if (@stream_select($read, $write, $except, $sec, (int) (($timeout - $sec) * 1000000)) > 0) {
            $chunk = fread($this->stream, 65536);
            if ($chunk !== false) {
                $this->buffer .= $chunk;
            }
        }
    }

    private function readUntil(string $marker, float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        while (($pos = strpos($this->buffer, $marker)) === false) {
            if (microtime(true) >= $deadline || feof($this->stream)) {
                return null;
            }
            $this->fill($deadline - microtime(true));
        }
        $head = substr($this->buffer, 0, $pos);
        $this->buffer = (string) substr($this->buffer, $pos + strlen($marker));

        return $head;
    }
}
