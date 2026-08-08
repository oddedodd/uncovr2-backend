<?php

return [
    'private_bucket' => env('SUPABASE_STORAGE_PRIVATE_BUCKET', 'uncovr-private-media'),
    'public_bucket' => env('SUPABASE_STORAGE_PUBLIC_BUCKET', 'uncovr-public-media'),
    'upload_ttl_seconds' => 7200,
    'download_ttl_seconds' => (int) env('MEDIA_DOWNLOAD_TTL_SECONDS', 900),
    'temporary_retention_hours' => (int) env('MEDIA_TEMPORARY_RETENTION_HOURS', 24),
    'superseded_retention_days' => (int) env('MEDIA_SUPERSEDED_RETENTION_DAYS', 7),
    'limits' => [
        'image' => ['bytes' => 25 * 1024 * 1024, 'max_width' => 12000, 'max_height' => 12000, 'max_pixels' => 100_000_000, 'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif']],
        'audio' => ['bytes' => 50 * 1024 * 1024, 'mime_types' => ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/flac']],
        'video' => ['bytes' => 50 * 1024 * 1024, 'mime_types' => ['video/mp4', 'video/webm']],
        'document' => ['bytes' => 10 * 1024 * 1024, 'mime_types' => ['application/pdf']],
    ],
];
