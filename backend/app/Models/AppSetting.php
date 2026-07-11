<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single runtime-adjustable setting (key → JSON value). The UI-editable counterpart to the static
 * config/*.php tunables: change it from a settings panel and it takes effect immediately, no redeploy.
 *
 * Read with AppSetting::get($key, $default) and write with AppSetting::put($key, $value). The `value`
 * column is JSON-cast so it holds a scalar (int/string/bool) or a list (e.g. excluded dates) the same
 * way. Defaults live in code at the call site — an absent row simply returns the passed default, so
 * the feature works on a fresh install with no seeding.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /** Read a setting, returning $default when the key has never been set. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    /** Create or update a setting and return the stored value. */
    public static function put(string $key, mixed $value): mixed
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        return $value;
    }
}
