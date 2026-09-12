<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key, with an optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::get("setting_{$key}");

        if ($cached !== null) {
            return $cached;
        }

        try {
            $value = static::where('key', $key)->value('value');
        } catch (\Throwable) {
            // Table may not exist yet (pending migration), or the database is
            // briefly unreachable. Returning the default is right; *caching* it
            // for an hour was not -- a blip during boot pinned the fallback
            // (rtrConfigured reading false, so RTR silently disabled) for the
            // whole hour, with nothing to indicate why.
            return $default;
        }

        if ($value === null) {
            // Nothing stored: hand back the default without caching it. An
            // absent setting costs one indexed lookup per call, which is
            // cheaper than an hour of wondering why the fallback is in force.
            return $default;
        }

        Cache::put("setting_{$key}", $value, 3600);

        return $value;
    }

    /**
     * Set a setting value. Passing null clears the value.
     */
    public static function set(string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            static::where('key', $key)->delete();
        } else {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget("setting_{$key}");
    }
}
