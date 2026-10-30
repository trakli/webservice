<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $this->renameIfPresent('workspace_transaction_categorys', 'workspace_transaction_categories');
    }

    public function down(): void
    {
        $this->renameIfPresent('workspace_transaction_categories', 'workspace_transaction_categorys');
    }

    private function renameIfPresent(string $from, string $to): void
    {
        if (! Schema::hasTable($from)) {
            return;
        }
        if (Schema::hasTable($to)) {
            throw new RuntimeException('Both workspace category link tables exist. Reconcile them before migrating.');
        }
        Schema::rename($from, $to);
    }
};
