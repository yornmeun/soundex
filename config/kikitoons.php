<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HeyGen API
    |--------------------------------------------------------------------------
    | Verified against https://developers.heygen.com (HeyGen Voice 1):
    |   POST /v3/models/audio/tts        - synchronous text to speech
    |   GET  /v3/models/audio/voices     - list workspace (private) voices
    |   GET  /v3/models/audio/voices/{id}- read one voice
    |   GET  /v3/users/me                - verify the API key
    | The API key may come from the encrypted settings table (preferred, set in
    | the UI) or from HEYGEN_API_KEY in .env as a fallback.
    */
    'heygen' => [
        'base_url' => env('HEYGEN_BASE_URL', 'https://api.heygen.com'),
        'api_key' => env('HEYGEN_API_KEY'),
        'default_voice_id' => env('HEYGEN_DEFAULT_VOICE_ID'),
        'default_voice_name' => env('HEYGEN_DEFAULT_VOICE_NAME', 'kikitoons'),
        'default_model' => env('HEYGEN_MODEL', 'heygen-voice-1'),
        'connect_timeout' => (int) env('HEYGEN_CONNECT_TIMEOUT', 15),
        // /tts holds the connection open until synthesis finishes.
        'request_timeout' => (int) env('HEYGEN_REQUEST_TIMEOUT', 300),
        // Documented hard limit is 10,000 characters per request.
        'max_chars_per_request' => 10000,
        // Documented rate limit: 30 requests per minute per workspace member.
        'requests_per_minute' => (int) env('HEYGEN_REQUESTS_PER_MINUTE', 30),
        // Retry policy for retryable errors (429 / 500 / 502 / 503 / 504 / network).
        'max_attempts' => (int) env('HEYGEN_MAX_ATTEMPTS', 4),
        'backoff_base_seconds' => (int) env('HEYGEN_BACKOFF_BASE_SECONDS', 5),
        'backoff_max_seconds' => (int) env('HEYGEN_BACKOFF_MAX_SECONDS', 120),
        // When paid credits run out (HTTP 402 insufficient_credit), retry instant
        // voices on the no-charge heygen-voice-1-turbo-free model.
        'free_model_fallback' => (bool) env('HEYGEN_FREE_MODEL_FALLBACK', true),
    ],

    // Models documented for POST /v3/models/audio/tts.
    'models' => [
        'heygen-voice-1' => 'HeyGen Voice 1 (best quality, all voices)',
        'heygen-voice-1-turbo' => 'HeyGen Voice 1 Turbo (instant clones only)',
        'heygen-voice-1-turbo-free' => 'HeyGen Voice 1 Turbo Free (no credits used; instant clones only, daily limits)',
    ],

    // ISO 639-1 codes accepted by POST /v3/models/audio/tts (null = auto-detect).
    'languages' => [
        'en' => 'English', 'ar' => 'Arabic', 'be' => 'Belarusian', 'bg' => 'Bulgarian',
        'ca' => 'Catalan', 'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German',
        'el' => 'Greek', 'es' => 'Spanish', 'fa' => 'Persian', 'fi' => 'Finnish',
        'fr' => 'French', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hr' => 'Croatian',
        'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian', 'ja' => 'Japanese',
        'ko' => 'Korean', 'mk' => 'Macedonian', 'ms' => 'Malay', 'nl' => 'Dutch',
        'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian',
        'sk' => 'Slovak', 'sl' => 'Slovenian', 'sr' => 'Serbian', 'sv' => 'Swedish',
        'ta' => 'Tamil', 'th' => 'Thai', 'tl' => 'Tagalog', 'tr' => 'Turkish',
        'uk' => 'Ukrainian', 'vi' => 'Vietnamese', 'zh' => 'Chinese',
    ],

    // MP3 bitrates documented for 44.1 kHz output.
    'mp3_bitrates' => [64000, 96000, 128000, 192000],

    'output_formats' => ['mp3' => 'MP3', 'wav' => 'WAV (PCM 44.1 kHz)'],

    /*
    |--------------------------------------------------------------------------
    | Generation defaults (overridable in General Settings)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        // Smaller than the 10k API cap so a single failed chunk is cheap to retry.
        'chunk_max_chars' => (int) env('KIKITOONS_CHUNK_MAX_CHARS', 3000),
        'silence_between_ms' => 350,
        'normalize_loudness' => true,
        'trim_chunk_silence' => false,
        'mp3_bitrate' => 128000,
        'output_format' => 'mp3',
        'language' => 'en',
        'max_upload_kb' => (int) env('KIKITOONS_MAX_UPLOAD_KB', 2048),
        'words_per_minute' => 150,
    ],

    'ffmpeg' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout' => (int) env('FFMPEG_TIMEOUT', 1800),
    ],

    'storage_disk' => env('KIKITOONS_DISK', 'local'),

    // Unused uploaded SRT files older than this are pruned by the scheduler.
    'prune_uploads_after_days' => 7,
];
