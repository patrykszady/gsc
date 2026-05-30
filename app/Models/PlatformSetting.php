<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::where('key', $key)->first();

        if (! $row) {
            return $default;
        }

        try {
            return $row->value ?? $default;
        } catch (DecryptException $e) {
            // APP_KEY rotated or row written with a different key. Surface
            // the default instead of crashing the request; admin can re-save
            // the value to re-encrypt with the current key.
            Log::warning('PlatformSetting decryption failed; returning default.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::where('key', $key)->delete();
            return;
        }
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
