<?php

return [
    'private_bucket' => env('SUPABASE_STORAGE_PRIVATE_BUCKET', 'uncovr-private-media'),
    'public_bucket' => env('SUPABASE_STORAGE_PUBLIC_BUCKET', 'uncovr-public-media'),
    'upload_ttl_seconds' => (int) env('MEDIA_UPLOAD_TTL_SECONDS', 7200),
    'download_ttl_seconds' => (int) env('MEDIA_DOWNLOAD_TTL_SECONDS', 900),
    'batch_download_limit' => (int) env('MEDIA_BATCH_DOWNLOAD_LIMIT', 100),
    'temporary_retention_hours' => (int) env('MEDIA_TEMPORARY_RETENTION_HOURS', 24),
    'superseded_retention_days' => (int) env('MEDIA_SUPERSEDED_RETENTION_DAYS', 7),
    'limits' => [
        'image' => [
            'bytes' => (int) env('MEDIA_IMAGE_MAX_BYTES', 25 * 1024 * 1024),
            'max_width' => (int) env('MEDIA_IMAGE_MAX_WIDTH', 12000),
            'max_height' => (int) env('MEDIA_IMAGE_MAX_HEIGHT', 12000),
            'max_pixels' => (int) env('MEDIA_IMAGE_MAX_PIXELS', 100_000_000),
            'mime_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIA_IMAGE_MIME_TYPES', 'image/jpeg,image/png,image/webp,image/avif'))))),
        ],
        'audio' => ['bytes' => 50 * 1024 * 1024, 'mime_types' => ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/flac']],
        'video' => ['bytes' => 50 * 1024 * 1024, 'mime_types' => ['video/mp4', 'video/webm']],
        'document' => ['bytes' => 10 * 1024 * 1024, 'mime_types' => ['application/pdf']],
    ],
];
