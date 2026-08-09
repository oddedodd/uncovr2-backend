<?php

return [
    'alert_channel' => env('OPERATIONS_ALERT_CHANNEL', 'stack'),
    'monitor_window_minutes' => (int) env('OPERATIONS_MONITOR_WINDOW_MINUTES', 60),
    'minimum_email_volume' => (int) env('OPERATIONS_MINIMUM_EMAIL_VOLUME', 20),
    'max_provider_failures' => (int) env('OPERATIONS_MAX_PROVIDER_FAILURES', 3),
    'max_queue_failures' => (int) env('OPERATIONS_MAX_QUEUE_FAILURES', 0),
    'max_bounce_rate' => (float) env('OPERATIONS_MAX_BOUNCE_RATE', 0.05),
    'max_complaint_rate' => (float) env('OPERATIONS_MAX_COMPLAINT_RATE', 0.001),
    'alert_cooldown_minutes' => (int) env('OPERATIONS_ALERT_COOLDOWN_MINUTES', 60),
];
