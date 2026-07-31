<?php

namespace App\Services;

class LegalEntityContext
{
    protected static ?int $legalEntityId = null;

    public static function set(?int $legalEntityId): void
    {
        self::$legalEntityId = $legalEntityId;
    }

    public static function current(): ?int
    {
        return self::$legalEntityId;
    }

    public static function clear(): void
    {
        self::$legalEntityId = null;
    }
}