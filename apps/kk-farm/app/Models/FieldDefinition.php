<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One field of the goat registration form for one organization (D-040). */
class FieldDefinition extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['options' => 'array', 'required' => 'boolean', 'builtin' => 'boolean', 'active' => 'boolean'];

    /** @return array<string, string> value => label, for select fields */
    public function optionMap(): array
    {
        return collect($this->options ?? [])->mapWithKeys(fn (array $o) => [$o['value'] => $o['label']])->all();
    }
}
