<?php

$current = (float)$argv[1];
$baselineFile = '.github/coverage-baseline.json';

if (!is_dir('.github')) mkdir('.github', 0777, true);

if (file_exists($baselineFile)) {
    $data = json_decode(file_get_contents($baselineFile), true);
}


$data['coverage'] = round($current, 2);
$data['updated_at'] = gmdate('c');
$data['exempt'] = $data['exempt'] ?? 90;

file_put_contents($baselineFile, json_encode($data, JSON_PRETTY_PRINT));

echo "Coverage baseline updated to {$current}%\n";
