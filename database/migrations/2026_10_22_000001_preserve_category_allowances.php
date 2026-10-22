<?php

use App\Models\Category;
use App\Models\User;
use App\Support\CategoryAllowance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $hasProvenance = Schema::hasColumn('categories', 'provenance');
        User::query()->chunkById(100, function ($users) use ($hasProvenance) {
            foreach ($users as $user) {
                DB::transaction(function () use ($user, $hasProvenance) {
                    $owner = User::whereKey($user->getKey())->lockForUpdate()->first();
                    if ($owner === null) {
                        return;
                    }

                    $ids = Category::withTrashed()->where('user_id', $owner->getKey())
                        ->when($hasProvenance, fn ($query) => $query->whereIn('provenance', ['seeded', 'grandfathered']))
                        ->pluck('id')->all();
                    app(CategoryAllowance::class)->initialize($owner, $ids);
                });
            }
        });
    }

    public function down(): void
    {
    }
};
