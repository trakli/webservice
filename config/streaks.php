<?php

return [
    // A streak is only worth telling anyone about once it reaches this length.
    'threshold' => env('STREAKS_THRESHOLD', 3),

    // Lengths worth an email. The threshold is always included.
    'milestones' => [3, 7, 14, 30, 60, 100],

    'mail' => [
        'enabled' => env('STREAKS_MAIL_ENABLED', true),

        // Check-ins are counted but not mailed: a day that earns both streaks
        // would otherwise send two near-identical messages.
        'types' => ['transaction'],
    ],
];
