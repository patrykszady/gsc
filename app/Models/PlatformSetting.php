<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::where('key', $key)->first();
        return $row?->value ?? $default;
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
