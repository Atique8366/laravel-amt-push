<?php

namespace BiometricPush\AmtPush\Protocol;

/**
 * One incoming XML frame from a device.
 *
 *   <Message><Request>Register|Login</Request>…</Message>      device asks
 *   <Message><Event>TimeLog_v2|AdminLog_v2|KeepAlive</Event>…   device pushes
 *   <Message><Response>…</Response>…</Message>                  answer to our request
 */
class Frame
{
    /** @var \SimpleXMLElement */
    private $xml;

    /** @var string */
    public $raw;

    private function __construct(\SimpleXMLElement $xml, string $raw)
    {
        $this->xml = $xml;
        $this->raw = $raw;
    }

    /** @return self|null null when the text is not XML */
    public static function parse(string $raw): ?self
    {
        $raw = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
        if ($raw === '' || $raw[0] !== '<') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml instanceof \SimpleXMLElement ? new self($xml, $raw) : null;
    }

    public function root(): string
    {
        return $this->xml->getName();
    }

    /** Trimmed text of a child tag ('' when absent). *_Base36 aliases of UserID / AdminID are honoured. */
    public function get(string $tag): string
    {
        $value = isset($this->xml->{$tag}) ? trim((string) $this->xml->{$tag}) : '';
        if ($value === '' && in_array($tag, ['UserID', 'AdminID'], true) && isset($this->xml->{$tag . '_Base36'})) {
            $value = trim((string) $this->xml->{$tag . '_Base36'});
        }

        return $value;
    }

    public function opt(string $tag): ?string
    {
        $v = $this->get($tag);

        return $v === '' ? null : $v;
    }

    public function int(string $tag): ?int
    {
        $v = $this->get($tag);

        return $v !== '' && preg_match('/^-?\d+$/', $v) ? (int) $v : null;
    }

    public function yes(string $tag): bool
    {
        return in_array(strtolower($this->get($tag)), ['yes', '1', 'true'], true);
    }

    public function has(string $tag): bool
    {
        return isset($this->xml->{$tag});
    }

    /** request | event | response | null */
    public function type(): ?string
    {
        foreach (['Request' => 'request', 'Event' => 'event', 'Response' => 'response'] as $tag => $type) {
            if ($this->get($tag) !== '') {
                return $type;
            }
        }

        return null;
    }

    /** The Request / Event / Response name. */
    public function name(): string
    {
        foreach (['Request', 'Event', 'Response'] as $tag) {
            if (($v = $this->get($tag)) !== '') {
                return $v;
            }
        }

        return $this->root();
    }

    /** OK / 0 / Success → true. */
    public function isOk(): bool
    {
        return in_array(strtolower($this->get('Result')), ['ok', '0', 'success', 'succeeded'], true);
    }

    /** All child tags as [tag => value] (first occurrence). */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->xml->children() as $child) {
            $name = $child->getName();
            if (!array_key_exists($name, $out)) {
                $out[$name] = trim((string) $child);
            }
        }

        return $out;
    }
}
