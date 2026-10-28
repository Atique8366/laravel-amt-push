<?php

namespace BiometricPush\AmtPush\Protocol;

/**
 * Builds the <Message> XML sent to devices (DOMDocument, as the reference SDK does).
 *
 *   MessageBuilder::request('SetTime')->add('Time', $t)->toXml();
 */
class MessageBuilder
{
    /** @var \DOMDocument */
    private $doc;

    /** @var \DOMElement */
    private $root;

    public function __construct()
    {
        $this->doc = new \DOMDocument('1.0');
        $this->doc->formatOutput = true;
        $this->root = $this->doc->createElement('Message');
        $this->doc->appendChild($this->root);
    }

    public static function request(string $name): self
    {
        return (new self())->add('Request', $name);
    }

    public static function response(string $name): self
    {
        return (new self())->add('Response', $name);
    }

    /** <Tag>value</Tag>; null values are left out. */
    public function add(string $tag, $value): self
    {
        if ($value === null) {
            return $this;
        }
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }
        $element = $this->doc->createElement($tag);
        $element->appendChild($this->doc->createTextNode((string) $value));
        $this->root->appendChild($element);

        return $this;
    }

    public function addIf(bool $condition, string $tag, $value): self
    {
        return $condition ? $this->add($tag, $value) : $this;
    }

    public function toXml(): string
    {
        return (string) $this->doc->saveXML();
    }

    public function __toString()
    {
        return $this->toXml();
    }
}
