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

    public static function name(): string
    {
        return self::$tenant['name'] ?? env('email.fromName', 'System Administrator');
    }

    public static function email(): string
    {
        return self::$tenant['email'] ?? env('email.fromEmail', 'admin@hopesparepart.com');
    }

    public static function url(): string
    {
        return self::$tenant['url'] ?? env('app.baseURL', 'https://api.hopesparepart.com');
    }
}

