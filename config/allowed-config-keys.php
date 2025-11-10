<?php

return [
    // 'keys' => [
    //     'default-wallet',
    //     'default-currency',
    //     'default-group',
    //     'default-lang',
    //     'onboarding-complete',
    //     'theme',
    // ],
    'keys' => explode(',', env('MODELS_ALLOWED_CONFIGS', 'default-wallet,default-currency,default-group,default-lang,onboarding-complete,theme')),
];
