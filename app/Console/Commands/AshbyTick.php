<?php

namespace App\Console\Commands;

use App\Ashby\Admin\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Opens and closes years on the class calendars that run on their own. Runs every few minutes. */
class AshbyTick extends Command
{
    protected $signature = 'ashby:tick';

    protected $description = 'Open and close decision years whose calendar times have come';

    public function handle(Calendar $calendar): int
    {
        foreach ($calendar->tick(CarbonImmutable::now()) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
