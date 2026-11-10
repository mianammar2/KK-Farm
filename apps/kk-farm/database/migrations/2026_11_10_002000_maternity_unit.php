<?php

use App\Support\Maternity;
use Illuminate\Database\Migrations\Migration;

/** D-163: every farm gets its Maternity Unit (MTU) with the pre-kidding, kidding, post-kidding, neonatal and isolation pens. */
return new class extends Migration
{
    public function up(): void
    {
        Maternity::seedAll();
    }

    public function down(): void
    {
        // Pens and sheds are farm records: they stay (removing them would orphan movements).
    }
};
