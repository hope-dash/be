<?php

namespace App\Libraries;

final class TenantContext
{
    private static ?array $tenant = null;

    public static function set(?array $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function get(): ?array
    {
        return self::$tenant;
    }

    public static function hasTenant(): bool
    {
        return !empty(self::$tenant) && isset(self::$tenant['id']);
    }

    public static function id(): int
    {
        if (!self::hasTenant()) {
            return 0;
        }

        return (int) self::$tenant['id'];
    }

    public static function code(): ?string
    {
        return self::$tenant['code'] ?? null;
    }
}

