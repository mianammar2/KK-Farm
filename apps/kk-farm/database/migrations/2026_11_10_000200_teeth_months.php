<?php

use App\Support\Classification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** D-092: the teeth stages read in months only, as every age on the farm. */
return new class extends Migration
{
    public function up(): void
    {
        $this->labels(Classification::TEETH);
    }

    public function down(): void
    {
        $this->labels(['milk' => 'Milk teeth only (under 1 year)', '2' => '2 teeth (1 to 1½ years)', '4' => '4 teeth (1½ to 2 years)', '6' => '6 teeth (2½ to 3 years)', '8' => '8 teeth, full mouth (3 to 4 years)', 'worn' => 'Worn or broken mouth (5+ years)']);
    }

    private function labels(array $teeth): void
    {
        $options = json_encode(collect($teeth)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values());
        DB::table('field_definitions')->where('key', 'teeth')->where('builtin', true)->update(['options' => $options]);
    }
};
