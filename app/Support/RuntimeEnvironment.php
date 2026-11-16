<?php

namespace App\Support;

final class RuntimeEnvironment
{
    public static function isDevelopment(): bool
    {
        return in_array(config('app.env'), ['develop', 'development', 'local', 'testing'], true);
    }

    public static function isProduction(): bool
    {
        return config('app.env') === 'production';
    }
}
