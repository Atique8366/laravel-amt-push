<?php

namespace BiometricPush\AmtPush\Exceptions;

/** A write was requested for a device whose read_only guard is on. */
class ReadOnlyDeviceException extends \DomainException
{
    public static function forDevice(string $serialNumber): self
    {
        return new self("Device {$serialNumber} is read-only: only reading requests are allowed. Turn read_only off to change it.");
    }
}
