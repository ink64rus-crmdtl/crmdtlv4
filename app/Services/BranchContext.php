<?php

namespace App\Services;

class BranchContext
{
    protected static ?int $branchId = null;

    public static function set(?int $branchId): void
    {
        self::$branchId = $branchId;
    }

    public static function current(): ?int
    {
        return self::$branchId;
    }

    public static function clear(): void
    {
        self::$branchId = null;
    }
}