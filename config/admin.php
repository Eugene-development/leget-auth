<?php

$emails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => strtolower(trim($email)),
    explode(',', (string) env('LEGET_ADMIN_EMAILS', '')),
))));

return [
    'emails' => $emails,
];
