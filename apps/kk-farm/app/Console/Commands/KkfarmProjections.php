<?php

namespace App\Console\Commands;

use App\Support\Projections;
use Illuminate\Console\Command;

class KkfarmProjections extends Command
{
    protected $signature = 'kkfarm:projections {--rebuild : Repair cached state so it matches the events}';

    protected $description = 'Check (or rebuild) cached state against the immutable event history (D-038).';

    public function handle(): int
    {
        $drift = Projections::drift();
        $count = array_sum(array_map('count', $drift));
        foreach ($drift as $name => $rows) {
            $this->line(sprintf('%-15s %d row(s) differ from the events', $name, count($rows)));
        }

        if ($count === 0) {
            $this->info('Cached state matches the events.');

            return self::SUCCESS;
        }

        if (! $this->option('rebuild')) {
            $this->warn('Run with --rebuild to repair.');

            return self::FAILURE;
        }

        $this->table(['cache', 'rows repaired'], collect(Projections::rebuild())->map(fn ($n, $k) => [$k, $n])->values());

        return self::SUCCESS;
    }
}
