<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalHealthService;
use Illuminate\Console\Command;

class CheckOperations extends Command
{
    protected $signature = 'operations:check {--json : Emit machine-readable output} {--no-alert : Do not send alert logs}';

    protected $description = 'Check queue and transactional email operational thresholds';

    public function handle(OperationalHealthService $health): int
    {
        $result = $health->check(! $this->option('no-alert'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Status', strtoupper($result['status']));

            foreach ($result['metrics'] as $name => $value) {
                $this->components->twoColumnDetail($name, (string) $value);
            }
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
