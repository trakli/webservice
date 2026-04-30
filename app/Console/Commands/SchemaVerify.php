<?php

namespace App\Console\Commands;

use App\Services\SchemaConformance\SchemaConformanceService;
use Illuminate\Console\Command;

class SchemaVerify extends Command
{
    protected $signature = 'schema:verify';

    protected $description = 'Verify the live database schema matches every feature\'s declared spec in config/schema.php';

    public function handle(SchemaConformanceService $service): int
    {
        $problems = $service->verify();

        if (empty($problems)) {
            $this->info('Schema is conformant.');

            return self::SUCCESS;
        }

        $this->error('Schema drift detected:');
        foreach ($problems as $problem) {
            $this->line("  - [{$problem['kind']}] {$problem['detail']}");
        }

        $this->newLine();
        $this->warn('Run `php artisan schema:conform` to apply additive fixes.');

        return self::FAILURE;
    }
}
