<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Whilesmart\Entitlements\Contracts\Entitlements;
use Whilesmart\Entitlements\Contracts\OwnerResolver;

class RequireFeature
{
    public function __construct(private Entitlements $entitlements, private OwnerResolver $owners)
    {
    }

    public function handle(Request $request, Closure $next, string $feature)
    {
        $result = $this->entitlements->check($this->owners->resolve($request), $feature);

        if (! $result->allowed) {
            return app(config('user-authentication.response_formatter'))->failure(
                'This feature requires a paid plan.',
                402,
                ['feature' => $feature, 'reason' => $result->reason],
            );
        }

        return $next($request);
    }
}
