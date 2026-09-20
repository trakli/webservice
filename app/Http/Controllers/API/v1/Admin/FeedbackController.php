<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Whilesmart\Feedback\Enums\FeedbackStatus;
use Whilesmart\Feedback\Http\Resources\FeedbackResource;
use Whilesmart\Feedback\Models\Feedback;

class FeedbackController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Feedback::query()->latest();
        foreach (['status', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }
        if ($request->filled('q')) {
            $term = '%' . strtolower($request->string('q')) . '%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(message) like ?', [$term])
                ->orWhereRaw('lower(subject) like ?', [$term])
                ->orWhereRaw('lower(email) like ?', [$term]));
        }

        return $this->success(
            FeedbackResource::collection($query->paginate((int) $request->input('per_page', 25)))
                ->response()->getData(true),
        );
    }

    public function update(Request $request, Feedback $feedback): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(FeedbackStatus::values())],
        ]);
        $feedback->update($data);

        return $this->success(new FeedbackResource($feedback->fresh()));
    }
}
