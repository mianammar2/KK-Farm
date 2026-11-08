<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pen extends Model
{
    protected $guarded = [];

    /**
     * D-055: every pen is named "Pen …". Strips a typed prefix ("pen B", "p01", "Pen-3") and a trailing "pen"
     * ("Main pen"), then adds "Pen " once. Returns '' when nothing is left.
     */
    public static function standardName(?string $typed): string
    {
        $rest = preg_replace('/^\s*(pen\b|p(?=\d))[\s\-:_]*/i', '', (string) $typed);
        $rest = trim((string) preg_replace('/[\s\-_]*\bpen\s*$/i', '', (string) $rest));

        return $rest === '' ? '' : 'Pen '.(strlen($rest) === 1 ? strtoupper($rest) : $rest);
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
