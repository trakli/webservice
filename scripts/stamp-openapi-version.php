<?php

// Stamps the application version from composer.json (the single source of truth)
// into a generated OpenAPI spec, so the published docs report the current
// version instead of the placeholder baked into the annotation. Run by the
// "openapi" and "openapi:test" composer scripts after generation.

$file = $argv[1] ?? __DIR__ . '/../public/docs/api.json';

$composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
$version = $composer['version'] ?? '0.0.0';

$json = (string) file_get_contents($file);
$json = preg_replace_callback(
    '/("info"\s*:\s*\{[^}]*?"version"\s*:\s*")[^"]*(")/s',
    static fn (array $matches): string => $matches[1] . $version . $matches[2],
    $json,
    1
);

file_put_contents($file, $json);
