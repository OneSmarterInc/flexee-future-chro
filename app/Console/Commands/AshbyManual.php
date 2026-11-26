<?php

namespace App\Console\Commands;

use App\Ashby\Help\Manual;
use Illuminate\Console\Command;

/** Checks the manuals against the product and keeps the What changed log current (H2, H4). */
class AshbyManual extends Command
{
    protected $signature = 'ashby:manual-log {--check : Report only, and fail when the manual is out of step}';

    protected $description = 'Check the manuals against the product and keep the What changed log current';

    public function handle(Manual $manual): int
    {
        $problems = $manual->problems();
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        if ($this->option('check')) {
            if (! $manual->logIsCurrent()) {
                $this->error('The What changed log is out of date. Run: php artisan ashby:manual-log');

                return self::FAILURE;
            }
            $this->info('The manuals agree with the product, and the What changed log is current.');

            return self::SUCCESS;
        }
        $moved = $manual->updateLog(now()->toDateString());
        $this->info($moved === [] ? 'Nothing changed.' : 'Logged: '.implode(', ', $moved));

        return self::SUCCESS;
    }
}
