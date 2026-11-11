<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-204: a waste route and the disposal method set by a qualified adviser. */
class WasteStream extends Model
{
    protected $guarded = [];

    protected $casts = ['advised_on' => 'date', 'active' => 'boolean'];

    public const KINDS = ['manure' => 'Manure & bedding', 'wastewater' => 'Wastewater / wash water', 'sharps' => 'Needles & sharps', 'medical' => 'Medical waste (vials, gloves)',
        'carcass' => 'Carcasses', 'placenta' => 'Afterbirth & aborted material', 'feed_waste' => 'Spoiled feed', 'containers' => 'Chemical containers', 'general' => 'General waste'];

    public const METHODS = ['compost' => 'Composting', 'spread' => 'Field spreading', 'biogas' => 'Biogas plant', 'sold' => 'Sold / given as manure', 'contractor' => 'Licensed contractor pickup',
        'burial' => 'Burial pit', 'incineration' => 'Incineration', 'rendering' => 'Rendering plant', 'lagoon' => 'Settling lagoon / soak pit', 'sharps_box' => 'Sharps box to contractor', 'return' => 'Return to supplier'];

    /** Biological waste: personal protection and disinfection must be confirmed on every disposal. */
    public const BIOHAZARD = ['sharps', 'medical', 'carcass', 'placenta'];

    public function approved(): bool
    {
        return $this->active && $this->adviser && $this->advised_on;
    }

    public function disposals()
    {
        return $this->hasMany(WasteDisposal::class, 'stream_id');
    }
}
