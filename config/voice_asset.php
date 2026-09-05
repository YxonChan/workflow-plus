<?php

declare(strict_types=1);

return [
    'enabled' => filter_var(env('VOICE_ASSET_ENABLED', true), FILTER_VALIDATE_BOOL),
    'max_size_bytes' => max(1, (int) env('VOICE_UPLOAD_MAX_MB', 20)) * 1024 * 1024,
    'min_duration_seconds' => max(1, (int) env('VOICE_UPLOAD_MIN_SECONDS', 5)),
    'max_duration_seconds' => max(1, (int) env('VOICE_UPLOAD_MAX_SECONDS', 30)),
    'allowed_extensions' => array_values(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', (string) env('VOICE_UPLOAD_EXTENSIONS', 'wav,mp3,m4a'))
    ))),
    'allowed_mime_types' => [
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/x-m4a',
        'application/octet-stream',
    ],
    'ffprobe_binary' => trim((string) env('FFPROBE_BINARY', 'ffprobe')) ?: 'ffprobe',
];
