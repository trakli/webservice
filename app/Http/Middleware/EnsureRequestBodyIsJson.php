<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRequestBodyIsJson
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'DELETE'])) {
            return $next($request);
        }

        if (str_starts_with($request->header('Content-Type', ''), 'application/json')) {
            return $next($request);
        }

        if ($request->header('Content-Type') === 'application/json') {
            return $next($request);
        }

        return response()->json([
            'error' => 'Unsupported Media Type',
            'message' => __('The Content-Type header must be application/json for this request.'),
        ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }
}
