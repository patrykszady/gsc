<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ShortLink extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'code',
        'url',
        'clicks',
    ];

    /* ------------------------------------------------------------------ */
    /*  Short code generation                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Create a short link for the given URL.
     * Returns existing record if the URL was already shortened.
     */
    public static function shorten(string $url): self
    {
        return static::firstOrCreate(
            ['url' => $url],
            ['code' => static::generateUniqueCode()],
        );
    }

    /**
     * Generate a unique 6-character alphanumeric code.
     */
    public static function generateUniqueCode(int $length = 6): string
    {
        do {
            $code = Str::random($length);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /* ------------------------------------------------------------------ */
    /*  URL helpers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Get the full short URL (e.g. https://gs.construction/s/Xk9m2P).
     */
    public function getShortUrlAttribute(): string
    {
        $domain = rtrim((string) config('app.url'), '/');

        return "{$domain}/s/{$this->code}";
    }

    /**
     * Increment the click counter.
     */
    public function recordClick(): void
    {
        $this->increment('clicks');
    }
}
