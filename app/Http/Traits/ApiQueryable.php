<?php

namespace App\Http\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Whilesmart\LaravelUserAuthentication\Traits\ApiResponse;

trait ApiQueryable
{
    use ApiResponse;

    protected function applyApiQuery(Request $request, Builder|Relation $query): array
    {
        $last_synced = now();
        $limit = $request->query('limit', 20);

        if ($request->has('sync_from')) {
            try {
                $date = Carbon::parse($request->sync_from);
                $query->where('updated_at', '>=', $date);
            } catch (\Exception $exception) {
                throw new \InvalidArgumentException('Invalid date format for sync_from parameter.');
            }
        }

        $paginatedResults = $query->paginate($limit);

        return [
            'data' => $paginatedResults->items(),
            'last_sync' => $last_synced,
            'current_page' => $paginatedResults->currentPage(),
            'total' => $paginatedResults->total(),
            'per_page' => $paginatedResults->perPage(),
            'last_page' => $paginatedResults->lastPage(),
        ];
    }
}
