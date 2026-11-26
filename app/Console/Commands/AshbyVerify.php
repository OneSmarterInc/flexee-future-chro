<?php

namespace App\Console\Commands;

use App\Ashby\Verify\Battery;
use Illuminate\Console\Command;

/**
 * Runs the battery of checks on this server and prints what was expected and what came out, so whoever releases
 * can show the numbers are right. `--database` also runs a throwaway class through the real pipeline on this
 * database and rolls it back.
 */
class AshbyVerify extends Command
{
    protected $signature = 'ashby:verify {--database : Also run a throwaway class through the pipeline on this database (rolled back)}';

    protected $description = 'Check the engine, the lessons and the pipeline against the reference model';

    public function handle(Battery $battery): int
    {
        $this->line('Future CHRO verification · '.config('app.env').' · '.now()->format('Y-m-d H:i T').' · '.trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: 'unknown commit'));
        $checks = $battery->run((bool) $this->option('database'));
        $group = '';
        $n = 0;
        $failed = 0;
        foreach ($checks as $c) {
            if ($c['group'] !== $group) {
                $group = $c['group'];
                $this->newLine();
                $this->line("<options=bold>$group</>");
            }
            $n++;
            $failed += $c['pass'] ? 0 : 1;
            $mark = $c['pass'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
            $detail = $c['pass'] ? $c['actual'] : "expected {$c['expected']}, got {$c['actual']}";
            $this->line(sprintf('  %s  %2d. %s  <fg=gray>→ %s</>', $mark, $n, $c['name'], $detail));
        }
        $this->newLine();
        if ($failed === 0) {
            $this->info("All $n checks passed.");

            return self::SUCCESS;
        }
        $this->error("$failed of $n checks failed. Send this whole output to Vikram.");

        return self::FAILURE;
    }
}
