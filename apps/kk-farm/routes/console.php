<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * D-164: the maternity round for every organization: does near kidding go to the Pre-Kidding Unit, does after a
 * delivery or loss and their kids go into the Maternity Unit. Runs every hour (scheduler started by the entrypoint).
 */
Artisan::command('kkfarm:maternity-round', function () {
    foreach (\Illuminate\Support\Facades\DB::table('organizations')->pluck('id') as $orgId) {
        \App\Support\Tenant::enter($orgId);
        try {
            $moved = \App\Support\Maternity::sweep();
            if ($moved['preKidding'] || $moved['afterOutcome']) {
                $this->info("{$orgId}: pre-kidding ".implode(', ', $moved['preKidding']).' · after outcome '.implode(', ', $moved['afterOutcome']));
            }
        } finally {
            \App\Support\Tenant::leave();
        }
    }
})->purpose('Move does near kidding to the Pre-Kidding Unit and does after an outcome (with kids) into the Maternity Unit');

\Illuminate\Support\Facades\Schedule::command('kkfarm:maternity-round')->hourly()->withoutOverlapping();

/* D-231: breeding eligibility moves on with age: each day, newly qualified goats get their approval task. */
Artisan::command('kkfarm:eligibility-round', function () {
    foreach (\Illuminate\Support\Facades\DB::table('organizations')->pluck('id') as $orgId) {
        \App\Support\Tenant::enter((string) $orgId);
        try {
            \App\Support\Eligibility::sync();
        } finally {
            \App\Support\Tenant::leave();
        }
    }
})->purpose('Create breeding approval tasks for goats that have become qualified');

\Illuminate\Support\Facades\Schedule::command('kkfarm:eligibility-round')->dailyAt('05:30')->withoutOverlapping();
