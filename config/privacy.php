<?php

return [
    'terms_version' => env('PRIVACY_TERMS_VERSION', '2026-08-09'),
    'privacy_version' => env('PRIVACY_POLICY_VERSION', '2026-08-09'),
    'deletion_grace_days' => max(1, (int) env('PRIVACY_DELETION_GRACE_DAYS', 30)),
];
