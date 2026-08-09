<?php

return [
    'queue' => env('MAIL_QUEUE', 'emails'),
    'webhook' => [
        'secret' => env('RESEND_WEBHOOK_SECRET'),
        'url' => env('RESEND_WEBHOOK_URL'),
        'tolerance_seconds' => (int) env('RESEND_WEBHOOK_TOLERANCE_SECONDS', 300),
        'max_payload_bytes' => (int) env('RESEND_WEBHOOK_MAX_PAYLOAD_BYTES', 65536),
    ],
    'credential_rotation' => [
        'api_key_rotated_at' => env('RESEND_API_KEY_ROTATED_AT'),
        'webhook_secret_rotated_at' => env('RESEND_WEBHOOK_SECRET_ROTATED_AT'),
        'max_age_days' => (int) env('RESEND_CREDENTIAL_MAX_AGE_DAYS', 90),
    ],
];
