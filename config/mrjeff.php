<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Owner setup secret
    |--------------------------------------------------------------------------
    |
    | Mirrors OWNER_SETUP_SECRET from the Next.js app: anyone holding it can
    | bootstrap the first owner account via /setup, exactly once. When it is
    | empty the setup page refuses to run instead of allowing an open door.
    |
    */

    'setup_secret' => env('OWNER_SETUP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Low-stock highlighting
    |--------------------------------------------------------------------------
    |
    | Kept for parity with the original's visual thresholds; per-model
    | `low_stock_threshold` remains the number that actually drives alerts.
    |
    */
    'data_cache_seconds' => 30,
];
