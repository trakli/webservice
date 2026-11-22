<?php

namespace App\Http\Middleware;

use App\Enums\StreakType;
use App\Services\StreakService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RecordCheckInStreak
{
    public function __construct(
        private readonly StreakService $streaks,
    ) {
    }

    /**
     * A check-in is a day the user reached the API at all. The cache key holds
     * the day, so a busy user costs one cache read per request rather than a
     * write.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $key = "streak:check-in:{$user->getKey()}:" . $this->streaks->localDate($user);

            if (Cache::add($key, true, now()->addDay())) {
                $this->streaks->track($user, StreakType::CHECK_IN);
            }
        }

        return $next($request);
    }
}
