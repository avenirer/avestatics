<?php
namespace Avenirer\AveStatics;

class BuildOptions
{
    /** @var bool */
    private static $force = false;

    public static function setForce(bool $force): void
    {
        self::$force = $force;
    }

    public static function isForce(): bool
    {
        return self::$force;
    }
}
