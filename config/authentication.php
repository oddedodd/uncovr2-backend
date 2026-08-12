<?php

return [
    'portal_url' => env('PORTAL_URL', 'http://localhost:5173'),

    'access_token_ttl_minutes' => max(
        5,
        (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 15),
    ),

    'refresh_token_idle_ttl_days' => max(
        1,
        (int) env('AUTH_REFRESH_TOKEN_IDLE_TTL_DAYS', 30),
    ),

    'mobile_session_absolute_ttl_days' => max(
        1,
        (int) env('AUTH_MOBILE_SESSION_ABSOLUTE_TTL_DAYS', 90),
    ),

    'portal_session_idle_ttl_minutes' => max(
        5,
        (int) env('SESSION_LIFETIME', 120),
    ),

    'portal_session_absolute_ttl_minutes' => max(
        60,
        (int) env('AUTH_PORTAL_SESSION_ABSOLUTE_TTL_MINUTES', 720),
    ),

    'portal_device_session_cache_seconds' => max(
        0,
        (int) env('AUTH_PORTAL_DEVICE_SESSION_CACHE_SECONDS', 30),
    ),

    /*
     * Bearer clients resolve their device session by id on every request. Revoking
     * a mobile session deletes its access tokens, so authentication already fails
     * before this cache is consulted; expiry is evaluated from the cached
     * timestamps and therefore stays exact. Set to 0 to always hit the database.
     */
    'bearer_device_session_cache_seconds' => max(
        0,
        (int) env('AUTH_BEARER_DEVICE_SESSION_CACHE_SECONDS', 30),
    ),

    'refresh_token_bytes' => 32,

    'refresh_token_prefix' => 'uncovr_refresh_',

    'refresh_token_retention_days' => max(
        1,
        (int) env('AUTH_REFRESH_TOKEN_RETENTION_DAYS', 30),
    ),
];
