<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key, with an optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting_{$key}", 3600, function () use ($key, $default) {
            $value = static::where('key', $key)->value('value');

            return $value !== null ? $value : $default;
        });
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
