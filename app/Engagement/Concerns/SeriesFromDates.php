<?php

namespace App\Engagement\Concerns;

use Carbon\CarbonImmutable;
use Whilesmart\Engagement\Support\Period;

trait SeriesFromDates
{
    /**
     * Bucket a set of timestamps into a zero-filled {date, value} series over
     * the period so gaps render as 0 rather than missing points.
     *
     * @return array<int, array{date: string, value: int}>
     */
    protected function seriesRows(iterable $dates, Period $period): array
    {
        $format = $period->bucketFormat();

        $counts = [];
        foreach ($dates as $date) {
            $bucket = CarbonImmutable::parse($date)->format($format);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        $rows = [];
        foreach ($period->buckets() as $bucketStart) {
            $bucket = $bucketStart->format($format);
            $rows[] = ['date' => $bucket, 'value' => $counts[$bucket] ?? 0];
        }

        return $rows;
    }
}
