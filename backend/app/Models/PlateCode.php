<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the OM plate-code dictionary: a numeric code (OM PlateColorNo / EmNo) and the
 * plate symbol/letter it renders as (F, H, P, CC …). A vehicle's plate_code joins here so we
 * display "CC 43460" and never the raw numeric code.
 */
class PlateCode extends Model
{
    protected $fillable = ['em_no', 'letter_en', 'letter_ar'];

    /** code (int) => letter_en, for cheap in-memory lookups during resolution/display. */
    public static function letterMap(): array
    {
        return static::query()->pluck('letter_en', 'em_no')->all();
    }

    /** Render a plate for display: "<letter> <digits>", falling back to digits alone. */
    public static function display(?string $code, ?string $digits): string
    {
        $digits = (string) $digits;
        if ($code === null || $code === '') {
            return $digits;
        }
        $letter = static::query()->where('em_no', $code)->value('letter_en');

        return $letter ? trim($letter . ' ' . $digits) : $digits;
    }
}
