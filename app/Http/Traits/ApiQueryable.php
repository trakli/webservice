<?php

namespace App\Http\Traits;

use App\Services\FileService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Whilesmart\UserAuthentication\Traits\ApiResponse;

trait ApiQueryable
{
    use ApiResponse;

    protected function applyApiQuery(Request $request, Builder|Relation $query): array
    {

        $limit = $request->query('limit', 20);

        if ($request->has('synced_since')) {
            try {
                $date = Carbon::parse($request->synced_since);
                $query = $query->where('updated_at', '>=', $date)->withTrashed();
            } catch (\Exception $exception) {
                throw new \InvalidArgumentException('Invalid date format for synced_since parameter.');
            }
        }
        if ($request->has('no_client_id')) {
            $query = $query->whereHas('syncState', function ($q) {
                $q->whereNull('client_generated_id');
            });
        }

        $paginatedResults = $query->paginate($limit);
        $query_completed_at = now();
        $last_synced_date = collect($paginatedResults->items())->max('updated_at');
        if ($last_synced_date === null && $request->synced_since !== null) {
            $last_synced_date = $request->synced_since;
        } elseif ($last_synced_date === null) {
            $last_synced_date = $query_completed_at;
        }

        return [
            'data' => $paginatedResults->items(),
            'last_sync' => $last_synced_date,
            'current_page' => $paginatedResults->currentPage(),
            'total' => $paginatedResults->total(),
            'per_page' => $paginatedResults->perPage(),
            'last_page' => $paginatedResults->lastPage(),
        ];
    }

    private function updateModel(Model $model, array $validatedData, Request $request): void
    {
        $model->update($validatedData);
        $user = $request->user();
        if (isset($request['client_id']) && ! $model->client_generated_id) {
            $model->setClientGeneratedId($request['client_id'], $user);
        }
        FileService::updateIcon($model, $validatedData, $request);
    }
}
