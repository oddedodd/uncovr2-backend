<?php

return [
    'public_per_minute' => max(1, (int) env('RATE_LIMIT_PUBLIC_PER_MINUTE', 60)),

    'authenticated_per_minute' => max(1, (int) env('RATE_LIMIT_AUTHENTICATED_PER_MINUTE', 120)),

    'authentication_per_ip_per_minute' => max(
        1,
        (int) env('RATE_LIMIT_AUTHENTICATION_PER_IP_PER_MINUTE', 10),
    ),

    'authentication_per_identity_per_minute' => max(
        1,
        (int) env('RATE_LIMIT_AUTHENTICATION_PER_IDENTITY_PER_MINUTE', 5),
    ),
];
