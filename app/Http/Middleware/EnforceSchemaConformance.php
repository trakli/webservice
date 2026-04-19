<?php

namespace App\Http\Middleware;

use App\Services\SchemaConformance\SchemaConformanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the app behind a schema conformance check. When SCHEMA_CONFORMANCE_ENFORCE
 * is true (default), any drift between config/schema.php and the live DB causes
 * a 503 response with the list of problems. The check result is cached for 60s
 * so it does not hit the DB on every request.
 *
 * Bypass in local dev by setting SCHEMA_CONFORMANCE_ENFORCE=false.
 */
class EnforceSchemaConformance
{
    private const CACHE_KEY = 'schema.conformance.problems';

    private const CACHE_TTL = 60;

    public function __construct(
        protected SchemaConformanceService $service,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('schema.enforce', true)) {
            return $next($request);
        }

        // Never gate the schema tooling itself — otherwise the fix is unreachable
        // when the app is already refusing to boot.
        if ($request->is('schema/*') || $request->is('api/v1/info')) {
            return $next($request);
        }

        $problems = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->service->verify();
        });

        if (empty($problems)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Database schema is not conformant. Run `php artisan schema:conform`.',
            'problems' => $problems,
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
