<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\StreakType;
use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        // update user streak
        $user->updateStreak(StreakType::APP_CHECK_IN);

        return $this->success($user);
    }
}
