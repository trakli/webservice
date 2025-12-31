<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    protected array $supportedLocales = ['en', 'fr', 'es', 'de', 'pt', 'it'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->getPreferredLocale($request);
        app()->setLocale($locale);

        return $next($request);
    }

    protected function getPreferredLocale(Request $request): string
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (! $acceptLanguage) {
            return config('app.locale', 'en');
        }

        $locales = $this->parseAcceptLanguage($acceptLanguage);

        foreach ($locales as $locale) {
            $shortLocale = substr($locale, 0, 2);
            if (in_array($shortLocale, $this->supportedLocales)) {
                return $shortLocale;
            }
        }

        return config('app.locale', 'en');
    }

    protected function parseAcceptLanguage(string $header): array
    {
        $locales = [];
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $part = trim($part);
            if (str_contains($part, ';q=')) {
                [$locale, $quality] = explode(';q=', $part);
                $locales[(float) $quality] = trim($locale);
            } else {
                $locales[1.0] = $part;
            }
        }

        krsort($locales);

        return array_values($locales);
    }
}
