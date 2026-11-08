<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pen extends Model
{
    protected $guarded = [];

    /**
     * D-057: a pen's own code is P + a two-digit number (P01, P02 …). Accepts what people type: "1", "01", "P1",
     * "pen 3", "Pen-03". Returns '' when there is no number.
     */
    public static function standardName(?string $typed): string
    {
        $rest = preg_replace('/^\s*(pen\b|p)[\s\-:_]*/i', '', (string) $typed);
        $rest = trim((string) preg_replace('/[\s\-_]*\bpen\s*$/i', '', (string) $rest));

        return ctype_digit($rest) && (int) $rest > 0 ? sprintf('P%02d', (int) $rest) : '';
    }

    /** "KKF-BDU-01-P01": the shed's name and the pen's code. Follows the shed if the shed is renamed. */
    public function fullName(): string
    {
        return $this->shed ? "{$this->shed->name}-{$this->name}" : $this->name;
    }

    public function shed()
    {
        return $this->belongsTo(Shed::class);
    }

    public function animals()
    {
        return $this->hasMany(Animal::class);
    }
}
