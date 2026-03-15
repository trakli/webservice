<?php

$coverageFile = $argv[1] ?? 'reports/coverage/coverage.xml';

$xml = simplexml_load_file($coverageFile);
$metrics = $xml->project->metrics;

$covered = (float)$metrics['coveredstatements'];
$total = (float)$metrics['statements'];

$coverage = $total > 0 ? ($covered / $total) * 100 : 0;
echo round($coverage, 2);
