<?php

$current = (float)$argv[1];
$baselineFile = '.github/coverage-baseline.json';

// Ensure baseline file exists
if (!file_exists($baselineFile)) {
    if (!is_dir('.github')) mkdir('.github', 0777, true);
    file_put_contents($baselineFile, json_encode(['coverage' => 0, 'exempt' => 90], JSON_PRETTY_PRINT));
}

$data = json_decode(file_get_contents($baselineFile), true);
$baseline = (float)($data['coverage'] ?? 0);
$exempt = (float)($data['exempt'] ?? 90);

echo "Baseline coverage: {$baseline}%\n";
echo "Current coverage: {$current}%\n";

if ($current < $exempt && $current < $baseline) {
    echo "Current coverage of $current% is less than the baseline coverage of $baseline%  ❌\n";
    exit(1);
}

echo "Coverage OK ✅\n";
