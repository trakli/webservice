<?php

namespace App\Console\Commands;

use App\Services\SchemaConformance\SchemaConformanceService;
use Illuminate\Console\Command;

class SchemaConform extends Command
{
    protected $signature = 'schema:conform {--dry-run : Show what would change without touching the DB}';

    protected $description = 'Apply any additive columns/indexes from config/schema.php that are missing from the live DB';

    public function handle(SchemaConformanceService $service): int
    {
        if ($this->option('dry-run')) {
            $problems = $service->verify();
            if (empty($problems)) {
                $this->info('Nothing to apply — schema is already conformant.');

                return self::SUCCESS;
            }
            $this->warn('Dry run — the following changes would be applied:');
            foreach ($problems as $p) {
                $this->line("  - [{$p['kind']}] {$p['detail']}");
            }

            return self::SUCCESS;
        }

        $applied = $service->conform();

        if (empty($applied)) {
            $this->info('Schema already conformant — no changes applied.');

            return self::SUCCESS;
        }

        $this->info('Applied:');
        foreach ($applied as $change) {
            $this->line("  + [{$change['kind']}] {$change['detail']}");
        }

        $remaining = $service->verify();
        if (! empty($remaining)) {
            $this->error('Some problems remain (likely missing tables — run `php artisan migrate`):');
            foreach ($remaining as $p) {
                $this->line("  - [{$p['kind']}] {$p['detail']}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
