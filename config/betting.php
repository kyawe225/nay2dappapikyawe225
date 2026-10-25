<?php

return [
    'enforce_cutoffs' => env('BETTING_ENFORCE_CUTOFFS', false),
    'timezone' => env('BETTING_TIMEZONE', 'Asia/Yangon'),
    'cutoffs' => [
        'twod' => ['am' => '11:55', 'pm' => '15:55'],
        'threed' => ['pm' => '14:00'],
    ],
];
