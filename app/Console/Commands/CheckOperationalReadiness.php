<?php

namespace App\Console\Commands;

use App\Services\OperationalReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:readiness {--json : Output a safe machine-readable report}')]
#[Description('Read-only production configuration and local runtime checks; no live service verification')]
class CheckOperationalReadiness extends Command
{
    public function handle(OperationalReadiness $readiness): int
    {
        $report = $readiness->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'State', 'Action'], array_map(fn ($row) => [$row['label'], $row['state'], $row['help']], $report['checks']));
            $this->line('Blocking checks: '.$report['blocking_count'].'. Configuration and local runtime only. Live providers, restore and deployment acceptance remain separate.');
        }

        return $report['checks_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
