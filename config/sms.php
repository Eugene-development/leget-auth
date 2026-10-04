<?php

return [
    // No live provider yet. Never expose a test code in production.
    'driver' => env('SMS_AUTH_DRIVER', 'test'),
];
