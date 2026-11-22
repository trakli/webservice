<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Whilesmart\Feedback\Http\Resources\FeedbackResource;
use Whilesmart\Feedback\Models\Feedback;

class FeedbackController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $items = Feedback::query()
            ->where('owner_type', User::class)
            ->where('owner_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->success(FeedbackResource::collection($items));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['general', 'bug', 'feature', 'question'])],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:2', 'max:5000'],
        ]);
        $user = $request->user();
        $feedback = Feedback::create([
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'form_key' => 'support',
            'type' => $data['type'],
            'status' => 'new',
            'name' => trim($user->first_name . ' ' . $user->last_name),
            'email' => $user->email,
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'],
        ]);

        return $this->success(new FeedbackResource($feedback), __('Thanks, your feedback reached the team.'), 201);
    }
}
