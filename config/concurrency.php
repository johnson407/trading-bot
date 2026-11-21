<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Concurrency Driver
    |--------------------------------------------------------------------------
    |
    | The process driver runs independent tasks in forked processes. Tests use
    | the synchronous driver so work stays inside the test database transaction.
    |
    */

    'default' => env('CONCURRENCY_DRIVER', 'process'),
];
